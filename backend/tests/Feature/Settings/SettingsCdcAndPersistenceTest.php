<?php

use App\Filament\Pages\SettingsPage;
use App\Models\User;
use App\Services\SettingsService;
use Livewire\Livewire;

it('4.1 SettingsService::DEFAULTS correspond exactement aux chapitres 8.1, 8.2, 8.3 et 8.4 du CDC', function () {
    $defaults = SettingsService::DEFAULTS;

    // CDC 8.3 — 12 tarifs GoShop (4 tranches × 3 niveaux)
    expect($defaults['tarifs_goshop']['bareme'])->toBe([
        ['max_km' => 2, 'normal' => 500, 'pointe' => 700, 'pluie' => 1000],
        ['max_km' => 4, 'normal' => 800, 'pointe' => 1000, 'pluie' => 1300],
        ['max_km' => 7, 'normal' => 1200, 'pointe' => 1500, 'pluie' => 1800],
        ['max_km' => 9999, 'normal' => 1500, 'pointe' => 1800, 'pluie' => 2000],
    ]);

    // CDC 8.3 — Heures de pointe
    expect($defaults['tarifs_goshop']['heures_pointe'])->toBe([
        ['debut' => '11:30', 'fin' => '14:00'],
        ['debut' => '18:00', 'fin' => '21:00'],
    ]);

    // CDC 8.4 — 3 forfaits GoColis + CDC 8.2 assurance 3 % au-dessus de 20 000 F CFA
    expect($defaults['tarifs_gocolis']['bareme'])->toBe([
        'petit' => ['base' => 800, 'par_km' => 200],
        'moyen' => ['base' => 1200, 'par_km' => 300],
        'grand' => ['base' => 2000, 'par_km' => 400],
    ])
        ->and($defaults['tarifs_gocolis']['seuil_assurance'])->toBe(20000)
        ->and($defaults['tarifs_gocolis']['taux_assurance'])->toBe(3.0);

    // CDC 8.1 & 8.2 — Commissions (15 % commerce, 20 % retenus livraison GoShop = 80 % livreur, 25 % plateforme GoColis = 75 % livreur)
    expect($defaults['commissions']['merchant_defaut'])->toBe(15.0)
        ->and($defaults['commissions']['livreur_goshop'])->toBe(20.0)
        ->and(100.0 - (float) $defaults['commissions']['livreur_goshop'])->toBe(80.0)
        ->and($defaults['commissions']['plateforme_gocolis'])->toBe(25.0)
        ->and(100.0 - (float) $defaults['commissions']['plateforme_gocolis'])->toBe(75.0);
});

it('4.2 SettingsPage affiche les libellés explicites et l\'exemple chiffré pour livreur_goshop et plateforme_gocolis', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $this->actingAs($admin);

    Livewire::test(SettingsPage::class)
        ->assertSuccessful()
        ->assertSee('Part retenue par la plateforme sur les frais de livraison (le livreur touche le reste : 100 − cette valeur)')
        ->assertSee('800 F → plateforme 160 F, livreur 640 F')
        ->assertSee('Part retenue par la plateforme sur les frais d\'expédition GoColis (le livreur touche le reste : 100 − cette valeur)')
        ->assertSee('1 800 F → plateforme 450 F, livreur 1 350 F');
});

it('4.3 l\'enregistrement sur SettingsPage d\'un seul groupe ne supprime ni n\'écrase aucune clé absente du formulaire ou valeur de secours', function () {
    $admin = User::factory()->create(['role' => 'super_admin']);
    $settingsService = app(SettingsService::class);

    // Clé personnalisée existante hors formulaire SettingsPage
    $settingsService->set('courses.max_actives', 2, null, $admin->id, 'Test persistance hors formulaire');
    $settingsService->set('cle_hors_formulaire_custom', ['actif' => true, 'seuil' => 777], null, $admin->id, 'Clé tierce');

    $this->actingAs($admin);

    Livewire::test(SettingsPage::class)
        ->set('data.commissions.merchant_defaut', 16.0)
        ->call('save')
        ->assertHasNoFormErrors();

    // Groupe modifié mis à jour
    expect((float) $settingsService->get('commissions')['merchant_defaut'])->toBe(16.0)
        ->and((float) $settingsService->get('commissions')['livreur_goshop'])->toBe(20.0)
        ->and((float) $settingsService->get('commissions')['plateforme_gocolis'])->toBe(25.0);

    // Sous-clé absente du formulaire (dg_national dans remboursements.plafonds) conservée intacte
    expect($settingsService->get('remboursements')['plafonds']['dg_national'] ?? null)
        ->toBe(SettingsService::DEFAULTS['remboursements']['plafonds']['dg_national']);

    // Clés absentes du formulaire et valeurs de secours intactes
    expect($settingsService->get('courses.max_actives'))->toBe(2)
        ->and($settingsService->get('cle_hors_formulaire_custom'))->toBe(['actif' => true, 'seuil' => 777])
        ->and($settingsService->get('tarifs_goshop'))->toEqual(SettingsService::DEFAULTS['tarifs_goshop'])
        ->and($settingsService->get('tarifs_gocolis'))->toEqual(SettingsService::DEFAULTS['tarifs_gocolis']);
});
