<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterDriverRequest;
use App\Http\Requests\Auth\RegisterMerchantRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Jobs\SendPushNotification;
use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\MerchantCategory;
use App\Models\OtpCode;
use App\Models\User;
use App\Models\VehicleType;
use App\Models\Wallet;
use App\Services\ActivityLogService;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function __construct(
        private readonly SmsService $smsService,
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * Étape 1 — Inscription : envoie un OTP SMS au numéro fourni.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');

        // Rate limit : 3 tentatives par numéro par 10 minutes
        $rateLimitKey = "otp_register:{$phone}";
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $secondes = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'succes' => false,
                'message' => 'Trop de tentatives. Réessayez dans '.ceil($secondes / 60).' minute(s).',
            ], 429);
        }
        RateLimiter::hit($rateLimitKey, 3600); // Blocage 1h après 3 échecs

        // Vérifier que le numéro n'est pas déjà utilisé
        if (User::where('phone', $phone)->exists()) {
            return response()->json([
                'succes' => false,
                'message' => 'Ce numéro est déjà associé à un compte.',
            ], 409);
        }

        // Générer et envoyer l'OTP (jamais loggé)
        $code = (string) random_int(1000, 9999);
        $otpRecord = OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'purpose' => 'register',
            'expires_at' => now()->addMinutes(5),
        ]);

        // Mémoriser les informations d'inscription pour l'étape 2 (/verify-register)
        $pendingName = trim((string) $request->input('name', ''));
        $pendingCityId = $request->input('city_id');
        $rawPassword = $request->input('password');
        $pendingPasswordHash = is_string($rawPassword) && $rawPassword !== ''
            ? Hash::make($rawPassword)
            : null;
        if ($pendingName !== '' || $pendingCityId !== null || $pendingPasswordHash !== null) {
            Cache::put("register_meta:{$phone}", [
                'name' => $pendingName,
                'city_id' => $pendingCityId,
                'password_hash' => $pendingPasswordHash,
            ], now()->addMinutes(15));
        }

        if (! $this->smsService->envoyerOtp($phone, $code, 'inscription')) {
            $otpRecord->delete();

            return response()->json([
                'succes' => false,
                'message' => 'Impossible d\'envoyer le code SMS pour le moment. Veuillez réessayer.',
            ], 503);
        }

        return response()->json([
            'succes' => true,
            'message' => "Code envoyé au {$this->masquerTelephone($phone)}.",
        ], 200);
    }

    /**
     * Étape 2 — Vérification OTP et création du compte.
     */
    public function verifierOtpInscription(VerifyOtpRequest $request): JsonResponse
    {
        $phone = (string) $request->input('phone');
        $otp = (string) ($request->input('otp') ?? $request->input('code'));
        $meta = Cache::get("register_meta:{$phone}", []);
        $rawName = trim((string) ($request->input('name') ?: ($meta['name'] ?? '')));
        $name = $rawName !== '' ? $rawName : 'Client';

        $otpRecord = OtpCode::where('phone', $phone)
            ->where('purpose', 'register')
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $otpRecord || ! Hash::check($otp, $otpRecord->code_hash)) {
            if ($otpRecord) {
                $otpRecord->increment('attempts');
                if ($otpRecord->attempts >= 5) {
                    $otpRecord->update(['is_used' => true]);

                    return response()->json([
                        'succes' => false,
                        'message' => 'Trop de tentatives de validation. Votre code a été invalidé. Veuillez demander un nouveau code.',
                    ], 429);
                }
            }

            return response()->json([
                'succes' => false,
                'message' => 'Code incorrect ou expiré.',
            ], 422);
        }

        if (User::where('phone', $phone)->exists()) {
            return response()->json([
                'succes' => false,
                'message' => 'Ce numéro est déjà associé à un compte.',
            ], 409);
        }

        // Supprimer l'OTP après validation réussie (CDC 11.4) et consommer les métadonnées
        $otpRecord->delete();
        Cache::forget("register_meta:{$phone}");
        RateLimiter::clear("otp_register:{$phone}");

        $requestedCityId = $request->input('city_id') ?? ($meta['city_id'] ?? null);
        $validCityId = $requestedCityId && City::whereKey($requestedCityId)->exists()
            ? (int) $requestedCityId
            : null;
        $rawPassword = $request->input('password');
        $passwordHash = is_string($rawPassword) && $rawPassword !== ''
            ? Hash::make($rawPassword)
            : ($meta['password_hash'] ?? null);

        // Créer l'utilisateur
        $user = User::create([
            'name' => $name,
            'phone' => $phone,
            'password' => $passwordHash,
            'role' => 'client',
            'city_id' => $validCityId,
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);
        $user->load('city');

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'succes' => true,
            'message' => 'Compte créé avec succès. Bienvenue sur GoLivreur !',
            'donnees' => [
                'access_token' => $token,
                'refresh_token' => $this->genererRefreshToken($user),
                'token_type' => 'bearer',
                'user' => $this->formatUserPayload($user),
            ],
        ], 201);
    }

    /**
     * Connexion — envoie un OTP au numéro existant.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');

        $rateLimitKey = "otp_login:{$phone}";
        $maxLoginAttempts = app()->environment('local', 'testing') ? 10 : 3;
        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxLoginAttempts)) {
            $secondes = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'succes' => false,
                'message' => 'Trop de tentatives. Réessayez dans '.ceil($secondes / 60).' minute(s).',
            ], 429);
        }
        RateLimiter::hit($rateLimitKey, 3600);

        $user = User::where('phone', $phone)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $user) {
            return response()->json([
                'succes' => false,
                'message' => 'Numéro non reconnu ou compte désactivé.',
            ], 404);
        }

        $code = (string) random_int(1000, 9999);
        $otpRecord = OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'purpose' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);

        if (! $this->smsService->envoyerOtp($phone, $code, 'connexion')) {
            $otpRecord->delete();

            return response()->json([
                'succes' => false,
                'message' => 'Impossible d\'envoyer le code SMS pour le moment. Veuillez réessayer.',
            ], 503);
        }

        return response()->json([
            'succes' => true,
            'message' => "Code envoyé au {$this->masquerTelephone($phone)}.",
        ]);
    }

    /**
     * Pré-inscription d'un candidat livreur depuis l'application GoLivreur Driver.
     * Crée le compte User (role='driver') et la fiche Courier avec is_active=false
     * en attente de vérification physique en agence (Validations en attente Filament).
     */
    public function registerDriver(RegisterDriverRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $vehicleType = strtolower(trim((string) $validated['vehicle_type']));

        if (
            VehicleType::where('is_active', true)->exists()
            && ! VehicleType::where('slug', $vehicleType)->where('is_active', true)->exists()
        ) {
            return response()->json([
                'succes' => false,
                'message' => 'Le type de véhicule sélectionné n\'est pas disponible.',
                'errors' => ['vehicle_type' => ['Le type de véhicule sélectionné n\'est pas disponible.']],
            ], 422);
        }

        $courier = DB::transaction(function () use ($validated, $vehicleType): Courier {
            $user = User::create([
                'name' => trim((string) $validated['name']),
                'phone' => (string) $validated['phone'],
                'password' => Hash::make((string) $validated['password']),
                'role' => 'driver',
                'city_id' => (int) $validated['city_id'],
                'is_active' => false,
            ]);

            $courier = Courier::create([
                'user_id' => $user->id,
                'city_id' => (int) $validated['city_id'],
                'vehicle_type' => $vehicleType,
                'vehicle_plate' => trim((string) $validated['vehicle_plate']),
                'is_active' => false,
                'is_online' => false,
                'current_lat' => null,
                'current_lng' => null,
                'location_updated_at' => null,
            ]);

            Wallet::firstOrCreate(
                ['owner_type' => 'courier', 'owner_id' => $courier->id],
                ['balance' => 0, 'total_earned' => 0, 'total_withdrawn' => 0],
            );

            $this->activityLogService->log(
                userId: $user->id,
                action: 'driver_pre_registered',
                entityType: 'courier',
                entityId: $courier->id,
                properties: [
                    'city_id' => $courier->city_id,
                    'vehicle_type' => $courier->vehicle_type,
                    'vehicle_plate' => $courier->vehicle_plate,
                ],
            );

            return $courier;
        });

        return response()->json([
            'succes' => true,
            'message' => 'Pré-inscription enregistrée. Passez en agence GoLivreur pour vérifier vos pièces et activer votre compte.',
            'donnees' => [
                'courier_id' => $courier->id,
                'status' => 'pending_validation',
                'is_active' => false,
            ],
        ], 201);
    }

    /**
     * Pré-inscription d'un commerce partenaire depuis l'application GoLivreur Business.
     * Crée le compte User (role='merchant') et la fiche Merchant avec is_active=false
     * en attente de validation finale en agence ou par un agent terrain.
     */
    public function registerMerchant(RegisterMerchantRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $categorySlug = strtolower(trim((string) $validated['category']));

        if (
            MerchantCategory::where('is_active', true)->exists()
            && ! MerchantCategory::where('slug', $categorySlug)->where('is_active', true)->exists()
        ) {
            return response()->json([
                'succes' => false,
                'message' => 'La catégorie sélectionnée n\'est pas disponible.',
                'errors' => ['category' => ['La catégorie sélectionnée n\'est pas disponible.']],
            ], 422);
        }

        $city = City::findOrFail((int) $validated['city_id']);
        $businessName = trim((string) $validated['name']);
        $managerName = trim((string) ($validated['manager_name'] ?? ''));
        if ($managerName === '') {
            $managerName = $businessName;
        }

        $merchant = DB::transaction(function () use ($validated, $city, $businessName, $managerName, $categorySlug): Merchant {
            $user = User::create([
                'name' => $managerName,
                'phone' => (string) $validated['phone'],
                'password' => Hash::make((string) $validated['password']),
                'role' => 'merchant',
                'city_id' => $city->id,
                'is_active' => false,
            ]);

            $merchant = Merchant::create([
                'user_id' => $user->id,
                'city_id' => $city->id,
                'name' => $businessName,
                'category' => $categorySlug,
                'address' => trim((string) $validated['address']),
                'lat' => $city->center_lat ?? 6.8770,
                'lng' => $city->center_lng ?? -6.4502,
                'prep_time_minutes' => 20,
                'is_open' => false,
                'is_active' => false,
                'commission_rate' => 15.0,
            ]);

            Wallet::firstOrCreate(
                ['owner_type' => 'merchant', 'owner_id' => $merchant->id],
                ['balance' => 0, 'total_earned' => 0, 'total_withdrawn' => 0],
            );

            $this->activityLogService->log(
                userId: $user->id,
                action: 'merchant_pre_registered',
                entityType: 'merchant',
                entityId: $merchant->id,
                properties: [
                    'city_id' => $merchant->city_id,
                    'category' => $merchant->category,
                    'address' => $merchant->address,
                ],
            );

            return $merchant;
        });

        return response()->json([
            'succes' => true,
            'message' => 'Pré-inscription enregistrée. Passez en agence GoLivreur ou avec un agent terrain pour valider et compléter votre dossier.',
            'donnees' => [
                'merchant_id' => $merchant->id,
                'status' => 'pending_validation',
                'is_active' => false,
            ],
        ], 201);
    }

    /**
     * Connexion directe par mot de passe / identifiants (Clients, Gérants Commerces, Livreurs).
     */
    public function loginPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string',
            'password' => 'required|string',
        ]);

        $rawPhone = preg_replace('/[\s\.\-\(\)]+/', '', trim((string) $validated['phone'])) ?? '';
        if (str_starts_with($rawPhone, '+225')) {
            $phone = substr($rawPhone, 4);
        } elseif (str_starts_with($rawPhone, '00225')) {
            $phone = substr($rawPhone, 5);
        } elseif (strlen($rawPhone) === 13 && str_starts_with($rawPhone, '225')) {
            $phone = substr($rawPhone, 3);
        } else {
            $phone = $rawPhone;
        }

        $password = $validated['password'];

        $candidatePhones = array_values(array_unique(array_filter([
            $phone,
            $rawPhone,
            $phone !== '' ? "+225{$phone}" : '',
        ])));

        $user = User::whereIn('phone', $candidatePhones)
            ->whereNull('deleted_at')
            ->first();

        if (! $user) {
            return response()->json([
                'succes' => false,
                'message' => 'Numéro de téléphone non reconnu ou compte désactivé.',
            ], 404);
        }

        $passwordMatches = $user->password && Hash::check($password, $user->password);

        if (! $passwordMatches) {
            return response()->json([
                'succes' => false,
                'message' => 'Mot de passe ou identifiant incorrect.',
            ], 422);
        }

        if (in_array($user->role, ['driver', 'courier'], true)) {
            $courier = Courier::where('user_id', $user->id)->first();
            if (! $user->is_active || ($courier && ! $courier->is_active)) {
                $isPendingAgency = $courier && $courier->activated_at === null;

                return response()->json([
                    'succes' => false,
                    'message' => $isPendingAgency
                        ? 'Votre dossier livreur est en attente de validation. Passez en agence GoLivreur pour vérifier vos pièces et activer votre compte.'
                        : 'Votre compte livreur est actuellement désactivé. Contactez l\'agence GoLivreur.',
                ], 403);
            }
        } elseif ($user->role === 'merchant') {
            $merchant = Merchant::where('user_id', $user->id)->first();
            if (! $user->is_active || ($merchant && ! $merchant->is_active)) {
                $isPendingAgency = $merchant && $merchant->activated_at === null;

                return response()->json([
                    'succes' => false,
                    'message' => $isPendingAgency
                        ? 'Votre dossier commerçant est en attente de validation. Passez en agence GoLivreur ou avec un agent terrain pour compléter votre dossier et activer votre compte.'
                        : 'Votre compte commerçant est actuellement désactivé. Contactez l\'agence GoLivreur.',
                ], 403);
            }
        } elseif (! $user->is_active) {
            return response()->json([
                'succes' => false,
                'message' => 'Numéro de téléphone non reconnu ou compte désactivé.',
            ], 403);
        }

        if (in_array($user->role, ['merchant', 'driver', 'courier'])) {
            $this->revoquerSessionPrecedente($user);
        }

        $token = JWTAuth::fromUser($user);
        if (in_array($user->role, ['merchant', 'driver', 'courier'])) {
            $jti = (string) JWTAuth::setToken($token)->getPayload()->get('jti');
            $user->update(['session_token' => $jti]);
        }
        $user->load('city');

        return response()->json([
            'succes' => true,
            'message' => 'Connexion réussie.',
            'donnees' => [
                'access_token' => $token,
                'refresh_token' => $this->genererRefreshToken($user),
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60,
                'user' => $this->formatUserPayload($user),
            ],
        ]);
    }

    /**
     * Vérification OTP connexion et génération du JWT.
     */
    public function verifierOtpConnexion(VerifyOtpRequest $request): JsonResponse
    {
        $phone = (string) $request->input('phone');
        $otp = (string) ($request->input('otp') ?? $request->input('code'));
        $deviceToken = $request->input('device_token');

        $otpRecord = OtpCode::where('phone', $phone)
            ->where('purpose', 'login')
            ->where('is_used', false)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $otpRecord || ! Hash::check($otp, $otpRecord->code_hash)) {
            if ($otpRecord) {
                $otpRecord->increment('attempts');
                if ($otpRecord->attempts >= 5) {
                    $otpRecord->update(['is_used' => true]);

                    return response()->json([
                        'succes' => false,
                        'message' => 'Trop de tentatives de validation. Votre code a été invalidé. Veuillez demander un nouveau code.',
                    ], 429);
                }
            }

            return response()->json([
                'succes' => false,
                'message' => 'Code incorrect ou expiré.',
            ], 422);
        }

        $user = User::where('phone', $phone)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $user) {
            return response()->json([
                'succes' => false,
                'message' => 'Numéro non reconnu ou compte désactivé.',
            ], 404);
        }

        $otpRecord->delete();
        RateLimiter::clear("otp_login:{$phone}");

        // Session unique pour merchants et drivers (CDC 9.2 & ADR-002)
        if (in_array($user->role, ['merchant', 'driver', 'courier'])) {
            $this->revoquerSessionPrecedente($user);
        }

        // Mettre à jour le device token FCM si fourni
        if ($deviceToken) {
            $user->update(['device_token' => $deviceToken]);
        }

        // Log IP tracking
        $user->update([
            'last_login_ip' => $request->ip(),
            'last_login_at' => now(),
        ]);
        $user->load('city');

        $token = JWTAuth::fromUser($user);
        if (in_array($user->role, ['merchant', 'driver', 'courier'])) {
            $jti = (string) JWTAuth::setToken($token)->getPayload()->get('jti');
            $user->update(['session_token' => $jti]);
        }

        $this->activityLogService->log(
            userId: $user->id,
            action: 'user_login',
            properties: ['ip' => $request->ip()],
        );

        return response()->json([
            'succes' => true,
            'message' => 'Connexion réussie.',
            'donnees' => [
                'access_token' => $token,
                'refresh_token' => $this->genererRefreshToken($user),
                'token_type' => 'bearer',
                'expires_in' => config('jwt.ttl') * 60,
                'user' => $this->formatUserPayload($user),
            ],
        ]);
    }

    /**
     * Renouvellement du JWT avec le refresh token (CDC 11.4 & ADR-002).
     */
    public function refresh(Request $request): JsonResponse
    {
        $rawToken = $request->bearerToken() ?: (string) $request->input('refresh_token');

        if (! $rawToken) {
            return response()->json([
                'succes' => false,
                'message' => 'Token de renouvellement manquant.',
            ], 401);
        }

        // Cas 1 : Refresh token opaque stocké en cache (émis au login/register)
        $userId = Cache::get("refresh_token:{$rawToken}");
        if ($userId) {
            $user = User::find($userId);
            if (! $user || ! $user->is_active) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Votre compte est désactivé ou suspendu.',
                ], 403);
            }

            // Rotation du refresh token : invalide l'ancien et en génère un nouveau
            Cache::forget("refresh_token:{$rawToken}");
            $newRefreshToken = $this->genererRefreshToken($user);
            $newAccessToken = JWTAuth::fromUser($user);

            // Pour merchant/driver, mettre à jour le session_token
            if (in_array($user->role, ['merchant', 'driver', 'courier'])) {
                $jti = (string) JWTAuth::setToken($newAccessToken)->getPayload()->get('jti');
                $user->update(['session_token' => $jti]);
            }

            return response()->json([
                'succes' => true,
                'donnees' => [
                    'access_token' => $newAccessToken,
                    'refresh_token' => $newRefreshToken,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl') * 60,
                ],
            ]);
        }

        // Cas 2 : JWT passé directement pour renouvellement
        try {
            $newToken = JWTAuth::setToken($rawToken)->refresh();
            $user = JWTAuth::setToken($newToken)->toUser();

            if (! $user instanceof User || ! $user->is_active) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Votre compte est désactivé ou suspendu.',
                ], 403);
            }

            $newRefreshToken = $this->genererRefreshToken($user);

            if (in_array($user->role, ['merchant', 'driver', 'courier'])) {
                $jti = (string) JWTAuth::setToken($newToken)->getPayload()->get('jti');
                $user->update(['session_token' => $jti]);
            }

            return response()->json([
                'succes' => true,
                'donnees' => [
                    'access_token' => $newToken,
                    'refresh_token' => $newRefreshToken,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl') * 60,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'succes' => false,
                'message' => 'Token invalide ou expiré. Veuillez vous reconnecter.',
            ], 401);
        }
    }

    /**
     * Déconnexion — invalide le token JWT.
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            if ($user = auth('api')->user()) {
                $user->update(['session_token' => null]);
            }
            JWTAuth::parseToken()->invalidate();
            auth('api')->logout();
        } catch (\Exception $e) {
            // Token déjà invalidé ou expiré
        }

        return response()->json([
            'succes' => true,
            'message' => 'Déconnexion réussie.',
        ]);
    }

    // ─── Méthodes privées ─────────────────────────────────────────────────────

    private function formatUserPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => $user->role,
            'city_id' => $user->city_id,
            'city_name' => $user->city?->name,
            'default_address' => $user->default_address,
            'language' => $user->language ?? 'fr',
            'notifications_enabled' => (bool) ($user->notifications_enabled ?? true),
            'photo_url' => $user->photo_url,
            'is_active' => (bool) ($user->is_active ?? true),
        ];
    }

    private function masquerTelephone(string $phone): string
    {
        return substr($phone, 0, 2).'****'.substr($phone, -3);
    }

    private function genererRefreshToken(User $user): string
    {
        // Refresh token opaque stocké en cache Redis (7 jours selon CDC 11.4)
        $token = Str::random(64);
        Cache::put("refresh_token_user:{$user->id}", $token, now()->addDays(7));
        Cache::put("refresh_token:{$token}", $user->id, now()->addDays(7));

        return $token;
    }

    private function revoquerSessionPrecedente(User $user): void
    {
        if ($user->session_token) {
            // Notifier l'ancien appareil via FCM
            if ($user->device_token) {
                SendPushNotification::dispatch(
                    $user->device_token,
                    'Déconnexion',
                    'Nouvelle connexion détectée sur votre compte GoLivreur.',
                    ['type' => 'forced_logout'],
                )->onQueue('default');
            }
        }
    }
}
