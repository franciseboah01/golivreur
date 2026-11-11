<?php

use App\Models\City;
use App\Modules\GoShop\Services\CommissionService;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

it('calcule le barème GoShop CDC 8.3 en tarif normal, pointe seule, pluie seule et pluie+pointe', function () {
    $service = app(CommissionService::class);
    $cityDry = City::factory()->create(['rain_mode_active' => false]);
    $cityRain = City::factory()->create(['rain_mode_active' => true]);

    // 1. Tarif normal (hors pointe, sans pluie) sur les 4 tranches CDC 8.3
    $this->travelTo(now()->setTime(10, 0));
    expect($service->calculerFraisLivraison(1.5, $cityDry))->toBe(500.0)
        ->and($service->calculerFraisLivraison(3.0, $cityDry))->toBe(800.0)
        ->and($service->calculerFraisLivraison(5.0, $cityDry))->toBe(1200.0)
        ->and($service->calculerFraisLivraison(9.0, $cityDry))->toBe(1500.0);

    // 2. Pluie seule (hors pointe, mode pluie actif)
    expect($service->calculerFraisLivraison(1.5, $cityRain))->toBe(1000.0)
        ->and($service->calculerFraisLivraison(3.0, $cityRain))->toBe(1300.0)
        ->and($service->calculerFraisLivraison(5.0, $cityRain))->toBe(1800.0)
        ->and($service->calculerFraisLivraison(9.0, $cityRain))->toBe(2000.0);

    // 3. Pointe seule (en heure de pointe, sans pluie)
    $this->travelTo(now()->setTime(12, 30));
    expect($service->calculerFraisLivraison(1.5, $cityDry))->toBe(700.0)
        ->and($service->calculerFraisLivraison(3.0, $cityDry))->toBe(1000.0)
        ->and($service->calculerFraisLivraison(5.0, $cityDry))->toBe(1500.0)
        ->and($service->calculerFraisLivraison(9.0, $cityDry))->toBe(1800.0);

    // 4. Pluie + pointe simultanées (CDC silencieux -> comportement actuel conservé : pluie prioritaire, sans cumul)
    expect($service->calculerFraisLivraison(1.5, $cityRain))->toBe(1000.0)
        ->and($service->calculerFraisLivraison(3.0, $cityRain))->toBe(1300.0)
        ->and($service->calculerFraisLivraison(5.0, $cityRain))->toBe(1800.0)
        ->and($service->calculerFraisLivraison(9.0, $cityRain))->toBe(2000.0);
});

it('vérifie les bornes horaires des heures de pointe GoShop (11:29, 11:30, 14:00, 14:01 et soirée)', function () {
    $service = app(CommissionService::class);
    $city = City::factory()->create(['rain_mode_active' => false]);

    // Plage midi CDC 8.3 : 11h30-14h00 (bornes inclusives dans l'implémentation actuelle)
    $this->travelTo(now()->setTime(11, 29));
    expect($service->estHeureDePointe($city->id))->toBeFalse()
        ->and($service->calculerFraisLivraison(3.0, $city))->toBe(800.0);

    $this->travelTo(now()->setTime(11, 30));
    expect($service->estHeureDePointe($city->id))->toBeTrue()
        ->and($service->calculerFraisLivraison(3.0, $city))->toBe(1000.0);

    $this->travelTo(now()->setTime(14, 0));
    expect($service->estHeureDePointe($city->id))->toBeTrue()
        ->and($service->calculerFraisLivraison(3.0, $city))->toBe(1000.0);

    $this->travelTo(now()->setTime(14, 1));
    expect($service->estHeureDePointe($city->id))->toBeFalse()
        ->and($service->calculerFraisLivraison(3.0, $city))->toBe(800.0);

    // Plage soir CDC 8.3 : 18h00-21h00
    $this->travelTo(now()->setTime(17, 59));
    expect($service->estHeureDePointe($city->id))->toBeFalse();

    $this->travelTo(now()->setTime(18, 0));
    expect($service->estHeureDePointe($city->id))->toBeTrue();

    $this->travelTo(now()->setTime(21, 0));
    expect($service->estHeureDePointe($city->id))->toBeTrue();

    $this->travelTo(now()->setTime(21, 1));
    expect($service->estHeureDePointe($city->id))->toBeFalse();
});

it('calcule le barème GoColis CDC 8.2/8.4 (cas 4 km moyen = 1 800 F CFA et partage 75%/25%)', function () {
    $service = app(CommissionService::class);
    $city = City::factory()->create(['rain_mode_active' => true]);

    $this->travelTo(now()->setTime(12, 30)); // heure de pointe + pluie active

    $fraisMoyen4Km = $service->calculerFraisExpedition('moyen', 4.0, $city);
    $commissions = $service->calculerCommissionsGoColis($fraisMoyen4Km, $city);

    expect($fraisMoyen4Km)->toBe(1800.0)
        ->and($commissions['livreur'])->toBe(1350.0)
        ->and($commissions['plateforme'])->toBe(450.0);
});
