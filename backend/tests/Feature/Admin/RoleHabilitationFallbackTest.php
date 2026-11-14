<?php

namespace Tests\Feature\Admin;

use App\Models\RoleHabilitation;
use App\Models\User;
use Database\Seeders\RoleHabilitationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleHabilitationFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_habilitation_tab_fallback_without_db_relation(): void
    {
        // 1. city_finance sans relation DB chargée
        $cf = new User(['role' => 'city_finance']);
        $this->assertTrue($cf->hasHabilitationTab('finances'));
        $this->assertTrue($cf->hasHabilitationTab('refunds'));
        $this->assertTrue($cf->hasHabilitationTab('dashboard'));
        $this->assertFalse($cf->hasHabilitationTab('orders'));
        $this->assertFalse($cf->hasHabilitationTab('emergency_center'));
        $this->assertFalse($cf->hasHabilitationTab('settings'));

        // 2. national_tech sans relation DB
        $tech = new User(['role' => 'national_tech']);
        $this->assertTrue($tech->hasHabilitationTab('dashboard'));
        $this->assertTrue($tech->hasHabilitationTab('webhook_logs'));
        $this->assertTrue($tech->hasHabilitationTab('activity_logs'));
        $this->assertTrue($tech->hasHabilitationTab('settings'));
        $this->assertFalse($tech->hasHabilitationTab('finances'));
        $this->assertFalse($tech->hasHabilitationTab('emergency_center'));

        // 3. city_manager sans relation DB (doit avoir refunds, pas emergency_center)
        $cm = new User(['role' => 'city_manager']);
        $this->assertTrue($cm->hasHabilitationTab('refunds'));
        $this->assertTrue($cm->hasHabilitationTab('orders'));
        $this->assertTrue($cm->hasHabilitationTab('administrators'));
        $this->assertFalse($cm->hasHabilitationTab('emergency_center'));
    }

    public function test_role_habilitation_seeded_and_migrated_matches_cdc(): void
    {
        $this->seed(RoleHabilitationSeeder::class);

        $cmRole = RoleHabilitation::where('slug', 'city_manager')->first();
        $this->assertNotNull($cmRole);
        $this->assertTrue($cmRole->hasOnglet('refunds'));
        $this->assertFalse($cmRole->hasOnglet('emergency_center'));

        $cfRole = RoleHabilitation::where('slug', 'city_finance')->first();
        $this->assertNotNull($cfRole);
        $this->assertTrue($cfRole->hasOnglet('finances'));
        $this->assertTrue($cfRole->hasOnglet('refunds'));

        $techRole = RoleHabilitation::where('slug', 'national_tech')->first();
        $this->assertNotNull($techRole);
        $this->assertTrue($techRole->hasOnglet('webhook_logs'));
        $this->assertTrue($techRole->hasOnglet('settings'));
    }
}
