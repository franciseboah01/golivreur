<?php

namespace App\Modules\GoShop\Services;

use App\Models\{City, Courier, Merchant, Order, OrderItem, User};
use App\Services\{ActivityLogService, NotificationService, WalletService};
use Illuminate\Support\Facades\{DB, Hash, Log};
use Illuminate\Validation\ValidationException;

/**
 * Service central GoShop — gère tout le cycle de vie d'une commande :
 * création → attribution livreur → livraison → paiement wallet.
 */
class OrderService
{
    public function __construct(
        private readonly AssignmentService  $assignmentService,
        private readonly CommissionService  $commissionService,
        private readonly WalletService      $walletService,
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

            // Vérifier que le commerce est ouvert
            if (!$merchant->is_open || !$merchant->is_active) {
                throw ValidationException::withMessages([
                    'merchant_id' => ['Ce commerce est actuellement fermé.'],
                ]);
            }

            // Calculer les montants
            $city         = City::findOrFail($client->city_id);
            $produitsTotal = $this->calculerTotalProduits($donnees['items']);
            $fraisLivraison = $this->commissionService->calculerFraisLivraison(
                distance: $donnees['distance_km'],
                city: $city,
            );
            $commissions = $this->commissionService->calculerCommissions(
                produitsTotal: $produitsTotal,
                fraisLivraison: $fraisLivraison,
                merchant: $merchant,
            );

            // Créer la commande
            $order = Order::create([
                'client_id'           => $client->id,
                'merchant_id'         => $merchant->id,
                'city_id'             => $client->city_id,
                'status'              => Order::STATUS_PENDING,
                'products_total'      => $produitsTotal,
                'delivery_fee'        => $fraisLivraison,
                'platform_commission' => $commissions['plateforme'],
                'merchant_amount'     => $commissions['merchant'],
                'courier_amount'      => $commissions['livreur'],
                'rain_mode_applied'   => $city->rain_mode_active,
                'peak_hours_applied'  => $this->commissionService->estHeureDePointe(),
                'payment_method'      => $donnees['payment_method'],
                'payment_status'      => $donnees['payment_method'] === 'cash'
                                            ? 'pending_cash' : 'pending',
                'confirmation_code'   => Hash::make((string) random_int(1000, 9999)),
                'delivery_address'    => $donnees['delivery_address'],
                'delivery_lat'        => $donnees['delivery_lat'] ?? null,
                'delivery_lng'        => $donnees['delivery_lng'] ?? null,
                'pickup_lat'          => $merchant->lat,
                'pickup_lng'          => $merchant->lng,
                'special_instructions' => $donnees['special_instructions'] ?? null,
            ]);

            // Créer les lignes de commande avec snapshot des prix
            foreach ($donnees['items'] as $item) {
                /** @var \App\Models\Product $product */
                $product = $merchant->products()->findOrFail($item['product_id']);
                OrderItem::create([
                    'order_id'             => $order->id,
                    'product_id'           => $product->id,
                    'quantity'             => $item['quantity'],
                    'unit_price'           => $product->price,
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
            \App\Jobs\AnnulerCommandeTimeout::dispatch($order->id)
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
        if ($order->status !== Order::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Cette commande ne peut plus être acceptée.'],
            ]);
        }

        return DB::transaction(function () use ($order) {
            $order->update([
                'status'      => Order::STATUS_ACCEPTED,
                'accepted_at' => now(),
            ]);

            // Notifier le client
            $this->notificationService->notifierCommandeAcceptee($order);

            // Chercher le livreur le plus proche
            $this->assignmentService->attribuerLivreurGoShop($order);

            return $order->fresh();
        });
    }

    /**
     * Le commerce refuse la commande (statut v5).
     */
    public function refuserCommande(Order $order, string $raison = ''): Order
    {
        if ($order->status !== Order::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Cette commande ne peut plus être refusée.'],
            ]);
        }

        $order->update([
            'status'        => Order::STATUS_REFUSED,
            'cancelled_at'  => now(),
            'cancel_reason' => $raison ?: 'Refusé par le commerce.',
        ]);

        // Notifier le client immédiatement
        $this->notificationService->notifierCommandeRefusee($order);

        return $order->fresh();
    }

    /**
     * Le livreur saisit le code de confirmation — valide la livraison.
     */
    public function validerCodeConfirmation(Order $order, string $codeSaisi, ?User $admin = null): Order
    {
        if ($order->confirmation_locked) {
            throw ValidationException::withMessages([
                'code' => ['Cette livraison est bloquée. Le support a été contacté.'],
            ]);
        }

        // Vérifier le code (hashé bcrypt)
        if (!Hash::check($codeSaisi, $order->confirmation_code)) {
            $order->increment('confirmation_attempts');

            if ($order->confirmation_attempts >= 3) {
                $order->update(['confirmation_locked' => true]);
                // Alerte Admin en queue haute priorité
                \App\Jobs\AlerterAdminCodeBloque::dispatch($order, 'goshop')
                    ->onQueue('high');

                throw ValidationException::withMessages([
                    'code' => ['Code incorrect. Livraison bloquée — le support a été alerté.'],
                ]);
            }

            $restants = 3 - $order->confirmation_attempts;
            throw ValidationException::withMessages([
                'code' => ["Code incorrect. {$restants} tentative(s) restante(s)."],
            ]);
        }

        return DB::transaction(function () use ($order) {
            $order->update([
                'status'       => Order::STATUS_DELIVERED,
                'delivered_at' => now(),
            ]);

            // Créditer les wallets
            $this->walletService->crediterLivreur($order);
            $this->walletService->crediterMerchant($order);

            // Notifier le client
            $this->notificationService->notifierCommandeLivree($order);

            $this->activityLogService->log(
                userId: $order->courier->user_id,
                action: 'order_delivered',
                entityType: 'order',
                entityId: $order->id,
                properties: [
                    'courier_amount'  => $order->courier_amount,
                    'merchant_amount' => $order->merchant_amount,
                ],
            );

            return $order->fresh(['client', 'merchant', 'courier']);
        });
    }

    /**
     * Le commerce signale que la commande est prête à être ramassée.
     */
    public function signalerCommandePrete(Order $order): Order
    {
        if ($order->status !== Order::STATUS_ACCEPTED) {
            throw ValidationException::withMessages([
                'status' => ['La commande doit être acceptée avant d\'être marquée prête.'],
            ]);
        }

        $order->update([
            'status'   => Order::STATUS_READY,
            'ready_at' => now(),
        ]);

        // Notifier le livreur assigné
        if ($order->courier) {
            $this->notificationService->notifierCommandePrete($order);
        }

        return $order->fresh();
    }

    // ─── Méthodes privées ─────────────────────────────────────────────────────

    private function calculerTotalProduits(array $items): float
    {
        // Les prix sont récupérés depuis la BDD, jamais depuis le client
        $total = 0;
        foreach ($items as $item) {
            $product = \App\Models\Product::findOrFail($item['product_id']);
            $total += $product->price * $item['quantity'];
        }
        return $total;
    }
}
