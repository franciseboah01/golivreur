<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\ParcelResource\Pages\ListParcels;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Parcel;
use App\Models\RoleHabilitation;
use App\Models\User;
use Database\Seeders\RoleHabilitationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ParcelCodeUnlockSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleHabilitationSeeder::class);
    }

    public function test_unlock_sender_code_requires_client_called_checkbox_and_calls_service(): void
    {
        $city = City::factory()->create(['name' => 'Daloa']);
        $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);

        $parcel = Parcel::factory()->create([
            'city_id' => $city->id,
            'sender_id' => $client->id,
            'sender_code_locked' => true,
            'sender_code_attempts' => 3,
            'sender_code_unlocked_by' => null,
        ]);

        $supportHab = RoleHabilitation::where('slug', 'city_support')->first();
        $supportUser = User::factory()->create([
            'role' => 'city_support',
            'role_habilitation_id' => $supportHab?->id,
            'city_id' => $city->id,
        ]);

        $this->actingAs($supportUser);

        // 1. Tentative sans cocher la case -> échec validation, le code reste bloqué
        Livewire::test(ListParcels::class)
            ->callTableAction('unlockSenderCode', $parcel, [
                'client_called' => false,
            ])
            ->assertHasTableActionErrors(['client_called']);

        $this->assertTrue((bool) $parcel->fresh()->sender_code_locked);

        // 2. Déblocage valide avec attestation d'appel client
        Livewire::test(ListParcels::class)
            ->callTableAction('unlockSenderCode', $parcel, [
                'client_called' => true,
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $parcel->fresh();
        $this->assertFalse((bool) $fresh->sender_code_locked);
        $this->assertEquals(0, $fresh->sender_code_attempts);
        $this->assertEquals($supportUser->id, $fresh->sender_code_unlocked_by);

        // 3. Vérification de la trace activity_logs
        $log = ActivityLog::where('action', 'parcel_sender_code_unlocked')
            ->where('entity_id', $parcel->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($supportUser->id, $log->user_id);
        $this->assertTrue($log->properties['called_client']);
    }

    public function test_unlock_recipient_code_requires_client_called_checkbox_and_calls_service(): void
    {
        $city = City::factory()->create(['name' => 'Daloa']);
        $client = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);

        $parcel = Parcel::factory()->create([
            'city_id' => $city->id,
            'sender_id' => $client->id,
            'recipient_code_locked' => true,
            'recipient_code_attempts' => 3,
            'recipient_code_unlocked_by' => null,
        ]);

        $supportHab = RoleHabilitation::where('slug', 'city_support')->first();
        $supportUser = User::factory()->create([
            'role' => 'city_support',
            'role_habilitation_id' => $supportHab?->id,
            'city_id' => $city->id,
        ]);

        $this->actingAs($supportUser);

        // 1. Sans cocher la case
        Livewire::test(ListParcels::class)
            ->callTableAction('unlockRecipientCode', $parcel, [
                'client_called' => false,
            ])
            ->assertHasTableActionErrors(['client_called']);

        $this->assertTrue((bool) $parcel->fresh()->recipient_code_locked);

        // 2. Avec case cochée
        Livewire::test(ListParcels::class)
            ->callTableAction('unlockRecipientCode', $parcel, [
                'client_called' => true,
            ])
            ->assertHasNoTableActionErrors();

        $fresh = $parcel->fresh();
        $this->assertFalse((bool) $fresh->recipient_code_locked);
        $this->assertEquals(0, $fresh->recipient_code_attempts);
        $this->assertEquals($supportUser->id, $fresh->recipient_code_unlocked_by);

        // 3. Trace activity_logs
        $log = ActivityLog::where('action', 'parcel_recipient_code_unlocked')
            ->where('entity_id', $parcel->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($supportUser->id, $log->user_id);
        $this->assertTrue($log->properties['called_client']);
    }
}
