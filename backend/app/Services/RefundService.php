<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Parcel;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Service unique de gestion des remboursements & indemnisations (CDC Chap. 8.5 & 6.4).
 *
 * - Source unique des plafonds : SettingsService ('remboursements.plafonds')
 * - Table dédiée : refunds (aucun crédit wallet livreur/commerce par défaut ; client sans wallet)
 * - Traçabilité complète : activity_logs à chaque étape sans téléphone ni code en clair.
 */
class RefundService
{
    public const LOCAL_ROLES = [
        'city_agent',
        'city_support',
        'city_finance',
        'city_manager',
    ];

    private const FINANCE_PAYOUT_ROLES = [
        'city_finance',
        'national_finance',
        'dg_national',
        'super_admin',
    ];

    private const ELIGIBLE_ROLES_HORS_AGENT = [
        'city_support',
        'city_finance',
        'city_manager',
        'national_support',
        'national_finance',
        'dg_national',
        'super_admin',
    ];

    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * Retourne le plafond maximal (en F CFA) configuré pour un rôle dans settings.
     * null en configuration (super_admin, dg_national) correspond à illimité (PHP_INT_MAX).
     */
    public function plafondPourRole(string $role, ?int $cityId = null): int
    {
        if ($role === 'city_agent') {
            return 0;
        }

        $remboursements = $this->settingsService->get('remboursements', $cityId);
        $plafonds = is_array($remboursements) ? ($remboursements['plafonds'] ?? []) : [];

        if (! is_array($plafonds) || ! array_key_exists($role, $plafonds)) {
            return 0;
        }

        $valeur = $plafonds[$role];

        if ($valeur === null) {
            return PHP_INT_MAX;
        }

        return max(0, (int) round((float) $valeur));
    }

    public function roleRequisPourMontant(int $montant, ?int $cityId = null): string
    {
        foreach (['city_support', 'city_finance', 'city_manager', 'super_admin'] as $candidat) {
            if ($montant <= $this->plafondPourRole($candidat, $cityId)) {
                return $candidat;
            }
        }

        return 'super_admin';
    }

    public function peutPayerRemboursement(User $user): bool
    {
        return in_array($user->role, self::FINANCE_PAYOUT_ROLES, true);
    }

    public function peutDeclarerPerte(User $user): bool
    {
        return in_array($user->role, self::ELIGIBLE_ROLES_HORS_AGENT, true);
    }

    public function estHorsVilleLocale(?User $user, int $resourceCityId): bool
    {
        if (! $user || ! in_array($user->role, self::LOCAL_ROLES, true)) {
            return false;
        }

        return ! $user->city_id || $resourceCityId !== (int) $user->city_id;
    }

    /**
     * @return array{ok: bool, status: int, message: string|null}
     */
    public function verifierHabilitationValidation(?User $user, int $montant, ?int $cityId = null): array
    {
        if (! $user || ! in_array($user->role, self::ELIGIBLE_ROLES_HORS_AGENT, true)) {
            return [
                'ok' => false,
                'status' => 403,
                'message' => 'Action non autorisée pour votre rôle.',
            ];
        }

        $plafond = $this->plafondPourRole((string) $user->role, $cityId);
        if ($plafond === 0 || $montant > $plafond) {
            $roleRequis = $this->roleRequisPourMontant($montant, $cityId);

            return [
                'ok' => false,
                'status' => 403,
                'message' => "Plafond de remboursement insuffisant pour votre rôle ({$plafond} F CFA max). Rôle requis : {$roleRequis}.",
            ];
        }

        return ['ok' => true, 'status' => 200, 'message' => null];
    }

    public function verifierEligibiliteOrder(Order $order, int $montant): ?string
    {
        if (! in_array($order->payment_status, ['paid', 'paid_cash'], true)) {
            return 'Cette commande n\'a pas été payée et ne peut pas être remboursée.';
        }

        $estEnLitige = $order->status === Order::STATUS_DISPUTED || (bool) $order->confirmation_locked;
        $estEligible = in_array($order->status, [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED, Order::STATUS_REFUSED], true) || $estEnLitige;

        if (! $estEligible) {
            return 'Seule une commande livrée, en litige ou annulée après paiement peut être remboursée.';
        }

        $totalPaye = (int) round((float) $order->products_total) + (int) round((float) $order->delivery_fee);
        if ($montant <= 0 || $montant > $totalPaye) {
            return "Le montant demandé ({$montant} F CFA) dépasse le montant payé par le client ({$totalPaye} F CFA).";
        }

        return null;
    }

