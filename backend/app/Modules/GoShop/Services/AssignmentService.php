<?php

namespace App\Modules\GoShop\Services;

use App\Models\{Courier, Order, Parcel};
use App\Jobs\{NotifierLivreurGoShop, NotifierLivreurGoColis};
use App\Services\{ActivityLogService, NotificationService};
use Illuminate\Support\Facades\DB;

/**
 * Attribue les courses aux livreurs disponibles par proximité géographique.
 * Gère jusqu'à 3 tentatives avant de passer en statut 'waiting' (GoColis)
 * ou d'alerter l'admin (GoShop).
 */
class AssignmentService
{
    // Rayon de recherche initial en km, étendu si aucun livreur trouvé
    private const RAYON_INITIAL_KM = 5;
    private const RAYON_ETENDU_KM  = 10;
    private const MAX_TENTATIVES   = 3;
    // Délai d'acceptation en secondes
    private const DELAI_ACCEPTATION_GOSHOP  = 30;
    private const DELAI_ACCEPTATION_GOCOLIS = 30;

    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly ActivityLogService  $activityLogService,
    ) {}

    /**
     * Trouve le livreur le plus proche et lui propose la course GoShop.
     */
    public function attribuerLivreurGoShop(Order $order): void
    {
        $livreur = $this->trouverLivreurProche(
            lat: $order->pickup_lat,
            lng: $order->pickup_lng,
            cityId: $order->city_id,
            excludeIds: [],
        );

        if (!$livreur) {
            if (class_exists(\App\Jobs\AlerterAdminPasDelivreur::class)) {
                \App\Jobs\AlerterAdminPasDelivreur::dispatch($order, 'goshop')
                    ->onQueue('high');
            }
            return;
        }

        // Proposer la course au livreur (30 secondes pour accepter)
        $this->notificationService->proposerCourseGoShop($livreur, $order);

        // Si pas de réponse dans les 30s, passer au suivant
        if (class_exists(NotifierLivreurGoShop::class)) {
            NotifierLivreurGoShop::dispatch($order->id, $livreur->id, 1)
                ->delay(now()->addSeconds(self::DELAI_ACCEPTATION_GOSHOP))
                ->onQueue('default');
        }
    }

    /**
     * Trouve le livreur le plus proche et lui propose la course GoColis.
     * La taille du colis est visible avant acceptation (v5).
     */
    public function attribuerLivreurGoColis(Parcel $parcel, int $tentative = 1, array $exclusions = []): void
    {
        if ($tentative > self::MAX_TENTATIVES) {
            // 3 tentatives échouées → statut waiting + alerte Admin
            $parcel->update(['status' => Parcel::STATUS_WAITING]);
            if (class_exists(\App\Jobs\AlerterAdminPasDelivreur::class)) {
                \App\Jobs\AlerterAdminPasDelivreur::dispatch($parcel, 'gocolis')
                    ->onQueue('high');
            }
            $this->notificationService->notifierClientAttenteGoColis($parcel);
            return;
        }

        $livreur = $this->trouverLivreurProche(
            lat: $parcel->pickup_lat,
            lng: $parcel->pickup_lng,
            cityId: $parcel->city_id,
            excludeIds: $exclusions,
        );

        if (!$livreur) {
            $parcel->update(['status' => Parcel::STATUS_WAITING]);
            if (class_exists(\App\Jobs\AlerterAdminPasDelivreur::class)) {
                \App\Jobs\AlerterAdminPasDelivreur::dispatch($parcel, 'gocolis')
                    ->onQueue('high');
            }
            return;
        }

        // Notifier le livreur avec la taille du colis visible
        $this->notificationService->proposerCourseGoColis($livreur, $parcel);

        if (class_exists(NotifierLivreurGoColis::class)) {
            NotifierLivreurGoColis::dispatch($parcel->id, $livreur->id, $tentative, [...$exclusions, $livreur->id])
                ->delay(now()->addSeconds(self::DELAI_ACCEPTATION_GOCOLIS))
                ->onQueue('default');
        }
    }

    /**
     * Trouve le livreur en ligne le plus proche d'un point GPS dans une ville.
     * Utilise la formule de distance haversine approximée (rapide, suffisante à l'échelle de Daloa).
     */
    private function trouverLivreurProche(
        ?float $lat,
        ?float $lng,
        int $cityId,
        array $excludeIds = [],
    ): ?Courier {
        if (!$lat || !$lng) {
            return Courier::where('city_id', $cityId)
                ->where('is_online', true)
                ->where('is_active', true)
                ->whereNull('penalty_until')
                ->orWhere('penalty_until', '<', now())
                ->whereNotIn('id', $excludeIds)
                ->first();
        }

        $haversine = '(6371 * acos(least(1.0, greatest(-1.0, cos(radians(?)) * cos(radians(current_lat)) * cos(radians(current_lng) - radians(?)) + sin(radians(?)) * sin(radians(current_lat))))))';

        return Courier::where('city_id', $cityId)
            ->where('is_online', true)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('penalty_until')
                  ->orWhere('penalty_until', '<', now());
            })
            ->whereNotIn('id', $excludeIds)
            ->whereNotNull('current_lat')
            ->whereNotNull('current_lng')
            ->selectRaw("*, {$haversine} AS distance", [$lat, $lng, $lat])
            ->whereRaw("{$haversine} < ?", [$lat, $lng, $lat, self::RAYON_ETENDU_KM])
            ->orderBy('distance')
            ->first();
    }
}
