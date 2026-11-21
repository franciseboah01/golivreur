<?php

namespace App\Modules\GoShop\Services;

use App\Jobs\AlerterAdminCodeBloque;
use App\Jobs\AnnulerCommandePaiementTimeout;
use App\Jobs\AnnulerCommandeTimeout;
use App\Models\City;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\AsyncJobDispatcher;
use App\Services\ConfirmationCodeService;
use App\Services\DeliverySettlementService;
use App\Services\NotificationService;
use App\Services\SettingsService;
use App\Services\WalletService;
use Illuminate\Http\Exceptions\HttpResponseException;
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
    private readonly SettingsService $settingsService;

    private readonly ConfirmationCodeService $confirmationCodeService;

    public function __construct(
        private readonly AssignmentService $assignmentService,
        private readonly CommissionService $commissionService,
        private readonly WalletService $walletService,
        private readonly NotificationService $notificationService,
        private readonly ActivityLogService $activityLogService,
        ?SettingsService $settingsService = null,
        ?ConfirmationCodeService $confirmationCodeService = null,
    ) {
        $this->settingsService = $settingsService ?? app(SettingsService::class);
        $this->confirmationCodeService = $confirmationCodeService ?? app(ConfirmationCodeService::class);
    }

    /**
     * Crée une commande GoShop et déclenche la notification au commerce.
     */
    public function creerCommande(User $client, array $donnees): Order
    {
        return DB::transaction(function () use ($client, $donnees) {
            $merchant = Merchant::findOrFail($donnees['merchant_id']);

            // Sécurité anti-auto-achat : un commerçant (même multi-rôle client) ne peut jamais acheter ses propres produits
            $productIds = array_values(array_unique(array_map(
                static fn (array $item): int => (int) ($item['product_id'] ?? 0),
                $donnees['items'] ?? [],
            )));

            $ownsAnyProduct = ! empty($productIds) && Product::whereIn('id', $productIds)
                ->whereHas('merchant', fn ($q) => $q->where('user_id', $client->id))
                ->exists();

            if ((int) $merchant->user_id === (int) $client->id || $ownsAnyProduct) {
                $this->activityLogService->log(
                    userId: $client->id,
                    action: 'self_purchase_blocked',
                    entityType: 'merchant',
                    entityId: $merchant->id,
                    properties: ['merchant_id' => $merchant->id, 'product_ids' => $productIds],
                    severity: 'warning',
                );

                throw new HttpException(403, 'Achat interdit : un commerçant ne peut pas commander ses propres produits.');
            }

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
            $hasRealMerchantGps = $merchant->lat !== null
                && $merchant->lng !== null
                && ! (abs((float) $merchant->lat) < 0.0001 && abs((float) $merchant->lng) < 0.0001);
            $pickupLat = $hasRealMerchantGps
                ? (float) $merchant->lat
                : (float) ($city->center_lat ?? $deliveryLat);
            $pickupLng = $hasRealMerchantGps
                ? (float) $merchant->lng
                : (float) ($city->center_lng ?? $deliveryLng);

            $haversineDistance = City::distanceHaversine(
                $pickupLat,
                $pickupLng,
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

            $codePaire = $this->confirmationCodeService->genererPaire();

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
                'confirmation_code' => $codePaire['hash'],
                'confirmation_code_enc' => $codePaire['enc'],
                'delivery_address' => $donnees['delivery_address'],
                'delivery_lat' => $deliveryLat,
                'delivery_lng' => $deliveryLng,
                'pickup_lat' => $pickupLat,
                'pickup_lng' => $pickupLng,
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

            $estCash = $donnees['payment_method'] === 'cash';

            // Pour une commande cash, notifier le commerce immédiatement et démarrer le timeout d'acceptation.
            // Pour une commande en ligne, attendre payment_status='paid' (webhook) avant de notifier le commerce.
            if ($estCash) {
                $this->notificationService->notifierNouvelleCommande($merchant, $order);
            }

            // Log d'audit
            $this->activityLogService->log(
                userId: $client->id,
                action: 'order_created',
                entityType: 'order',
                entityId: $order->id,
                properties: ['total' => $produitsTotal + $fraisLivraison],
            );

            $orderId = $order->id;
            if ($estCash) {
                $delaiAcceptationCommerce = $this->settingsService->getDelaiRegle('acceptation_commerce_secondes', $city->id, 45);
                AsyncJobDispatcher::dispatchAfterCommit(
                    static fn () => AnnulerCommandeTimeout::dispatch($orderId)
                        ->delay(now()->addSeconds($delaiAcceptationCommerce))
                        ->onQueue('default'),
                    operation: 'annuler_commande_timeout',
                    contexte: ['order_id' => $orderId],
                );
            } else {
                $delaiPaiementMinutes = $this->settingsService->getDelaiRegle('paiement_attente_minutes', $city->id, 10);
                AsyncJobDispatcher::dispatchAfterCommit(
                    static fn () => AnnulerCommandePaiementTimeout::dispatch($orderId)
                        ->delay(now()->addMinutes($delaiPaiementMinutes))
                        ->onQueue('default'),
                    operation: 'annuler_commande_paiement_timeout',
                    contexte: ['order_id' => $orderId],
                );
            }

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

            $this->restaurerStockCommande($lockedOrder);

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

                $maxErreurs = $this->settingsService->getDelaiRegle('erreurs_code_avant_blocage', (int) $lockedOrder->city_id, 3);

                if ($lockedOrder->confirmation_attempts >= $maxErreurs) {
                    $lockedOrder->update(['confirmation_locked' => true]);
                    AsyncJobDispatcher::dispatchAfterCommit(
                        static fn () => AlerterAdminCodeBloque::dispatch($lockedOrder, 'goshop')
                            ->onQueue('high'),
                        operation: 'alerter_admin_code_bloque_goshop',
                        contexte: ['order_id' => $lockedOrder->id],
                    );

                    return 'Code incorrect. Livraison bloquée — le support a été alerté.';
                }

                $restants = max(0, $maxErreurs - (int) $lockedOrder->confirmation_attempts);

                return "Code incorrect. {$restants} tentative(s) restante(s).";
            }

            $estCash = $lockedOrder->payment_method === 'cash';

            $lockedOrder->update([
                'status' => Order::STATUS_DELIVERED,
                'payment_status' => $estCash ? 'paid_cash' : $lockedOrder->payment_status,
                'confirmation_attempts' => 0,
                'delivered_at' => now(),
            ]);

            // Règlement financier unique et idempotent (Point 4a)
            app(DeliverySettlementService::class)->reglerLivraison($lockedOrder);

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

    /**
     * Régénère le code de confirmation (ex: anciennes lignes sans chiffrement) avec trace admin.
     */
    public function regenererCodeConfirmation(Order $order, User $admin): Order
    {
        return DB::transaction(function () use ($order, $admin): Order {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::lockForUpdate()->findOrFail($order->id);

            if (in_array($lockedOrder->status, [
                Order::STATUS_DELIVERED,
                Order::STATUS_CANCELLED,
                Order::STATUS_REFUSED,
                Order::STATUS_DISPUTED,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Impossible de régénérer le code d\'une commande clôturée.'],
                ]);
            }

            $paire = $this->confirmationCodeService->genererPaire();

            $lockedOrder->update([
                'confirmation_code' => $paire['hash'],
                'confirmation_code_enc' => $paire['enc'],
                'confirmation_attempts' => 0,
                'confirmation_locked' => false,
                'confirmation_unlocked_by' => $admin->id,
            ]);

            $this->activityLogService->log(
                userId: $admin->id,
                action: 'order_confirmation_code_regenerated',
                entityType: 'order',
                entityId: $lockedOrder->id,
                properties: [
                    'order_id' => $lockedOrder->id,
                    'city_id' => $lockedOrder->city_id,
                    'regenerated_by' => $admin->id,
                ],
                severity: 'warning',
            );

            return $lockedOrder->fresh();
        });
    }

    /**
     * Rétablit le stock des produits à quantité limitée lorsqu'une commande est annulée,
     * refusée ou expire sans paiement. Un produit qui repasse de 0 à >0 redevient disponible.
     */
    public function restaurerStockCommande(Order $order): void
    {
        $items = $order->items()->get();
        if ($items->isEmpty()) {
            return;
        }

        $productIds = $items->pluck('product_id')->filter()->unique()->sort()->values()->all();
        if (empty($productIds)) {
            return;
        }

        /** @var array<int, Product> $products */
        $products = Product::whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();

        foreach ($items as $item) {
            $product = $products[(int) $item->product_id] ?? null;
            if (! $product || $product->quantity === null) {
                continue;
            }

            $oldQty = (int) $product->quantity;
            $newQty = $oldQty + (int) $item->quantity;
            $product->update([
                'quantity' => $newQty,
                'is_available' => $oldQty === 0 && $newQty > 0 ? true : $product->is_available,
            ]);
        }
    }

    // ─── Méthodes privées ─────────────────────────────────────────────────────

    /**
     * Résout les produits du commerce avec verrou atomique (`lockForUpdate`),
     * vérifie et décrémente le stock (null = illimité, 0 = indisponible),
     * et calcule le total côté serveur.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: float, 1: array<int, Product>}
     */
    private function resoudreProduitsEtTotal(Merchant $merchant, array $items): array
    {
        $productIds = array_values(array_unique(array_map(static fn (array $i): int => (int) $i['product_id'], $items)));
        sort($productIds);

        /** @var array<int, Product> $resolved */
        $resolved = Product::where('merchant_id', $merchant->id)
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();

        // Agréger les quantités demandées par produit (au cas où un product_id est répété)
        $requestedByProduct = [];
        foreach ($items as $item) {
            $pid = (int) $item['product_id'];
            $requestedByProduct[$pid] = ($requestedByProduct[$pid] ?? 0) + (int) $item['quantity'];
        }

        $total = 0.0;
        foreach ($requestedByProduct as $productId => $qty) {
            $product = $resolved[$productId] ?? null;
            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => ['Un ou plusieurs produits n\'appartiennent pas à ce commerce.'],
                ]);
            }

            if (! $product->is_available || ($product->quantity !== null && (int) $product->quantity <= 0)) {
                throw new HttpResponseException(response()->json([
                    'succes' => false,
                    'code' => 'insufficient_stock',
                    'product_id' => $product->id,
                    'available_quantity' => $product->quantity !== null ? max(0, (int) $product->quantity) : 0,
                    'message' => "Le produit « {$product->name} » n'est actuellement pas disponible.",
                ], 422));
            }

            if ($product->quantity !== null && (int) $product->quantity < $qty) {
                throw new HttpResponseException(response()->json([
                    'succes' => false,
                    'code' => 'insufficient_stock',
                    'product_id' => $product->id,
                    'available_quantity' => (int) $product->quantity,
                    'message' => "Stock insuffisant pour « {$product->name} » ({$product->quantity} disponible(s)).",
                ], 422));
            }

            if ($product->quantity !== null) {
                $newQty = max(0, (int) $product->quantity - $qty);
                $product->update([
                    'quantity' => $newQty,
                    'is_available' => $newQty > 0,
                ]);
            }

            $total += (float) $product->price * $qty;
        }

        return [$total, $resolved];
    }
}