    public function verifierEligibiliteParcel(Parcel $parcel, int $montant): ?string
    {
        if (! in_array($parcel->payment_status, ['paid', 'paid_cash'], true)) {
            return 'Cette expédition n\'a pas été payée et ne peut pas être remboursée.';
        }

        $estEnLitigeOuPerdu = in_array($parcel->status, [Parcel::STATUS_LOST, Parcel::STATUS_DISPUTED], true)
            || (bool) $parcel->sender_code_locked
            || (bool) $parcel->recipient_code_locked;
        $estEligible = in_array($parcel->status, [Parcel::STATUS_DELIVERED, Parcel::STATUS_CANCELLED], true)
            || $estEnLitigeOuPerdu;

        if (! $estEligible) {
            return 'Seul un colis livré, annulé après paiement, en litige ou déclaré perdu peut être remboursé.';
        }

        $montantPaye = (int) $parcel->shipping_fee
            + ((bool) $parcel->has_insurance ? (int) $parcel->insurance_amount : 0);

        if (! $estEnLitigeOuPerdu) {
            // Colis simplement livré ou annulé (assuré ou non) : plafond limité au montant payé
            $plafondColis = $montantPaye;
        } elseif ((bool) $parcel->has_insurance) {
            // Colis assuré déclaré perdu ou en litige : valeur déclarée (CDC chap. 5.2 / D-REMB-1)
            $plafondColis = (int) $parcel->declared_value;
        } else {
            // Colis sans assurance déclaré perdu ou en litige : 2 × frais d'expédition (CDC chap. 6 / D-REMB-1)
            $plafondColis = 2 * (int) $parcel->shipping_fee;
        }

        if ($montant <= 0 || $montant > $plafondColis) {
            return "Le montant demandé ({$montant} F CFA) dépasse le plafond autorisé ({$plafondColis} F CFA).";
        }

        return null;
    }

    /**
     * Crée un remboursement au statut 'requested' (workflow 3 étapes : requested -> approved -> paid).
     *
     * @return array{ok: bool, status: int, refund?: Refund, message?: string}
     */
    public function demanderRemboursement(
        ?Order $order,
        ?Parcel $parcel,
        int $montant,
        string $raison,
        User $demandeur,
    ): array {
        $cityId = $order ? (int) $order->city_id : (int) $parcel?->city_id;
        if ($this->estHorsVilleLocale($demandeur, $cityId)) {
            return ['ok' => false, 'status' => 404, 'message' => $order ? 'Commande introuvable.' : 'Expédition introuvable.'];
        }

        if (! $this->peutDeclarerPerte($demandeur)) {
            return ['ok' => false, 'status' => 403, 'message' => 'Action non autorisée pour votre rôle.'];
        }

        return $this->creerEnBase(
            order: $order,
            parcel: $parcel,
            montant: $montant,
            raison: $raison,
            acteur: $demandeur,
            approuverImmediatement: false,
        );
    }

    /**
     * Crée et approuve directement un remboursement si le rôle dispose du plafond nécessaire.
     *
     * @return array{ok: bool, status: int, refund?: Refund, message?: string}
     */
    public function creerEtApprouverDirect(
        ?Order $order,
        ?Parcel $parcel,
        int $montant,
        string $raison,
        User $admin,
    ): array {
        $cityId = $order ? (int) $order->city_id : (int) $parcel?->city_id;
        if ($this->estHorsVilleLocale($admin, $cityId)) {
            return ['ok' => false, 'status' => 404, 'message' => $order ? 'Commande introuvable.' : 'Expédition introuvable.'];
        }

        $habilitation = $this->verifierHabilitationValidation($admin, $montant, $cityId);
        if (! $habilitation['ok']) {
            return [
                'ok' => false,
                'status' => $habilitation['status'],
                'message' => $habilitation['message'] ?? 'Plafond insuffisant.',
            ];
        }

        return $this->creerEnBase(
            order: $order,
            parcel: $parcel,
            montant: $montant,
            raison: $raison,
            acteur: $admin,
            approuverImmediatement: true,
        );
    }

