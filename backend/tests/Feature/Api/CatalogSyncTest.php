<?php

use App\Models\City;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Models\User;

it('expose les noms, emojis et logos des catégories via l\'API client', function () {
    MerchantCategory::create([
        'name'      => 'Glacier Artisanal',
        'slug'      => 'glacier',
        'emoji'     => '🍨',
        'logo_path' => 'categories/logos/glacier.png',
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/client/categories');

    $response->assertOk()
        ->assertJson(['succes' => true]);

    $glacier = collect($response->json('donnees'))->firstWhere('slug', 'glacier');
    expect($glacier)->not->toBeNull();
    expect($glacier['name'])->toBe('Glacier Artisanal');
    expect($glacier['emoji'])->toBe('🍨');
    expect($glacier['logo_url'])->toContain('storage/categories/logos/glacier.png');
});

it('expose les noms, emojis, logo_url et photo_url des commerces via l\'API client', function () {
    $city = City::factory()->create();
    $owner = User::factory()->create(['role' => 'merchant', 'city_id' => $city->id]);

    MerchantCategory::firstOrCreate(
        ['slug' => 'patisserie_fine'],
        ['name' => 'Pâtisserie Fine', 'emoji' => '🍰', 'is_active' => true]
    );

    Merchant::create([
        'user_id'           => $owner->id,
        'city_id'           => $city->id,
        'name'              => 'Maison Délice Daloa',
        'category'          => 'patisserie_fine',
        'emoji'             => '🧁',
        'logo_path'         => 'commerces/logos/delice.png',
        'photo_path'        => 'commerces/vitrine-delice.jpg',
        'address'           => 'Quartier Lobia, Daloa',
        'lat'               => 6.88,
        'lng'               => -6.45,
        'prep_time_minutes' => 15,
        'is_active'         => true,
        'is_open'           => true,
    ]);

    $response = $this->getJson("/api/v1/client/merchants?city_id={$city->id}");

    $response->assertOk()
        ->assertJson(['succes' => true]);

    $merchant = collect($response->json('donnees'))->firstWhere('name', 'Maison Délice Daloa');
    expect($merchant)->not->toBeNull();
    expect($merchant['emoji'])->toBe('🧁');
    expect($merchant['category_name'])->toBe('Pâtisserie Fine');
    expect($merchant['logo_url'])->toContain('storage/commerces/logos/delice.png');
    expect($merchant['photo_url'])->toContain('storage/commerces/vitrine-delice.jpg');
});

