<?php

namespace App\Modules\GoShop\Services;

use App\Models\{City, Courier, Merchant, Order, Parcel};
use App\Jobs\{NotifierLivreurGoShop, NotifierLivreurGoColis};
use App\Services\NotificationService;
use Carbon\Carbon;

/**
 * Calcule tous les montants financiers GoShop :
 * frais de livraison (barème + majorations), commissions plateforme/merchant/livreur.
 */
class CommissionService
{
    // Barème des frais de livraison en F CFA
    private const BAREME = [
        ['max_km' => 2,  'normal' => 500,  'pointe' => 700,  'pluie' => 1000],
        ['max_km' => 4,  'normal' => 800,  'pointe' => 1000, 'pluie' => 1300],
        ['max_km' => 7,  'normal' => 1200, 'pointe' => 1500, 'pluie' => 1800],
        ['max_km' => PHP_INT_MAX, 'normal' => 1500, 'pointe' => 1800, 'pluie' => 2000],
    ];

    // Heures de pointe : 11h30-14h00 et 18h00-21h00
    private const HEURES_POINTE = [
        ['debut' => '11:30', 'fin' => '14:00'],
        ['debut' => '18:00', 'fin' => '21:00'],
    ];

    private const COMMISSION_MERCHANT_DEFAUT = 15.0; // 15% du prix des produits
    private const COMMISSION_LIVREUR         = 20.0; // 20% des frais de livraison (plateforme garde 20%)

    public function calculerFraisLivraison(float $distance, City $city): float
    {
        $tranche = $this->getTranche($distance);

        if ($city->rain_mode_active) {
            return $tranche['pluie'];
        }

        if ($this->estHeureDePointe()) {
            return $tranche['pointe'];
        }

        return $tranche['normal'];
    }

    public function calculerCommissions(
        float $produitsTotal,
        float $fraisLivraison,
        Merchant $merchant
    ): array {
        $tauxCommission = $merchant->commission_rate ?? self::COMMISSION_MERCHANT_DEFAUT;

        $commissionMerchant  = round($produitsTotal * ($tauxCommission / 100));
        $commissionLivreur   = round($fraisLivraison * (self::COMMISSION_LIVREUR / 100));
        $commissionPlateforme = $commissionMerchant + $commissionLivreur;

        return [
            'plateforme' => $commissionPlateforme,
            'merchant'   => $produitsTotal - $commissionMerchant,
            'livreur'    => $fraisLivraison - $commissionLivreur,
        ];
    }

    public function estHeureDePointe(): bool
    {
        $heureCourante = Carbon::now()->format('H:i');
        foreach (self::HEURES_POINTE as $plage) {
            if ($heureCourante >= $plage['debut'] && $heureCourante <= $plage['fin']) {
                return true;
            }
        }
        return false;
    }

    private function getTranche(float $distance): array
    {
        foreach (self::BAREME as $tranche) {
            if ($distance <= $tranche['max_km']) {
                return $tranche;
            }
        }
        return end(self::BAREME);
    }

    // ─── GoColis ──────────────────────────────────────────────────────────────

    private const BAREME_GOCOLIS = [
        'petit'  => ['base' => 800,  'par_km' => 200],
        'moyen'  => ['base' => 1200, 'par_km' => 300],
        'grand'  => ['base' => 2000, 'par_km' => 400],
    ];

    private const COMMISSION_GOCOLIS_PLATEFORME = 25.0; // 25% des frais d'expédition
    private const SEUIL_ASSURANCE               = 20000;
    private const TAUX_ASSURANCE                = 3.0;  // 3% de la valeur déclarée

    public function calculerFraisExpedition(string $taille, float $distance): float
    {
        $bareme = self::BAREME_GOCOLIS[$taille] ?? self::BAREME_GOCOLIS['moyen'];
        $kmSupplementaires = max(0, $distance - 2);
        return $bareme['base'] + ($kmSupplementaires * $bareme['par_km']);
    }

    public function calculerAssurance(float $valeurDeclaree): float
    {
        if ($valeurDeclaree <= self::SEUIL_ASSURANCE) {
            return 0;
        }
        return round($valeurDeclaree * (self::TAUX_ASSURANCE / 100));
    }

    public function calculerCommissionsGoColis(float $fraisExpedition): array
    {
        $commissionPlateforme = round($fraisExpedition * (self::COMMISSION_GOCOLIS_PLATEFORME / 100));
        return [
            'plateforme' => $commissionPlateforme,
            'livreur'    => $fraisExpedition - $commissionPlateforme, // 75%
        ];
    }
}

// ═══════════════════════════════════════════════════════════════════════════════

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
            // Pas de livreur disponible — alerte Admin
            \App\Jobs\AlerterAdminPasDelivreur::dispatch($order, 'goshop')
                ->onQueue('high');
            return;
        }

        // Proposer la course au livreur (30 secondes pour accepter)
        $this->notificationService->proposerCourseGoShop($livreur, $order);

        // Si pas de réponse dans les 30s, passer au suivant
        NotifierLivreurGoShop::dispatch($order->id, $livreur->id, attempt: 1)
            ->delay(now()->addSeconds(self::DELAI_ACCEPTATION_GOSHOP))
            ->onQueue('default');
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
            \App\Jobs\AlerterAdminPasDelivreur::dispatch($parcel, 'gocolis')
                ->onQueue('high');
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
            \App\Jobs\AlerterAdminPasDelivreur::dispatch($parcel, 'gocolis')
                ->onQueue('high');
            return;
        }

        // Notifier le livreur avec la taille du colis visible
        $this->notificationService->proposerCourseGoColis($livreur, $parcel);

        NotifierLivreurGoColis::dispatch($parcel->id, $livreur->id, $tentative, [...$exclusions, $livreur->id])
            ->delay(now()->addSeconds(self::DELAI_ACCEPTATION_GOCOLIS))
            ->onQueue('default');
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
            ->selectRaw(
                '*, (6371 * acos(cos(radians(?)) * cos(radians(current_lat)) * cos(radians(current_lng) - radians(?)) + sin(radians(?)) * sin(radians(current_lat)))) AS distance',
                [$lat, $lng, $lat]
            )
            ->having('distance', '<', self::RAYON_ETENDU_KM)
            ->orderBy('distance')
            ->first();
    }
}