    /**
     * @return array{ok: bool, status: int, refund?: Refund, message?: string}
     */
    public function approuverRemboursement(Refund $refund, User $admin): array
    {
        if ($this->estHorsVilleLocale($admin, (int) $refund->city_id)) {
            return ['ok' => false, 'status' => 404, 'message' => 'Remboursement introuvable.'];
        }

        $habilitation = $this->verifierHabilitationValidation($admin, (int) $refund->amount, (int) $refund->city_id);
        if (! $habilitation['ok']) {
            return [
                'ok' => false,
                'status' => $habilitation['status'],
                'message' => $habilitation['message'] ?? 'Plafond insuffisant.',
            ];
        }

        return DB::transaction(function () use ($refund, $admin): array {
            /** @var Refund $lockedRefund */
            $lockedRefund = Refund::lockForUpdate()->findOrFail($refund->id);

            if ($lockedRefund->status !== Refund::STATUS_REQUESTED) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'Seule une demande en attente peut être approuvée.',
                ];
            }

            $ancienStatut = $lockedRefund->status;
            $lockedRefund->update([
                'status' => Refund::STATUS_APPROVED,
                'approved_by' => $admin->id,
                'approved_at' => now(),
            ]);

            $this->enregistrerTrace(
                userId: $admin->id,
                action: 'refund_approved',
                refund: $lockedRefund,
                oldStatus: $ancienStatut,
                newStatus: Refund::STATUS_APPROVED,
            );

            return ['ok' => true, 'status' => 200, 'refund' => $lockedRefund->fresh()];
        });
    }

    /**
     * @return array{ok: bool, status: int, refund?: Refund, message?: string}
     */
    public function marquerPayeRemboursement(Refund $refund, User $finance, string $paymentReference): array
    {
        if ($this->estHorsVilleLocale($finance, (int) $refund->city_id)) {
            return ['ok' => false, 'status' => 404, 'message' => 'Remboursement introuvable.'];
        }

        if (! $this->peutPayerRemboursement($finance)) {
            return ['ok' => false, 'status' => 403, 'message' => 'Action réservée à un rôle Finance habilité.'];
        }

        $ref = trim($paymentReference);
        if ($ref === '') {
            return ['ok' => false, 'status' => 422, 'message' => 'La référence de paiement Mobile Money est obligatoire.'];
        }

        return DB::transaction(function () use ($refund, $finance, $ref): array {
            /** @var Refund $lockedRefund */
            $lockedRefund = Refund::lockForUpdate()->findOrFail($refund->id);

            if ($lockedRefund->status !== Refund::STATUS_APPROVED) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'Le remboursement doit être approuvé avant d\'être marqué payé.',
                ];
            }

            $ancienStatut = $lockedRefund->status;
            $lockedRefund->update([
                'status' => Refund::STATUS_PAID,
                'paid_by' => $finance->id,
                'paid_at' => now(),
                'payment_reference' => $ref,
            ]);

            if ($lockedRefund->parcel_id !== null) {
                Parcel::whereKey($lockedRefund->parcel_id)->update(['status' => 'refunded']);
            }

            $this->enregistrerTrace(
                userId: $finance->id,
                action: 'refund_paid',
                refund: $lockedRefund,
                oldStatus: $ancienStatut,
                newStatus: Refund::STATUS_PAID,
                extra: ['payment_reference' => $ref],
            );

            return ['ok' => true, 'status' => 200, 'refund' => $lockedRefund->fresh()];
        });
    }

    /**
     * @return array{ok: bool, status: int, refund?: Refund, message?: string}
     */
    public function rejeterRemboursement(Refund $refund, User $admin, ?string $motifRejet = null): array
    {
        if ($this->estHorsVilleLocale($admin, (int) $refund->city_id)) {
            return ['ok' => false, 'status' => 404, 'message' => 'Remboursement introuvable.'];
        }

        if (! $this->peutDeclarerPerte($admin)) {
            return ['ok' => false, 'status' => 403, 'message' => 'Action non autorisée pour votre rôle.'];
        }

        return DB::transaction(function () use ($refund, $admin, $motifRejet): array {
            /** @var Refund $lockedRefund */
            $lockedRefund = Refund::lockForUpdate()->findOrFail($refund->id);

            if (! in_array($lockedRefund->status, [Refund::STATUS_REQUESTED, Refund::STATUS_APPROVED], true)) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'Ce remboursement ne peut plus être rejeté.',
                ];
            }

            $ancienStatut = $lockedRefund->status;
            $lockedRefund->update([
                'status' => Refund::STATUS_REJECTED,
                'reason' => $motifRejet ? "{$lockedRefund->reason} — Rejet : {$motifRejet}" : $lockedRefund->reason,
            ]);

            $this->enregistrerTrace(
                userId: $admin->id,
                action: 'refund_rejected',
                refund: $lockedRefund,
                oldStatus: $ancienStatut,
                newStatus: Refund::STATUS_REJECTED,
            );

            return ['ok' => true, 'status' => 200, 'refund' => $lockedRefund->fresh()];
        });
    }

    /**
     * Déclare explicitement un colis GoColis perdu (transition admin traçable avant indemnisation).
     *
     * @return array{ok: bool, status: int, parcel?: Parcel, message?: string}
     */
    public function declarerColisPerdu(Parcel $parcel, User $admin, string $raison = ''): array
    {
        if ($this->estHorsVilleLocale($admin, (int) $parcel->city_id)) {
            return ['ok' => false, 'status' => 404, 'message' => 'Expédition introuvable.'];
        }

        if (! $this->peutDeclarerPerte($admin)) {
            return ['ok' => false, 'status' => 403, 'message' => 'Action non autorisée pour votre rôle.'];
        }

        return DB::transaction(function () use ($parcel, $admin, $raison): array {
            /** @var Parcel $lockedParcel */
            $lockedParcel = Parcel::lockForUpdate()->findOrFail($parcel->id);

            $statutsEligibles = [
                Parcel::STATUS_ACCEPTED,
                Parcel::STATUS_AT_SENDER,
                Parcel::STATUS_PICKED_UP,
                Parcel::STATUS_IN_TRANSIT,
                Parcel::STATUS_AT_RECIPIENT,
            ];

            if (! in_array($lockedParcel->status, $statutsEligibles, true)) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'message' => 'Seul un colis en cours d\'acheminement peut être déclaré perdu.',
                ];
            }

            $ancienStatut = $lockedParcel->status;
            $lockedParcel->update(['status' => Parcel::STATUS_LOST]);

            $this->activityLogService->log(
                userId: $admin->id,
                action: 'parcel_declared_lost',
                entityType: 'parcel',
                entityId: $lockedParcel->id,
                properties: [
                    'parcel_id' => $lockedParcel->id,
                    'old_status' => $ancienStatut,
                    'new_status' => Parcel::STATUS_LOST,
                    'reason' => $raison !== '' ? $raison : 'Colis déclaré perdu par l\'administration',
                ],
                severity: 'critical',
            );

            return ['ok' => true, 'status' => 200, 'parcel' => $lockedParcel->fresh()];
        });
    }

    /**
     * @return array{ok: bool, status: int, refund?: Refund, message?: string}
     */
    private function creerEnBase(
        ?Order $order,
        ?Parcel $parcel,
        int $montant,
        string $raison,
        User $acteur,
        bool $approuverImmediatement,
    ): array {
        try {
            return DB::transaction(function () use ($order, $parcel, $montant, $raison, $acteur, $approuverImmediatement): array {
                if ($order !== null) {
                    /** @var Order $lockedOrder */
                    $lockedOrder = Order::lockForUpdate()->findOrFail($order->id);

                    if ($erreur = $this->verifierEligibiliteOrder($lockedOrder, $montant)) {
                        return ['ok' => false, 'status' => 422, 'message' => $erreur];
                    }

                    if (Refund::where('order_id', $lockedOrder->id)->exists()) {
                        return [
                            'ok' => false,
                            'status' => 409,
                            'message' => 'Un remboursement existe déjà pour cette commande.',
                        ];
                    }

                    $statutInitial = $approuverImmediatement ? Refund::STATUS_APPROVED : Refund::STATUS_REQUESTED;
                    $refund = Refund::create([
                        'city_id' => $lockedOrder->city_id,
                        'order_id' => $lockedOrder->id,
                        'parcel_id' => null,
                        'client_id' => $lockedOrder->client_id,
                        'amount' => $montant,
                        'reason' => $raison,
                        'status' => $statutInitial,
                        'cost_bearer' => Refund::COST_PLATFORM,
                        'requested_by' => $acteur->id,
                        'approved_by' => $approuverImmediatement ? $acteur->id : null,
                        'approved_at' => $approuverImmediatement ? now() : null,
                    ]);

                    $this->enregistrerTrace(
                        userId: $acteur->id,
                        action: 'refund_requested',
                        refund: $refund,
                        oldStatus: null,
                        newStatus: Refund::STATUS_REQUESTED,
                    );

                    if ($approuverImmediatement) {
                        $this->enregistrerTrace(
                            userId: $acteur->id,
                            action: 'refund_approved',
                            refund: $refund,
                            oldStatus: Refund::STATUS_REQUESTED,
                            newStatus: Refund::STATUS_APPROVED,
                        );
                    }

                    return ['ok' => true, 'status' => 200, 'refund' => $refund->fresh()];
                }

                if ($parcel !== null) {
                    /** @var Parcel $lockedParcel */
                    $lockedParcel = Parcel::lockForUpdate()->findOrFail($parcel->id);

                    if ($erreur = $this->verifierEligibiliteParcel($lockedParcel, $montant)) {
                        return ['ok' => false, 'status' => 422, 'message' => $erreur];
                    }

                    if (Refund::where('parcel_id', $lockedParcel->id)->exists()) {
                        return [
                            'ok' => false,
                            'status' => 409,
                            'message' => 'Un remboursement existe déjà pour cette expédition.',
                        ];
                    }

                    $statutInitial = $approuverImmediatement ? Refund::STATUS_APPROVED : Refund::STATUS_REQUESTED;
                    $refund = Refund::create([
                        'city_id' => $lockedParcel->city_id,
                        'order_id' => null,
                        'parcel_id' => $lockedParcel->id,
                        'client_id' => $lockedParcel->sender_id,
                        'amount' => $montant,
                        'reason' => $raison,
                        'status' => $statutInitial,
                        'cost_bearer' => Refund::COST_PLATFORM,
                        'requested_by' => $acteur->id,
                        'approved_by' => $approuverImmediatement ? $acteur->id : null,
                        'approved_at' => $approuverImmediatement ? now() : null,
                    ]);

                    if ($approuverImmediatement) {
                        $lockedParcel->update(['status' => 'refunded']);
                    }

                    $this->enregistrerTrace(
                        userId: $acteur->id,
                        action: 'refund_requested',
                        refund: $refund,
                        oldStatus: null,
                        newStatus: Refund::STATUS_REQUESTED,
                    );

                    if ($approuverImmediatement) {
                        $this->enregistrerTrace(
                            userId: $acteur->id,
                            action: 'refund_approved',
                            refund: $refund,
                            oldStatus: Refund::STATUS_REQUESTED,
                            newStatus: Refund::STATUS_APPROVED,
                        );
                    }

                    return ['ok' => true, 'status' => 200, 'refund' => $refund->fresh()];
                }

                return ['ok' => false, 'status' => 422, 'message' => 'Cible de remboursement manquante.'];
            });
        } catch (UniqueConstraintViolationException) {
            return [
                'ok' => false,
                'status' => 409,
                'message' => 'Un remboursement existe déjà pour cette opération.',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function enregistrerTrace(
        int $userId,
        string $action,
        Refund $refund,
        ?string $oldStatus,
        string $newStatus,
        array $extra = [],
    ): void {
        $this->activityLogService->log(
            userId: $userId,
            action: $action,
            entityType: 'refund',
            entityId: $refund->id,
            properties: array_merge([
                'refund_id' => $refund->id,
                'order_id' => $refund->order_id,
                'parcel_id' => $refund->parcel_id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'amount' => (int) $refund->amount,
                'cost_bearer' => $refund->cost_bearer,
            ], $extra),
            severity: 'critical',
        );
    }
}
