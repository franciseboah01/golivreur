<?php

namespace App\Modules\GoShop\Services;

use App\Models\City;
use App\Models\Merchant;
use App\Services\SettingsService;
use Carbon\Carbon;

/**
 * Calcule tous les montants financiers GoShop et GoColis :
 * frais de livraison (barème + majorations), commissions plateforme/merchant/livreur, assurance.
 *
 * Les tarifs et commissions sont désormais configurables dynamiquement via SettingsService (table settings).
 */
class CommissionService
{
    // Barème de secours (utilisé si aucun paramètre n'est défini en base ou cache)
    private const BAREME = [
        ['max_km' => 2,  'normal' => 500,  'pointe' => 700,  'pluie' => 1000],
        ['max_km' => 4,  'normal' => 800,  'pointe' => 1000, 'pluie' => 1300],
        ['max_km' => 7,  'normal' => 1200, 'pointe' => 1500, 'pluie' => 1800],
        ['max_km' => PHP_INT_MAX, 'normal' => 1500, 'pointe' => 1800, 'pluie' => 2000],
    ];

    private const HEURES_POINTE = [
        ['debut' => '11:30', 'fin' => '14:00'],
        ['debut' => '18:00', 'fin' => '21:00'],
    ];

    private const COMMISSION_MERCHANT_DEFAUT = 15.0; // 15% du prix des produits

    private const COMMISSION_LIVREUR = 20.0; // 20% des frais de livraison (plateforme garde 20%)

    private const BAREME_GOCOLIS = [
        'petit' => ['base' => 800,  'par_km' => 200],
        'moyen' => ['base' => 1200, 'par_km' => 300],
        'grand' => ['base' => 2000, 'par_km' => 400],
    ];

    private const COMMISSION_GOCOLIS_PLATEFORME = 25.0; // 25% des frais d'expédition

    private const SEUIL_ASSURANCE = 20000;

    private const TAUX_ASSURANCE = 3.0;  // 3% de la valeur déclarée

    private readonly SettingsService $settingsService;

    public function __construct(?SettingsService $settingsService = null)
    {
        $this->settingsService = $settingsService ?? app(SettingsService::class);
    }

    public function calculerFraisLivraison(float $distance, City $city): float
    {
        $tarifs = $this->settingsService->get('tarifs_goshop', $city->id);
        $bareme = $tarifs['bareme'] ?? self::BAREME;
        $tranche = $this->getTranche($distance, $bareme);

        if ($city->rain_mode_active) {
            return (float) $tranche['pluie'];
        }

        if ($this->estHeureDePointe($city->id)) {
            return (float) $tranche['pointe'];
        }

        return (float) $tranche['normal'];
    }

    public function calculerCommissions(
        float $produitsTotal,
        float $fraisLivraison,
        Merchant $merchant
    ): array {
        $commissions = $this->settingsService->get('commissions', $merchant->city_id);
        $tauxCommission = $merchant->commission_rate ?? ($commissions['merchant_defaut'] ?? self::COMMISSION_MERCHANT_DEFAUT);
        $tauxLivreur = $commissions['livreur_goshop'] ?? self::COMMISSION_LIVREUR;

        $commissionMerchant = round($produitsTotal * ($tauxCommission / 100));
        $commissionLivreur = round($fraisLivraison * ($tauxLivreur / 100));
        $commissionPlateforme = $commissionMerchant + $commissionLivreur;

        return [
            'plateforme' => $commissionPlateforme,
            'merchant' => $produitsTotal - $commissionMerchant,
            'livreur' => $fraisLivraison - $commissionLivreur,
        ];
    }

    public function estHeureDePointe(?int $cityId = null): bool
    {
        $tarifs = $this->settingsService->get('tarifs_goshop', $cityId);
        $plages = $tarifs['heures_pointe'] ?? self::HEURES_POINTE;
        $heureCourante = Carbon::now()->format('H:i');

        foreach ($plages as $plage) {
            if ($heureCourante >= $plage['debut'] && $heureCourante <= $plage['fin']) {
                return true;
            }
        }

        return false;
    }

    private function getTranche(float $distance, ?array $bareme = null): array
    {
        $bareme = $bareme ?? self::BAREME;
        foreach ($bareme as $tranche) {
            if ($distance <= ($tranche['max_km'] ?? PHP_INT_MAX)) {
                return $tranche;
            }
        }

        return end($bareme);
    }

    // ─── GoColis ──────────────────────────────────────────────────────────────

    public function calculerFraisExpedition(string $taille, float $distance, ?City $city = null): float
    {
        $tarifs = $this->settingsService->get('tarifs_gocolis', $city?->id);
        $baremes = $tarifs['bareme'] ?? self::BAREME_GOCOLIS;
        $bareme = $baremes[$taille] ?? ($baremes['moyen'] ?? self::BAREME_GOCOLIS['moyen']);
        $kmSupplementaires = max(0, $distance - 2);

        return (float) ($bareme['base'] + ($kmSupplementaires * $bareme['par_km']));
    }

    public function calculerAssurance(float $valeurDeclaree, ?City $city = null): float
    {
        $tarifs = $this->settingsService->get('tarifs_gocolis', $city?->id);
        $seuil = $tarifs['seuil_assurance'] ?? self::SEUIL_ASSURANCE;
        $taux = $tarifs['taux_assurance'] ?? self::TAUX_ASSURANCE;

        if ($valeurDeclaree <= $seuil) {
            return 0.0;
        }

        return round($valeurDeclaree * ($taux / 100));
    }

    public function calculerCommissionsGoColis(float $fraisExpedition, ?City $city = null): array
    {
        $commissions = $this->settingsService->get('commissions', $city?->id);
        $tauxPlateforme = $commissions['plateforme_gocolis'] ?? self::COMMISSION_GOCOLIS_PLATEFORME;

        $commissionPlateforme = round($fraisExpedition * ($tauxPlateforme / 100));

        return [
            'plateforme' => $commissionPlateforme,
            'livreur' => $fraisExpedition - $commissionPlateforme, // Ex: 75%
        ];
    }
}
