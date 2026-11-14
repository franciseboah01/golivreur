<?php

namespace App\Services;

use App\Jobs\SendPushNotification;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    private readonly SettingsService $settingsService;

    public function __construct(?SettingsService $settingsService = null)
    {
        $this->settingsService = $settingsService ?? app(SettingsService::class);
    }

    public function notifierNouvelleCommande(Merchant $merchant, Order $order): void
    {
        $delaiSecondes = $this->settingsService->getDelaiRegle(
            'acceptation_commerce_secondes',
            $merchant->city_id ? (int) $merchant->city_id : null,
            45,
        );

        $this->createNotification(
            userId: $merchant->user_id,
            type: 'new_order',
            title: 'Nouvelle commande GoShop',
            body: "Commande #{$order->id} reçue. Confirmez sous {$delaiSecondes}s.",
            data: ['order_id' => $order->id],
        );
    }

    public function notifierCommandeAcceptee(Order $order): void
    {
        $this->createNotification(
            userId: $order->client_id,
            type: 'order_accepted',
            title: 'Commande confirmée',
            body: 'Votre commande a été acceptée et est en préparation.',
            data: ['order_id' => $order->id],
        );
    }

    public function notifierCommandeRefusee(Order $order): void
    {
        $this->createNotification(
            userId: $order->client_id,
            type: 'order_refused',
            title: 'Commande refusée',
            body: 'Le commerce ne peut pas traiter votre commande pour le moment.',
            data: ['order_id' => $order->id],
        );
    }

    public function notifierCommandePrete(Order $order): void
    {
        if ($order->courier_id && $order->courier) {
            $this->createNotification(
                userId: $order->courier->user_id,
                type: 'order_ready',
                title: 'Commande prête',
                body: "La commande #{$order->id} est prête à être récupérée.",
                data: ['order_id' => $order->id],
            );
        }
    }

    public function notifierCommandeAccepteeLivreur(Order $order): void
    {
        $this->notifierLivreurEnRoute(
            userId: (int) $order->client_id,
            type: 'order_driver_accepted',
            body: "Un livreur a accepté votre commande #{$order->id}.",
            data: ['order_id' => $order->id],
        );
    }

    public function notifierCommandeRamassage(Order $order): void
    {
        $this->createNotification(
            userId: (int) $order->client_id,
            type: 'order_picked_up',
            title: 'Commande récupérée',
            body: "Le livreur a récupéré votre commande #{$order->id} et est en route.",
            data: ['order_id' => $order->id],
        );
    }

    public function notifierCommandeLivree(Order $order): void
    {
        $this->createNotification(
            userId: $order->client_id,
            type: 'order_delivered',
            title: 'Commande livrée',
            body: 'Votre commande a été livrée avec succès. Bon appétit !',
            data: ['order_id' => $order->id],
        );
    }

    public function notifierColisAccepteLivreur(Parcel $parcel): void
    {
        $this->notifierLivreurEnRoute(
            userId: (int) $parcel->sender_id,
            type: 'parcel_driver_accepted',
            body: "Un livreur a accepté votre expédition #{$parcel->id}.",
            data: ['parcel_id' => $parcel->id],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notifierLivreurEnRoute(int $userId, string $type, string $body, array $data): void
    {
        $this->createNotification(
            userId: $userId,
            type: $type,
            title: 'Livreur en route',
            body: $body,
            data: $data,
        );
    }

    public function notifierColisRamasse(Parcel $parcel): void
    {
        $this->createNotification(
            userId: $parcel->sender_id,
            type: 'parcel_picked_up',
            title: 'Colis ramassé',
            body: 'Le livreur a pris en charge votre colis.',
            data: ['parcel_id' => $parcel->id],
        );
    }

    public function notifierColisLivre(Parcel $parcel): void
    {
        $this->createNotification(
            userId: $parcel->sender_id,
            type: 'parcel_delivered',
            title: 'Colis livré',
            body: 'Votre colis a été remis au destinataire avec succès.',
            data: ['parcel_id' => $parcel->id],
        );
    }

    public function notifierClientAttenteGoShop(Order $order): void
    {
        $this->notifierClientAttente(
            userId: (int) $order->client_id,
            type: 'order_waiting',
            data: ['order_id' => $order->id],
        );
    }

    public function notifierClientAttenteGoColis(Parcel $parcel): void
    {
        $this->notifierClientAttente(
            userId: (int) $parcel->sender_id,
            type: 'parcel_waiting',
            data: ['parcel_id' => $parcel->id],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notifierClientAttente(int $userId, string $type, array $data): void
    {
        $this->createNotification(
            userId: $userId,
            type: $type,
            title: 'Recherche de livreur en cours',
            body: 'Tous nos livreurs sont occupés. Nous continuons la recherche.',
            data: $data,
        );
    }

    public function proposerCourseGoShop(Courier $livreur, Order $order): void
    {
        $this->createNotification(
            userId: $livreur->user_id,
            type: 'course_offer_goshop',
            title: 'Nouvelle course GoShop disponible',
            body: "Course #{$order->id} — gain : {$order->courier_amount} F CFA",
            data: ['order_id' => $order->id],
        );
    }

    public function proposerCourseGoColis(Courier $livreur, Parcel $parcel): void
    {
        $this->createNotification(
            userId: $livreur->user_id,
            type: 'course_offer_gocolis',
            title: 'Nouvelle expédition GoColis disponible',
            body: "Colis {$parcel->size_label} — gain : {$parcel->courier_amount} F CFA",
            data: ['parcel_id' => $parcel->id, 'size' => $parcel->size, 'size_label' => $parcel->size_label],
        );
    }

    public function notifierPaiementConfirme(?User $user, ?Order $order = null, ?Parcel $parcel = null): void
    {
        if (! $user) {
            return;
        }

        $this->createNotification(
            userId: $user->id,
            type: 'payment_success',
            title: 'Paiement confirmé',
            body: 'Votre paiement a été validé avec succès.',
            data: ['order_id' => $order?->id, 'parcel_id' => $parcel?->id],
        );
    }

    public function notifierPaiementEchoue(?User $user, ?Order $order = null, ?Parcel $parcel = null): void
    {
        if (! $user) {
            return;
        }

        $this->createNotification(
            userId: $user->id,
            type: 'payment_failed',
            title: 'Échec de paiement',
            body: 'Le paiement n\'a pas pu être validé. Veuillez réessayer.',
            data: ['order_id' => $order?->id, 'parcel_id' => $parcel?->id],
        );
    }

    private function createNotification(int $userId, string $type, string $title, string $body, array $data = []): Notification
    {
        $notification = Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'is_read' => false,
        ]);

        try {
            $user = User::find($userId);
            if ($user && $user->device_token) {
                SendPushNotification::dispatch($user->device_token, $title, $body, $data);
            }
        } catch (\Throwable $e) {
            Log::warning("Notification push non envoyée : {$e->getMessage()}");
        }

        return $notification;
    }
}
