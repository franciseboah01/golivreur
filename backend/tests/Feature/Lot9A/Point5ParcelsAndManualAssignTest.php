<?php

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\ParcelResource\Pages\ListParcels;
use App\Models\ActivityLog;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\Refund;
use App\Models\RoleHabilitation;
use App\Models\User;
use Database\Seeders\RoleHabilitationSeeder;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tymon\JWTAuth\Facades\JWTAuth;

it('Point 5a : client peut annuler un colis avant picked_up, libere/notifie le livreur, cree remboursement idempotent si paye en ligne, bloque apres picked_up et acces croise 403', function () {
    $city = City::factory()->create(['name' => 'Daloa']);
    $sender = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $otherClient = User::factory()->create(['role' => 'client', 'city_id' => $city->id]);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id]);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $city->id, 'is_active' => true]);

    $parcel = Parcel::factory()->create([
        'sender_id' => $sender->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_ACCEPTED,
        'payment_method' => 'orange_money',
        'payment_status' => 'paid',
        'shipping_fee' => 1500,
        'insurance_amount' => 500,
    ]);

    // Autre client -> 403
    $otherToken = JWTAuth::fromUser($otherClient);
    $this->withHeader('Authorization', "Bearer {$otherToken}")
        ->postJson("/api/v1/client/parcels/{$parcel->id}/cancel")
        ->assertStatus(403);

    // Expéditeur annule -> 200, statut cancelled, livreur libéré et notifié, remboursement créé
    Auth::forgetGuards();
    $this->app->forgetScopedInstances();
    JWTAuth::unsetToken();
    $senderToken = JWTAuth::fromUser($sender);

    $this->withHeader('Authorization', "Bearer {$senderToken}")
        ->postJson("/api/v1/client/parcels/{$parcel->id}/cancel")
        ->assertOk()
        ->assertJsonPath('succes', true)
        ->assertJsonPath('donnees.status', Parcel::STATUS_CANCELLED);

    $parcel->refresh();
    expect($parcel->status)->toBe(Parcel::STATUS_CANCELLED)
        ->and($parcel->courier_id)->toBeNull()
        ->and($parcel->cancelled_at)->not->toBeNull();

    // Livreur notifié
    expect(Notification::where('user_id', $driverUser->id)->where('type', 'parcel_cancelled')->exists())->toBeTrue();

    // Demande de remboursement idempotente créée
    expect(Refund::where('parcel_id', $parcel->id)->count())->toBe(1);

    // Colis déjà ramassé (picked_up) -> 422
    $pickedUpParcel = Parcel::factory()->create([
        'sender_id' => $sender->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_PICKED_UP,
    ]);

    $this->withHeader('Authorization', "Bearer {$senderToken}")
        ->postJson("/api/v1/client/parcels/{$pickedUpParcel->id}/cancel")
        ->assertStatus(422);
});

it('Point 5b/c : assignation manuelle Filament met a jour statut, reset tentatives, resout alerte support, notifie livreur+client et parite Resource auto/manuel', function () {
    $this->seed(RoleHabilitationSeeder::class);

    $city = City::factory()->create(['name' => 'Daloa']);
    $superAdmin = User::factory()->create([
        'role' => 'super_admin',
        'role_habilitation_id' => RoleHabilitation::where('slug', 'super_admin')->value('id'),
        'city_id' => null,
    ]);
    $sender = User::factory()->create(['role' => 'client', 'city_id' => $city->id, 'phone' => '0701111111']);
    $driverUser = User::factory()->create(['role' => 'driver', 'city_id' => $city->id, 'phone' => '0703222222']);
    $courier = Courier::factory()->create(['user_id' => $driverUser->id, 'city_id' => $city->id, 'is_active' => true]);

    $parcelManual = Parcel::factory()->create([
        'sender_id' => $sender->id,
        'courier_id' => null,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_WAITING,
        'assignment_attempts' => 3,
        'payment_method' => 'cash',
        'shipping_fee' => 1200,
    ]);

    // Alerte support non lue existante
    $alert = Notification::create([
        'user_id' => $superAdmin->id,
        'type' => 'admin_no_driver_alert',
        'title' => 'Aucun livreur',
        'body' => "Colis #{$parcelManual->id} sans livreur",
        'data' => ['parcel_id' => $parcelManual->id, 'type' => 'gocolis'],
        'is_read' => false,
    ]);

    // Assignation manuelle Filament
    $this->actingAs($superAdmin);
    Livewire::test(ListParcels::class)
        ->callTableAction('reassignCourier', $parcelManual, data: ['courier_id' => $courier->id])
        ->assertHasNoTableActionErrors();

    $parcelManual->refresh();
    expect($parcelManual->courier_id)->toBe($courier->id)
        ->and($parcelManual->status)->toBe(Parcel::STATUS_ACCEPTED)
        ->and((int) $parcelManual->assignment_attempts)->toBe(0);

    // Alerte support résolue (marquée lue)
    expect($alert->fresh()->is_read)->toBeTrue();

    // Livreur ET client notifiés
    expect(Notification::where('user_id', $driverUser->id)->where('type', 'parcel_assigned')->exists())->toBeTrue();
    expect(Notification::where('user_id', $sender->id)->exists())->toBeTrue();
    expect(ActivityLog::where('action', 'parcel_assigned_manually_by_admin')->where('entity_id', $parcelManual->id)->where('user_id', $superAdmin->id)->exists())->toBeTrue();

    // Parité du DriverCourseResource entre colis assigné manuellement et colis assigné automatiquement
    $parcelAuto = Parcel::factory()->create([
        'sender_id' => $sender->id,
        'courier_id' => $courier->id,
        'city_id' => $city->id,
        'status' => Parcel::STATUS_ACCEPTED,
        'payment_method' => 'cash',
        'shipping_fee' => 1200,
    ]);

    Auth::forgetGuards();
    $this->app->forgetScopedInstances();
    JWTAuth::unsetToken();
    $driverToken = JWTAuth::fromUser($driverUser);

    $coursesRes = $this->withHeader('Authorization', "Bearer {$driverToken}")
        ->getJson('/api/v1/driver/courses')
        ->assertOk();

    $parcelsPayload = collect($coursesRes->json('donnees.parcels'));
    $manualDto = $parcelsPayload->firstWhere('id', $parcelManual->id);
    $autoDto = $parcelsPayload->firstWhere('id', $parcelAuto->id);

    expect($manualDto)->not->toBeNull()
        ->and($autoDto)->not->toBeNull()
        ->and(array_keys($manualDto))->toBe(array_keys($autoDto))
        ->and($manualDto['course_type'])->toBe('gocolis')
        ->and($manualDto['pickup_contact_phone'])->toBe('0701111111')
        ->and($manualDto['recipient_phone'])->toBe($parcelManual->recipient_phone)
        ->and((int) $manualDto['amount_to_collect'])->toBe(1200);
});
