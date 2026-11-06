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
        $bareme = self::BAREME;
        return end($bareme);
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

