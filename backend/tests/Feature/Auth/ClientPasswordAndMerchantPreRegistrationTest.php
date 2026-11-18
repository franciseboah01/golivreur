<?php

namespace Tests\Feature\Auth;

use App\Models\City;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Models\OtpCode;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClientPasswordAndMerchantPreRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private City $city;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = City::factory()->create([
            'name' => 'Daloa',
            'is_active' => true,
        ]);

        MerchantCategory::firstOrCreate(
            ['slug' => 'maquis'],
            [
                'name' => 'Maquis & Grillades',
                'emoji' => '🍗',
                'is_active' => true,
            ],
        );
    }

    public function test_client_registers_with_password_and_otp_then_logs_in_with_password_without_sms(): void
    {
        $registerResponse = $this->postJson('/api/v1/auth/register', [
            'name' => 'Awa Koné',
            'phone' => '+225 07 09 11 22 33',
            'password' => 'ClientSecret2026',
            'city_id' => $this->city->id,
        ]);

        $registerResponse->assertOk()
            ->assertJsonPath('succes', true);

        // Simuler un code OTP connu pour la vérification du numéro à l'inscription
        OtpCode::where('phone', '0709112233')->delete();
        OtpCode::create([
            'phone' => '0709112233',
            'code_hash' => Hash::make('4829'),
            'purpose' => 'register',
            'expires_at' => now()->addMinutes(5),
        ]);

        $verifyResponse = $this->postJson('/api/v1/auth/verify-register', [
            'phone' => '0709112233',
            'code' => '4829',
        ]);

        $verifyResponse->assertCreated()
            ->assertJsonPath('succes', true);

        $user = User::where('phone', '0709112233')->firstOrFail();
        $this->assertSame('client', $user->role);
        $this->assertTrue((bool) $user->is_active);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertTrue(Hash::check('ClientSecret2026', (string) $user->password));

        // Connexion directe par mot de passe (sans aucun envoi d'OTP SMS)
        $loginResponse = $this->postJson('/api/v1/auth/login-password', [
            'phone' => '0709112233',
            'password' => 'ClientSecret2026',
        ]);

        $loginResponse->assertOk()
            ->assertJsonPath('succes', true)
            ->assertJsonPath('donnees.user.role', 'client')
            ->assertJsonStructure([
                'succes',
                'donnees' => ['access_token', 'refresh_token', 'user'],
            ]);
    }

    public function test_merchant_can_pre_register_with_inactive_status_and_wallet(): void
    {
        $response = $this->postJson('/api/v1/auth/register-merchant', [
            'name' => 'Maquis Le Palmier',
            'manager_name' => 'Kouassi Adjoua',
            'phone' => '+225 07 08 44 55 66',
            'password' => 'MerchantPass2026',
            'city_id' => $this->city->id,
            'category' => 'maquis',
            'address' => 'Quartier Lobia, Daloa',
        ]);

        $response->assertCreated()
            ->assertJsonPath('succes', true)
            ->assertJsonPath(
                'message',
                'Pré-inscription enregistrée. Passez en agence GoLivreur ou avec un agent terrain pour valider et compléter votre dossier.'
            )
            ->assertJsonPath('donnees.status', 'pending_validation')
            ->assertJsonPath('donnees.is_active', false);

        $user = User::where('phone', '0708445566')->firstOrFail();
        $this->assertSame('merchant', $user->role);
        $this->assertFalse((bool) $user->is_active);
        $this->assertTrue(Hash::check('MerchantPass2026', (string) $user->password));

        $merchant = Merchant::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Maquis Le Palmier', $merchant->name);
        $this->assertSame('maquis', $merchant->category);
        $this->assertSame('Quartier Lobia, Daloa', $merchant->address);
        $this->assertFalse((bool) $merchant->is_active);
        $this->assertFalse((bool) $merchant->is_open);

        $this->assertTrue(
            Wallet::where('owner_type', 'merchant')
                ->where('owner_id', $merchant->id)
                ->exists()
        );
    }

    public function test_pre_registered_merchant_cannot_login_before_agency_or_field_validation_then_succeeds(): void
    {
        $this->postJson('/api/v1/auth/register-merchant', [
            'name' => 'Restaurant Chez Aya',
            'manager_name' => 'Aya Brou',
            'phone' => '0504778899',
            'password' => 'MonCommerce2026',
            'city_id' => $this->city->id,
            'category' => 'maquis',
            'address' => 'Quartier Commerce, Daloa',
        ])->assertCreated();

        $pendingResponse = $this->postJson('/api/v1/auth/login-password', [
            'phone' => '0504778899',
            'password' => 'MonCommerce2026',
        ]);

        $pendingResponse->assertStatus(403)
            ->assertJsonPath('succes', false)
            ->assertJsonPath(
                'message',
                'Votre dossier commerçant est en attente de validation. Passez en agence GoLivreur ou avec un agent terrain pour compléter votre dossier et activer votre compte.'
            );

        // Validation finale par l'agence ou l'agent terrain
        $user = User::where('phone', '0504778899')->firstOrFail();
        $merchant = Merchant::where('user_id', $user->id)->firstOrFail();
        $user->update(['is_active' => true]);
        $merchant->update([
            'is_active' => true,
            'activated_at' => now(),
        ]);

        $activeResponse = $this->postJson('/api/v1/auth/login-password', [
            'phone' => '0504778899',
            'password' => 'MonCommerce2026',
        ]);

        $activeResponse->assertOk()
            ->assertJsonPath('succes', true)
            ->assertJsonPath('donnees.user.role', 'merchant')
            ->assertJsonStructure([
                'succes',
                'donnees' => ['access_token', 'refresh_token', 'user'],
            ]);
    }
}
