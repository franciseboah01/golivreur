<?php

namespace Tests\Feature\Settings;

use App\Filament\Pages\SettingsPage;
use App\Models\{ActivityLog, City, Setting, User};
use App\Modules\GoShop\Services\CommissionService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private SettingsService $settingsService;
    private CommissionService $commissionService;
    private City $daloa;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsService = app(SettingsService::class);
        $this->commissionService = app(CommissionService::class);

        $this->daloa = City::create([
            'name'       => 'Daloa',
            'is_active'  => true,
            'center_lat' => 6.8770,
            'center_lng' => -6.4502,
        ]);

        $this->admin = User::create([
            'name'      => 'Francis Admin',
            'phone'     => '0700000000',
            'email'     => 'admin@golivreur.ci',
            'password'  => bcrypt('Secret123!'),
            'role'      => 'super_admin',
            'is_active' => true,
        ]);
    }

    public function test_settings_service_returns_cdc_defaults(): void
    {
        $commissions = $this->settingsService->get('commissions');
        $this->assertEquals(15.0, $commissions['merchant_defaut']);
        $this->assertEquals(20.0, $commissions['livreur_goshop']);
        $this->assertEquals(25.0, $commissions['plateforme_gocolis']);

        $delais = $this->settingsService->get('delais_regles');
        $this->assertEquals(45, $delais['acceptation_commerce_secondes']);
        $this->assertEquals(30, $delais['acceptation_livreur_secondes']);
    }

    public function test_setting_modification_writes_to_activity_logs_and_invalidates_cache(): void
    {
        $this->actingAs($this->admin);

        $this->settingsService->set(
            key: 'commissions',
            value: [
                'merchant_defaut'    => 18.0,
                'livreur_goshop'      => 22.0,
                'plateforme_gocolis' => 30.0,
            ],
            userId: $this->admin->id,
            description: 'Augmentation temporaire'
        );

        // Vérifie la valeur mise à jour
        $updated = $this->settingsService->get('commissions');
        $this->assertEquals(18.0, $updated['merchant_defaut']);

        // Vérifie l'écriture dans activity_logs
        $this->assertDatabaseHas('activity_logs', [
            'action'      => 'setting_updated',
            'entity_type' => 'setting',
            'severity'    => 'critical',
            'user_id'     => $this->admin->id,
        ]);
    }

    public function test_city_specific_override_takes_precedence(): void
    {
        $this->actingAs($this->admin);

        // Définir une commission spécifique pour Daloa
        $this->settingsService->set(
            key: 'commissions',
            value: [
                'merchant_defaut'    => 12.0,
                'livreur_goshop'      => 15.0,
                'plateforme_gocolis' => 20.0,
            ],
            cityId: $this->daloa->id,
            userId: $this->admin->id
        );

        // Sans city_id -> valeur par défaut globale (15%)
        $global = $this->settingsService->get('commissions', null);
        $this->assertEquals(15.0, $global['merchant_defaut']);

        // Avec city_id de Daloa -> surcharge Daloa (12%)
        $daloaCommissions = $this->settingsService->get('commissions', $this->daloa->id);
        $this->assertEquals(12.0, $daloaCommissions['merchant_defaut']);
    }

    public function test_commission_service_uses_dynamic_settings(): void
    {
        $this->travelTo(now()->setTime(10, 0));

        // Prix par défaut pour 1km GoShop (hors pointe)
        $prixNormal = $this->commissionService->calculerFraisLivraison(1.0, $this->daloa);
        $this->assertEquals(500.0, $prixNormal);

        // Mettre à jour le barème pour Daloa
        $this->settingsService->set(
            key: 'tarifs_goshop',
            value: [
                'bareme' => [
                    ['max_km' => 2, 'normal' => 600, 'pointe' => 800, 'pluie' => 1200],
                ],
                'heures_pointe' => [],
            ],
            cityId: $this->daloa->id,
        );

        // Le nouveau tarif pour Daloa doit être 600 F
        $nouveauPrix = $this->commissionService->calculerFraisLivraison(1.0, $this->daloa);
        $this->assertEquals(600.0, $nouveauPrix);
    }

    public function test_branding_returns_default_emoji_when_no_logo_uploaded(): void
    {
        $branding = $this->settingsService->getBranding();
        $this->assertNull($branding['logo_path']);
        $this->assertNull($branding['logo_url']);
        $this->assertSame('🛵', $branding['default_emoji']);

        $this->getJson('/api/v1/branding')
            ->assertOk()
            ->assertJsonPath('succes', true)
            ->assertJsonPath('donnees.logo_url', null)
            ->assertJsonPath('donnees.default_emoji', '🛵');

        $this->getJson('/api/v1/client/city-status')
            ->assertOk()
            ->assertJsonPath('donnees.app_logo_url', null)
            ->assertJsonPath('donnees.app_default_emoji', '🛵');

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('🛵');
    }

    public function test_admin_settings_page_uploads_and_removes_golivreur_logo(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin);

        $fakeLogo = UploadedFile::fake()->image('golivreur-brand.png', 120, 120);

        Livewire::test(SettingsPage::class)
            ->set('data.branding.logo_path', $fakeLogo)
            ->set('data.branding.default_emoji', '🛵')
            ->call('save')
            ->assertHasNoFormErrors();

        $branding = $this->settingsService->getBranding();
        $this->assertNotNull($branding['logo_path']);
        $this->assertNotNull($branding['logo_url']);
        Storage::disk('public')->assertExists($branding['logo_path']);

        $this->getJson('/api/v1/branding')
            ->assertOk()
            ->assertJsonPath('donnees.logo_url', $branding['logo_url']);

        $this->getJson('/api/v1/client/city-status')
            ->assertOk()
            ->assertJsonPath('donnees.app_logo_url', $branding['logo_url']);

        $this->get('/admin')
            ->assertOk()
            ->assertSee($branding['logo_url'], false);

        // Suppression du logo -> retour à l'emoji par défaut et suppression du fichier
        $oldPath = $branding['logo_path'];
        Livewire::test(SettingsPage::class)
            ->set('data.branding.logo_path', null)
            ->call('save')
            ->assertHasNoFormErrors();

        Storage::disk('public')->assertMissing($oldPath);
        $this->assertNull($this->settingsService->getAppLogoUrl());
    }
}

