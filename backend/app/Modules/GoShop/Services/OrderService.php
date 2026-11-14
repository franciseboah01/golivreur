<?php

namespace App\Modules\GoShop\Services;

use App\Jobs\AlerterAdminCodeBloque;
use App\Jobs\AnnulerCommandeTimeout;
use App\Models\City;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Service central GoShop — gère tout le cycle de vie d'une commande :
 * création → attribution livreur → livraison → paiement wallet.
 */
class OrderService
{
    public function __construct(
        private readonly AssignmentService $assignmentService,
        private readonly CommissionService $commissionService,
        private readonly WalletService $walletService,
        private readonly NotificationService $notificationService,
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * Crée une commande GoShop et déclenche la notification au commerce.
     */
    public function creerCommande(User $client, array $donnees): Order
    {
        return DB::transaction(function () use ($client, $donnees) {
            $merchant = Merchant::findOrFail($donnees['merchant_id']);

            // Calculer les montants selon la ville du commerce
            $cityId = $merchant->city_id ?? $client->city_id ?? 1;
            $city = City::findOrFail($cityId);

            if ($city->emergency_mode_active) {
                $msg = $city->emergency_mode_message ?: 'Le service de livraison est temporairement interrompu dans cette zone (Mode Urgence).';

                throw new HttpException(503, $msg);
            }

            // Vérifier que le commerce est ouvert (statut manuel + plage horaires)
            if (! $merchant->is_open || ! $merchant->is_active) {
                throw ValidationException::withMessages([
                    'merchant_id' => ['Ce commerce est actuellement fermé.'],
                ]);
            }

            if (! $merchant->estDansHorairesOuverture()) {
                throw ValidationException::withMessages([
                    'merchant_id' => ['Ce commerce est actuellement en dehors de ses horaires d\'ouverture.'],
                ]);
            }

            // Vérification coordonnées de livraison dans la ville
            $deliveryLat = (float) $donnees['delivery_lat'];
            $deliveryLng = (float) $donnees['delivery_lng'];
            if (! $city->estDansLaVille($deliveryLat, $deliveryLng)) {
                throw ValidationException::withMessages([
                    'delivery_lat' => ['Les coordonnées de livraison sont situées en dehors du périmètre de la ville.'],
                ]);
            }

            // Calcul distance serveur Haversine entre merchant (pickup) et delivery (coef routier approché CDC = 1.0)
            $haversineDistance = City::distanceHaversine(
                (float) $merchant->lat,
                (float) $merchant->lng,
                $deliveryLat,
                $deliveryLng
            );
            $distanceKm = max(0.1, round($haversineDistance * 1.0, 2));

            [$produitsTotal, $produitsResolus] = $this->resoudreProduitsEtTotal($merchant, $donnees['items']);
            $fraisLivraison = $this->commissionService->calculerFraisLivraison(
                distance: $distanceKm,
                city: $city,
            );
            $commissions = $this->commissionService->calculerCommissions(
                produitsTotal: $produitsTotal,
                fraisLivraison: $fraisLivraison,
                merchant: $merchant,
            );

            // Créer la commande
            $order = Order::create([
                'client_id' => $client->id,
                'merchant_id' => $merchant->id,
                'city_id' => $city->id,
                'status' => Order::STATUS_PENDING,
                'products_total' => $produitsTotal,
                'delivery_fee' => $fraisLivraison,
                'platform_commission' => $commissions['plateforme'],
                'merchant_amount' => $commissions['merchant'],
                'courier_amount' => $commissions['livreur'],
                'rain_mode_applied' => $city->rain_mode_active,
                'peak_hours_applied' => $this->commissionService->estHeureDePointe($city->id),
                'payment_method' => $donnees['payment_method'],
                'payment_status' => $donnees['payment_method'] === 'cash'
                                            ? 'pending_cash' : 'pending',
                'confirmation_code' => Hash::make((string) random_int(1000, 9999)),
                'delivery_address' => $donnees['delivery_address'],
                'delivery_lat' => $deliveryLat,
                'delivery_lng' => $deliveryLng,
                'pickup_lat' => $merchant->lat,
                'pickup_lng' => $merchant->lng,
                'distance_km' => $distanceKm,
                'special_instructions' => $donnees['special_instructions'] ?? null,
            ]);

            // Créer les lignes de commande avec snapshot des prix
            foreach ($donnees['items'] as $item) {
                $product = $produitsResolus[(int) $item['product_id']];
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->price,
                    'product_name_snapshot' => $product->name,
                ]);
            }

            // Notifier le commerce (Push FCM via queue default)
            $this->notificationService->notifierNouvelleCommande($merchant, $order);

            // Log d'audit
            $this->activityLogService->log(
                userId: $client->id,
                action: 'order_created',
                entityType: 'order',
                entityId: $order->id,
                properties: ['total' => $produitsTotal + $fraisLivraison],
            );

            // Timeout 45 secondes : planifier l'annulation automatique
            AnnulerCommandeTimeout::dispatch($order->id)
                ->delay(now()->addSeconds(45))
                ->onQueue('default');

            return $order->load('items.product', 'merchant');
        });
    }

    /**
     * Le commerce accepte la commande — déclenche la recherche de livreur.
     */
    public function accepterCommande(Order $order, Merchant $merchant): Order
    {
        return DB::transaction(function () use ($order, $merchant) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::lockForUpdate()->findOrFail($order->id);

            if ((int) $lockedOrder->merchant_id !== (int) $merchant->id) {
                throw ValidationException::withMessages([
                    'merchant_id' => ['Cette commande ne concerne pas votre commerce.'],
                ]);
            }

            if (! $lockedOrder->peutTransitionnerVers(Order::STATUS_ACCEPTED)) {
                throw ValidationException::withMessages([
                    'status' => ['Cette commande ne peut plus être acceptée.'],
                ]);
            }

            $lockedOrder->update([
                'status' => Order::STATUS_ACCEPTED,
                'accepted_at' => now(),
            ]);

            // Notifier le client
            $this->notificationService->notifierCommandeAcceptee($lockedOrder);

            // Chercher le livreur le plus proche
            $this->assignmentService->attribuerLivreurGoShop($lockedOrder);

            return $lockedOrder->fresh();
        });
    }

    /**
     * Le commerce refuse la commande (statut v5).
     */
    public function refuserCommande(Order $order, string $raison = ''): Order
    {
        return DB::transaction(function () use ($order, $raison) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::lockForUpdate()->findOrFail($order->id);

            if (! $lockedOrder->peutTransitionnerVers(Order::STATUS_REFUSED)) {
                throw ValidationException::withMessages([
                    'status' => ['Cette commande ne peut plus être refusée.'],
                ]);
            }

            $lockedOrder->update([
                'status' => Order::STATUS_REFUSED,
                'cancelled_at' => now(),
                'cancel_reason' => $raison ?: 'Refusé par le commerce.',
            ]);

            if ($lockedOrder->payment_status === 'paid' && ! Refund::where('order_id', $lockedOrder->id)->exists()) {
                $montantRemboursement = (int) round((float) $lockedOrder->products_total + (float) $lockedOrder->delivery_fee);
                if ($montantRemboursement > 0) {
                    $refund = Refund::create([
                        'city_id' => $lockedOrder->city_id,
                        'order_id' => $lockedOrder->id,
                        'parcel_id' => null,
                        'client_id' => $lockedOrder->client_id,
                        'amount' => $montantRemboursement,
                        'reason' => 'Refus commerce d\'une commande déjà payée : '.($raison ?: 'Refusé par le commerce.'),
                        'status' => Refund::STATUS_REQUESTED,
                        'cost_bearer' => Refund::COST_PLATFORM,
                        'requested_by' => auth()->id() ?? $lockedOrder->merchant?->user_id,
                    ]);

                    $this->activityLogService->log(
                        userId: auth()->id() ?? $lockedOrder->merchant?->user_id,
                        action: 'refund_requested_auto_merchant_refusal',
                        entityType: 'refund',
                        entityId: $refund->id,
                        properties: [
                            'order_id' => $lockedOrder->id,
                            'amount' => $montantRemboursement,
                            'cost_bearer' => Refund::COST_PLATFORM,
                            'status' => Refund::STATUS_REQUESTED,
                        ],
                        severity: 'warning',
                    );
                }
            }

            // Notifier le client immédiatement
            $this->notificationService->notifierCommandeRefusee($lockedOrder);

            return $lockedOrder->fresh();
        });
    }

    /**
     * Le livreur saisit le code de confirmation — valide la livraison.
     */
    public function validerCodeConfirmation(Order $order, string $codeSaisi, ?User $admin = null): Order
    {
        $erreurMessage = DB::transaction(function () use ($order, $codeSaisi, $admin): ?string {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::lockForUpdate()->findOrFail($order->id);

            // (a) Statut livrable strict (CDC Annexe A) vérifié AVANT toute tentative
            if ($lockedOrder->status === Order::STATUS_DELIVERED) {
                throw ValidationException::withMessages([
                    'code' => ['Cette commande a déjà été livrée et validée.'],
                ]);
            }

            if (! in_array($lockedOrder->status, [
                Order::STATUS_PICKED_UP,
                Order::STATUS_IN_TRANSIT,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Cette commande n\'est pas dans un statut livrable.'],
                ]);
            }

            // (b) Propriétaire livreur / acteur habilité vérifié AVANT toute tentative
            if ($lockedOrder->courier_id === null) {
                throw ValidationException::withMessages([
                    'courier' => ['Aucun livreur n\'est assigné à cette commande.'],
                ]);
            }

            /** @var User|null $actor */
            $actor = $admin ?? auth()->user();
            if ($actor !== null) {
                $isNationalOverride = in_array($actor->role, ['super_admin', 'dg_national', 'national_support'], true);
                $isLocalOverride = in_array($actor->role, ['city_support', 'city_manager'], true)
                    && $actor->city_id
                    && (int) $lockedOrder->city_id === (int) $actor->city_id;

                if (! $isNationalOverride && ! $isLocalOverride) {
                    $actorCourier = $actor->courier;
                    if (! $actorCourier
                        || (int) $lockedOrder->courier_id !== (int) $actorCourier->id
                        || ! $actorCourier->city_id
                        || (int) $lockedOrder->city_id !== (int) $actorCourier->city_id) {
                        throw ValidationException::withMessages([
                            'courier' => ['Accès refusé : vous n\'êtes pas le livreur assigné à cette commande.'],
                        ]);
                    }
                }
            }

            // (c) Verrouillage vérifié AVANT toute tentative (aucun incrément ni log du code)
            if ($lockedOrder->confirmation_locked) {
                throw ValidationException::withMessages([
                    'code' => ['Cette livraison est bloquée. Le support a été contacté.'],
                ]);
            }

            // Vérifier le code (hashé bcrypt)
            if (! Hash::check($codeSaisi, $lockedOrder->confirmation_code)) {
                $lockedOrder->increment('confirmation_attempts');
                $lockedOrder->refresh();

                if ($lockedOrder->confirmation_attempts >= 3) {
                    $lockedOrder->update(['confirmation_locked' => true]);
                    AlerterAdminCodeBloque::dispatch($lockedOrder, 'goshop')
                        ->onQueue('high');

                    return 'Code incorrect. Livraison bloquée — le support a été alerté.';
                }

                $restants = max(0, 3 - (int) $lockedOrder->confirmation_attempts);

                return "Code incorrect. {$restants} tentative(s) restante(s).";
            }

            $estCash = $lockedOrder->payment_method === 'cash';

            $lockedOrder->update([
                'status' => Order::STATUS_DELIVERED,
                'payment_status' => $estCash ? 'paid_cash' : $lockedOrder->payment_status,
                'delivered_at' => now(),
            ]);

            if ($estCash) {
                // Encaissement cash : le livreur garde sa part (640 F) et reverse (total - part livreur)
                $this->walletService->debiterReversementCashOrder($lockedOrder);
                $this->walletService->crediterMerchant($lockedOrder);
            } else {
                // Paiement électronique : crédit livreur + commerce inchangé
                $this->walletService->crediterLivreur($lockedOrder);
                $this->walletService->crediterMerchant($lockedOrder);
            }

            // Notifier le client
            $this->notificationService->notifierCommandeLivree($lockedOrder);

            $acteurId = $actor->id ?? $lockedOrder->courier?->user_id;

            if ($estCash) {
                $this->activityLogService->log(
                    userId: $acteurId,
                    action: 'cash_remittance_recorded',
                    entityType: 'order',
                    entityId: $lockedOrder->id,
                    properties: [
                        'order_id' => $lockedOrder->id,
                        'cash_collected' => (int) round((float) $lockedOrder->products_total) + (int) round((float) $lockedOrder->delivery_fee),
                        'courier_kept' => (int) round((float) $lockedOrder->courier_amount),
                        'remitted_amount' => $this->walletService->calculerMontantAReverserOrder($lockedOrder),
                        'merchant_amount' => (int) round((float) $lockedOrder->merchant_amount),
                        'platform_commission' => (int) round((float) $lockedOrder->platform_commission),
                    ],
                );
            }

            $this->activityLogService->log(
                userId: $acteurId,
                action: 'order_delivered',
                entityType: 'order',
                entityId: $lockedOrder->id,
                properties: [
                    'courier_amount' => $lockedOrder->courier_amount,
                    'merchant_amount' => $lockedOrder->merchant_amount,
                    'payment_method' => $lockedOrder->payment_method,
                ],
            );

            return null;
        });

        if ($erreurMessage !== null) {
            throw ValidationException::withMessages([
                'code' => [$erreurMessage],
            ]);
        }

        return $order->fresh(['client', 'merchant', 'courier']);
    }

    /**
     * Le commerce signale que la commande est prête à être ramassée.
     */
    public function signalerCommandePrete(Order $order): Order
    {
        return DB::transaction(function () use ($order) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::lockForUpdate()->findOrFail($order->id);

            if (! $lockedOrder->peutTransitionnerVers(Order::STATUS_READY)) {
                throw ValidationException::withMessages([
                    'status' => ['La commande doit être acceptée avant d\'être marquée prête.'],
                ]);
            }

            $lockedOrder->update([
                'status' => Order::STATUS_READY,
                'ready_at' => now(),
            ]);

            // Notifier le livreur assigné
            if ($lockedOrder->courier) {
                $this->notificationService->notifierCommandePrete($lockedOrder);
            }

            return $lockedOrder->fresh();
        });
    }

    // ─── Méthodes privées ─────────────────────────────────────────────────────

    /**
     * Résout les produits du commerce et calcule le total côté serveur.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: float, 1: array<int, Product>}
     */
    private function resoudreProduitsEtTotal(Merchant $merchant, array $items): array
    {
        $productIds = array_column($items, 'product_id');
        /** @var array<int, Product> $resolved */
        $resolved = Product::where('merchant_id', $merchant->id)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id')
            ->all();

        $total = 0.0;
        foreach ($items as $item) {
            $productId = (int) $item['product_id'];
            $product = $resolved[$productId] ?? null;
            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => ['Un ou plusieurs produits n\'appartiennent pas à ce commerce.'],
                ]);
            }

            if (! $product->is_available) {
                throw ValidationException::withMessages([
                    'items' => ["Le produit « {$product->name} » n'est actuellement pas disponible."],
                ]);
            }

            $total += (float) $product->price * (int) $item['quantity'];
        }

        return [$total, $resolved];
    }
}
