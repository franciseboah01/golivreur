<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\EmergencyCenter;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\RoleHabilitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EmergencyModeSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RoleHabilitationSeeder::class);
    }

    public function test_api_activation_allowed_for_super_admin_and_dg_national(): void
    {
        $city = City::factory()->create(['emergency_mode_active' => false]);

        $dg = User::factory()->create([
            'role' => 'dg_national',
            'city_id' => null,
        ]);

        $response = $this->actingAs($dg, 'api')->postJson("/api/v1/admin/cities/{$city->id}/emergency-mode", [
            'activer' => true,
            'message' => 'Tempête majeure sur la ville',
        ]);

        $response->assertOk();
        $this->assertTrue((bool) $city->fresh()->emergency_mode_active);
        $this->assertEquals('Tempête majeure sur la ville', $city->fresh()->emergency_mode_message);

        // Vérification de la trace activity_logs
        $log = ActivityLog::where('action', 'emergency_mode_activated')
            ->where('entity_id', $city->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($dg->id, $log->user_id);
        $this->assertEquals(false, $log->properties['ancien_etat']);
        $this->assertEquals(true, $log->properties['nouvel_etat']);
        $this->assertEquals($city->id, $log->properties['city_id']);
    }

    public function test_api_deactivation_is_strictly_forbidden_to_dg_national_and_reserved_to_super_admin(): void
    {
        $city = City::factory()->create(['emergency_mode_active' => true]);

        $dg = User::factory()->create([
            'role' => 'dg_national',
            'city_id' => null,
        ]);

        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'city_id' => null,
        ]);

        // 1. DG tente de désactiver -> Refus 403
        $refuseResponse = $this->actingAs($dg, 'api')->postJson("/api/v1/admin/cities/{$city->id}/emergency-mode", [
            'activer' => false,
        ]);

        $refuseResponse->assertStatus(403);
        $this->assertTrue((bool) $city->fresh()->emergency_mode_active);

        // 2. Super Admin désactive -> Succès 200
        $okResponse = $this->actingAs($superAdmin, 'api')->postJson("/api/v1/admin/cities/{$city->id}/emergency-mode", [
            'activer' => false,
        ]);

        $okResponse->assertOk();
        $this->assertFalse((bool) $city->fresh()->emergency_mode_active);

        // Trace activity_logs désactivation
        $log = ActivityLog::where('action', 'emergency_mode_deactivated')
            ->where('entity_id', $city->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($superAdmin->id, $log->user_id);
        $this->assertEquals(true, $log->properties['ancien_etat']);
        $this->assertEquals(false, $log->properties['nouvel_etat']);
    }

    public function test_api_city_manager_cannot_activate_or_deactivate_emergency_mode(): void
    {
        $city = City::factory()->create(['emergency_mode_active' => false]);

        $cityManager = User::factory()->create([
            'role' => 'city_manager',
            'city_id' => $city->id,
        ]);

        $response = $this->actingAs($cityManager, 'api')->postJson("/api/v1/admin/cities/{$city->id}/emergency-mode", [
            'activer' => true,
            'message' => 'Tentative par city manager',
        ]);

        $response->assertStatus(403);
        $this->assertFalse((bool) $city->fresh()->emergency_mode_active);
    }

    public function test_api_emergency_message_cannot_exceed_255_characters(): void
    {
        $city = City::factory()->create(['emergency_mode_active' => false]);

        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
        ]);

        $response = $this->actingAs($superAdmin, 'api')->postJson("/api/v1/admin/cities/{$city->id}/emergency-mode", [
            'activer' => true,
            'message' => str_repeat('A', 256),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['message']);
    }

    public function test_city_manager_does_not_have_access_to_emergency_center(): void
    {
        $city = City::factory()->create(['name' => 'Daloa']);
        $cmHab = RoleHabilitation::where('slug', 'city_manager')->first();

        $cityManager = User::factory()->create([
            'role' => 'city_manager',
            'role_habilitation_id' => $cmHab?->id,
            'city_id' => $city->id,
        ]);

        $this->actingAs($cityManager);

        $this->assertFalse($cityManager->hasHabilitationTab('emergency_center'));
        $this->assertFalse(EmergencyCenter::canAccess());
    }

    public function test_filament_emergency_center_deactivation_action_forbidden_to_dg_national(): void
    {
        $cityActive = City::factory()->create(['emergency_mode_active' => true]);
        $cityInactive = City::factory()->create(['emergency_mode_active' => false]);

        $dgHab = RoleHabilitation::where('slug', 'dg_national')->first();
        $dg = User::factory()->create([
            'role' => 'dg_national',
            'role_habilitation_id' => $dgHab?->id,
            'city_id' => null,
        ]);

        $this->actingAs($dg);

        // En tant que DG, on a accès à la page (activation)
        $this->assertTrue(EmergencyCenter::canAccess());

        $component = Livewire::test(EmergencyCenter::class);

        // 1. L'action globale de levée est masquée au DG
        $component->assertActionHidden('clearEmergencyGlobal');

        // 2. L'action globale de déclenchement est visible au DG
        $component->assertActionVisible('triggerEmergencyGlobal');

        // 3. Sur une ville déjà en urgence, l'action de levée individuelle est masquée au DG
        $component->assertTableActionHidden('toggleEmergency', $cityActive);

        // 4. Sur une ville non en urgence, l'action de déclenchement est visible au DG
        $component->assertTableActionVisible('toggleEmergency', $cityInactive);

        // Maintenant, avec un super admin : toutes les actions de levée sont visibles et exécutables
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'city_id' => null,
        ]);

        $this->actingAs($superAdmin);
        $saComponent = Livewire::test(EmergencyCenter::class);
        $saComponent->assertActionVisible('clearEmergencyGlobal');
        $saComponent->assertTableActionVisible('toggleEmergency', $cityActive);
    }
}
