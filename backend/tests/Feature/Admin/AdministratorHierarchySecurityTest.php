<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\AdministratorResource;
use App\Filament\Resources\AdministratorResource\Pages\CreateAdministrator;
use App\Filament\Resources\AdministratorResource\Pages\EditAdministrator;
use App\Models\City;
use App\Models\RoleHabilitation;
use App\Models\User;
use Database\Seeders\RoleHabilitationSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdministratorHierarchySecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleHabilitationSeeder::class);
    }

    public function test_role_selector_options_depend_on_authenticated_user_role(): void
    {
        $cityDaloa = City::factory()->create(['name' => 'Daloa']);

        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'city_id' => null,
        ]);

        $dg = User::factory()->create([
            'role' => 'dg_national',
            'city_id' => null,
        ]);

        $cityManager = User::factory()->create([
            'role' => 'city_manager',
            'city_id' => $cityDaloa->id,
        ]);

        $citySupport = User::factory()->create([
            'role' => 'city_support',
            'city_id' => $cityDaloa->id,
        ]);

        $saRoles = array_keys(AdministratorResource::getAllowedRolesForUser($superAdmin));
        $this->assertContains('super_admin', $saRoles);
        $this->assertContains('dg_national', $saRoles);
        $this->assertContains('city_manager', $saRoles);
        $this->assertContains('city_support', $saRoles);

        $dgRoles = array_keys(AdministratorResource::getAllowedRolesForUser($dg));
        $this->assertNotContains('super_admin', $dgRoles);
        $this->assertContains('city_manager', $dgRoles);
        $this->assertContains('national_finance', $dgRoles);

        $cmRoles = array_keys(AdministratorResource::getAllowedRolesForUser($cityManager));
        $this->assertEqualsCanonicalizing(['city_support', 'city_agent', 'city_finance'], $cmRoles);

        $csRoles = array_keys(AdministratorResource::getAllowedRolesForUser($citySupport));
        $this->assertEmpty($csRoles);
    }

    public function test_city_manager_cannot_create_superior_role_even_when_forging_request(): void
    {
        $cityDaloa = City::factory()->create(['name' => 'Daloa']);
        $cityBouake = City::factory()->create(['name' => 'Bouaké']);

        $cmHab = RoleHabilitation::where('slug', 'city_manager')->first();
        $cityManager = User::factory()->create([
            'role' => 'city_manager',
            'role_habilitation_id' => $cmHab?->id,
            'city_id' => $cityDaloa->id,
        ]);

        $this->actingAs($cityManager);

        // Tentative 1 : créer un super_admin (attaque d'élévation de privilège)
        Livewire::test(CreateAdministrator::class)
            ->fillForm([
                'name' => 'Hacker Admin',
                'email' => 'hacker@golivreur.ci',
                'phone' => '0700000001',
                'password' => 'Password123!',
                'role' => 'super_admin',
                'city_id' => $cityBouake->id,
            ])
            ->call('create')
            ->assertForbidden();

        // Tentative 2 : créer un dg_national
        Livewire::test(CreateAdministrator::class)
            ->fillForm([
                'name' => 'Hacker DG',
                'email' => 'hackerdg@golivreur.ci',
                'phone' => '0700000002',
                'password' => 'Password123!',
                'role' => 'dg_national',
                'city_id' => $cityDaloa->id,
            ])
            ->call('create')
            ->assertForbidden();

        // Tentative 3 : créer un city_manager (seul un rôle supérieur peut nommer un city_manager)
        Livewire::test(CreateAdministrator::class)
            ->fillForm([
                'name' => 'Autre City Manager',
                'email' => 'cm2@golivreur.ci',
                'phone' => '0700000003',
                'password' => 'Password123!',
                'role' => 'city_manager',
                'city_id' => $cityDaloa->id,
            ])
            ->call('create')
            ->assertForbidden();
    }

    public function test_city_manager_creating_local_agent_forces_city_id_to_own_city(): void
    {
        $cityDaloa = City::factory()->create(['name' => 'Daloa']);
        $cityBouake = City::factory()->create(['name' => 'Bouaké']);

        $cmHab = RoleHabilitation::where('slug', 'city_manager')->first();
        $cityManager = User::factory()->create([
            'role' => 'city_manager',
            'role_habilitation_id' => $cmHab?->id,
            'city_id' => $cityDaloa->id,
        ]);

        $this->actingAs($cityManager);

        // Le city_manager tente de créer un agent en envoyant la ville de Bouaké
        Livewire::test(CreateAdministrator::class)
            ->fillForm([
                'name' => 'Agent Daloa Legit',
                'email' => 'agent.legit@golivreur.ci',
                'phone' => '0700000010',
                'password' => 'Password123!',
                'role' => 'city_agent',
                'city_id' => $cityBouake->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::where('email', 'agent.legit@golivreur.ci')->first();
        $this->assertNotNull($created);
        $this->assertEquals('city_agent', $created->role);
        // La ville doit être forcée à Daloa (ville du city_manager), ignorant Bouaké
        $this->assertEquals($cityDaloa->id, $created->city_id);
    }

    public function test_city_manager_cannot_edit_admin_from_another_city(): void
    {
        $cityDaloa = City::factory()->create(['name' => 'Daloa']);
        $cityBouake = City::factory()->create(['name' => 'Bouaké']);

        $cmHab = RoleHabilitation::where('slug', 'city_manager')->first();
        $cityManager = User::factory()->create([
            'role' => 'city_manager',
            'role_habilitation_id' => $cmHab?->id,
            'city_id' => $cityDaloa->id,
        ]);

        $agentBouake = User::factory()->create([
            'role' => 'city_agent',
            'city_id' => $cityBouake->id,
        ]);

        $this->actingAs($cityManager);

        $this->assertFalse(AdministratorResource::canEdit($agentBouake));
        $this->assertFalse(AdministratorResource::canDelete($agentBouake));

        // Le City Manager ne peut pas charger la page d'édition d'un admin hors de sa ville (scoping getEloquentQuery)
        $this->expectException(ModelNotFoundException::class);
        Livewire::test(EditAdministrator::class, ['record' => $agentBouake->getRouteKey()]);
    }

    public function test_city_manager_cannot_escalate_local_agent_to_super_admin_via_edit(): void
    {
        $cityDaloa = City::factory()->create(['name' => 'Daloa']);

        $cmHab = RoleHabilitation::where('slug', 'city_manager')->first();
        $cityManager = User::factory()->create([
            'role' => 'city_manager',
            'role_habilitation_id' => $cmHab?->id,
            'city_id' => $cityDaloa->id,
        ]);

        $agentDaloa = User::factory()->create([
            'name' => 'Agent Daloa',
            'email' => 'agent.daloa@golivreur.ci',
            'phone' => '0701020304',
            'role' => 'city_agent',
            'city_id' => $cityDaloa->id,
        ]);

        $this->actingAs($cityManager);

        $this->assertTrue(AdministratorResource::canEdit($agentDaloa));

        Livewire::test(EditAdministrator::class, ['record' => $agentDaloa->getRouteKey()])
            ->fillForm([
                'name' => 'Agent Daloa Modif',
                'email' => 'agent.daloa@golivreur.ci',
                'phone' => '0701020304',
                'role' => 'super_admin',
            ])
            ->call('save')
            ->assertForbidden();

        $this->assertEquals('city_agent', $agentDaloa->fresh()->role);
    }
}
