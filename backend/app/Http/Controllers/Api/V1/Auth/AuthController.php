<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmPasswordResetRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterDriverRequest;
use App\Http\Requests\Auth\RegisterMerchantRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\RequestPasswordResetRequest;
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
use App\Services\Auth\PasswordResetService;
use App\Services\FirebasePhoneAuthService;
use App\Services\SettingsService;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
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
        private readonly FirebasePhoneAuthService $firebasePhoneAuthService,
    ) {}

    /**
     * Étape 1 — Inscription : valide la disponibilité du numéro et prépare l'OTP SMS
     * (via Firebase Phone Auth si `use_firebase_otp=true`, sinon via passerelle SMS historique).
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

        // Mode Firebase Phone Auth : le mobile déclenche l'envoi du SMS réel via Firebase SDK
        if ($request->boolean('use_firebase_otp')) {
            return response()->json([
                'succes' => true,
                'message' => "Vérification SMS prête pour le {$this->masquerTelephone($phone)}.",
                'donnees' => [
                    'firebase_otp' => true,
                    'phone_e164' => "+225{$phone}",
                ],
            ], 200);
        }

        // Mode passerelle SMS historique (jamais loggé)
        $code = (string) random_int(1000, 9999);
        $otpRecord = OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'code_enc' => Crypt::encryptString($code),
            'purpose' => 'register',
            'expires_at' => now()->addMinutes(5),
        ]);

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
     * Étape 2 — Vérification OTP (Firebase ID Token ou code OTP historique) et création du compte.
     */
    public function verifierOtpInscription(VerifyOtpRequest $request): JsonResponse
    {
        $phone = (string) $request->input('phone');
        $otp = (string) ($request->input('otp') ?? $request->input('code'));
        $firebaseIdToken = trim((string) $request->input('firebase_id_token', ''));
        $meta = Cache::get("register_meta:{$phone}", []);
        $rawName = trim((string) ($request->input('name') ?: ($meta['name'] ?? '')));
        $name = $rawName !== '' ? $rawName : 'Client';

        $otpRecord = null;
        $verifiedViaFirebase = false;

        if ($firebaseIdToken !== '') {
            try {
                $verifiedPhone = $this->firebasePhoneAuthService->verifyIdToken($firebaseIdToken);
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'succes' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            if ($verifiedPhone !== $phone) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Le numéro vérifié par SMS ne correspond pas au numéro saisi.',
                ], 422);
            }

            $verifiedViaFirebase = true;
        } else {
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
        }

        if (User::where('phone', $phone)->exists()) {
            return response()->json([
                'succes' => false,
                'message' => 'Ce numéro est déjà associé à un compte.',
            ], 409);
        }

        // Supprimer l'OTP après validation réussie (CDC 11.4) et consommer les métadonnées
        if ($otpRecord !== null) {
            $otpRecord->delete();
        }
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

        if ($verifiedViaFirebase) {
            $this->activityLogService->log(
                userId: $user->id,
                action: 'client_registered_firebase_otp',
                entityType: 'user',
                entityId: $user->id,
                properties: ['ip' => $request->ip()],
            );
        }

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
     * Connexion — vérifie le compte et prépare l'OTP SMS au numéro existant.
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

        if ($request->boolean('use_firebase_otp')) {
            return response()->json([
                'succes' => true,
                'message' => "Vérification SMS prête pour le {$this->masquerTelephone($phone)}.",
                'donnees' => [
                    'firebase_otp' => true,
                    'phone_e164' => "+225{$phone}",
                ],
            ]);
        }

        $code = (string) random_int(1000, 9999);
        $otpRecord = OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'code_enc' => Crypt::encryptString($code),
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

        $existingUser = User::where('phone', (string) $validated['phone'])
            ->whereNull('deleted_at')
            ->first();

        if ($existingUser) {
            if ($existingUser->courier()->exists()) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Ce numéro de téléphone est déjà associé à un profil livreur.',
                    'errors' => ['phone' => ['Ce numéro de téléphone est déjà associé à un profil livreur.']],
                ], 422);
            }

            if (! $existingUser->password || ! Hash::check((string) $validated['password'], $existingUser->password)) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Ce numéro existe déjà. Saisissez le mot de passe de votre compte existant pour ajouter le profil livreur.',
                    'errors' => ['password' => ['Mot de passe du compte existant incorrect.']],
                ], 422);
            }
        }

        $courier = DB::transaction(function () use ($validated, $vehicleType, $existingUser): Courier {
            $user = $existingUser ?? User::create([
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
                    'multi_role_attached' => $existingUser !== null,
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
        $shopLat = isset($validated['lat']) && isset($validated['lng']) ? (float) $validated['lat'] : null;
        $shopLng = isset($validated['lat']) && isset($validated['lng']) ? (float) $validated['lng'] : null;
        if ($shopLat !== null && $shopLng !== null && ! $city->estDansLaVille($shopLat, $shopLng)) {
            return response()->json([
                'succes' => false,
                'message' => "La position GPS sélectionnée se situe hors de {$city->name}. Sélectionnez la ville correspondante ou ajustez votre position sur la carte.",
                'errors' => [
                    'lat' => ["Position GPS hors du périmètre de {$city->name}."],
                ],
            ], 422);
        }

        $businessName = trim((string) $validated['name']);
        $managerName = trim((string) ($validated['manager_name'] ?? ''));
        if ($managerName === '') {
            $managerName = $businessName;
        }

        $existingUser = User::where('phone', (string) $validated['phone'])
            ->whereNull('deleted_at')
            ->first();

        if ($existingUser) {
            if ($existingUser->merchant()->exists()) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Ce numéro de téléphone est déjà associé à un commerce partenaire.',
                    'errors' => ['phone' => ['Ce numéro de téléphone est déjà associé à un commerce partenaire.']],
                ], 422);
            }

            if (! $existingUser->password || ! Hash::check((string) $validated['password'], $existingUser->password)) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Ce numéro existe déjà. Saisissez le mot de passe de votre compte existant pour ajouter votre commerce.',
                    'errors' => ['password' => ['Mot de passe du compte existant incorrect.']],
                ], 422);
            }
        }

        $merchant = DB::transaction(function () use ($validated, $city, $businessName, $managerName, $categorySlug, $existingUser, $shopLat, $shopLng): Merchant {
            $user = $existingUser ?? User::create([
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
                'lat' => $shopLat ?? $city->center_lat ?? 6.8770,
                'lng' => $shopLng ?? $city->center_lng ?? -6.4502,
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
                    'multi_role_attached' => $existingUser !== null,
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
            'app_type' => 'nullable|string|in:client,driver,business',
        ]);

        $appType = isset($validated['app_type']) ? strtolower((string) $validated['app_type']) : null;

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
                'message' => 'Identifiants incorrects.',
            ], 401);
        }

        $passwordMatches = $user->password && Hash::check($password, $user->password);

        if (! $passwordMatches) {
            return response()->json([
                'succes' => false,
                'message' => 'Mot de passe ou identifiant incorrect.',
            ], 422);
        }

        $user->load(['city', 'merchant', 'courier']);

        // Vérification stricte du rôle attendu par l'application mobile (client | driver | business)
        if ($appType !== null) {
            if ($appType === 'driver') {
                $courier = $user->courier;
                if ($courier && ! $courier->is_active) {
                    $isPendingAgency = $courier->activated_at === null;

                    return response()->json([
                        'succes' => false,
                        'message' => $isPendingAgency
                            ? 'Votre dossier livreur est en attente de validation. Passez en agence GoLivreur pour vérifier vos pièces et activer votre compte.'
                            : 'Votre compte livreur est actuellement désactivé. Contactez l\'agence GoLivreur.',
                    ], 403);
                }

                if (! $user->hasRole('driver')) {
                    return response()->json([
                        'succes' => false,
                        'code' => 'ROLE_MISSING',
                        'required_role' => 'driver',
                        'user_roles' => $user->effectiveRoles(),
                        'suggested_action' => 'register_driver',
                        'message' => 'Ce compte ne dispose pas du profil Livreur. Inscrivez-vous comme livreur ou connectez-vous sur l\'application adaptée à votre profil.',
                    ], 403);
                }
            } elseif ($appType === 'business') {
                $merchant = $user->merchant;
                if ($merchant && ! $merchant->is_active) {
                    $isPendingAgency = $merchant->activated_at === null;

                    return response()->json([
                        'succes' => false,
                        'message' => $isPendingAgency
                            ? 'Votre dossier commerçant est en attente de validation. Passez en agence GoLivreur ou avec un agent terrain pour compléter votre dossier et activer votre compte.'
                            : 'Votre compte commerçant est actuellement désactivé. Contactez l\'agence GoLivreur.',
                    ], 403);
                }

                if (! $user->hasRole('merchant')) {
                    return response()->json([
                        'succes' => false,
                        'code' => 'ROLE_MISSING',
                        'required_role' => 'merchant',
                        'user_roles' => $user->effectiveRoles(),
                        'suggested_action' => 'register_merchant',
                        'message' => 'Ce compte ne dispose pas du profil Commerçant. Inscrivez votre commerce ou ouvrez l\'application adaptée à votre profil.',
                    ], 403);
                }
            } elseif ($appType === 'client' && ! $user->hasRole('client')) {
                return response()->json([
                    'succes' => false,
                    'code' => 'ROLE_MISSING',
                    'required_role' => 'client',
                    'user_roles' => $user->effectiveRoles(),
                    'suggested_action' => 'register_client',
                    'message' => 'Ce compte ne dispose pas du profil Client. Ouvrez GL Driver ou GL Business, ou créez un profil Client.',
                ], 403);
            }
        }

        if (in_array($user->role, ['driver', 'courier'], true) && ($appType === null || $appType === 'driver')) {
            $courier = $user->courier;
            if (! $user->is_active || ($courier && ! $courier->is_active)) {
                $isPendingAgency = $courier && $courier->activated_at === null;

                return response()->json([
                    'succes' => false,
                    'message' => $isPendingAgency
                        ? 'Votre dossier livreur est en attente de validation. Passez en agence GoLivreur pour vérifier vos pièces et activer votre compte.'
                        : 'Votre compte livreur est actuellement désactivé. Contactez l\'agence GoLivreur.',
                ], 403);
            }
        } elseif ($user->role === 'merchant' && ($appType === null || $appType === 'business')) {
            $merchant = $user->merchant;
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

        if (in_array($user->role, ['merchant', 'driver', 'courier'], true) || in_array($appType, ['business', 'driver'], true)) {
            $this->revoquerSessionPrecedente($user);
        }

        $token = JWTAuth::fromUser($user);
        if (in_array($user->role, ['merchant', 'driver', 'courier'], true) || in_array($appType, ['business', 'driver'], true)) {
            $jti = (string) JWTAuth::setToken($token)->getPayload()->get('jti');
            $user->update(['session_token' => $jti]);
        } elseif ($user->session_token !== null) {
            $user->update(['session_token' => null]);
        }

        $mustChangePassword = $user->mustChangePassword();

        return response()->json([
            'succes' => true,
            'message' => 'Connexion réussie.',
            'donnees' => [
                'access_token' => $token,
                'refresh_token' => $this->genererRefreshToken($user),
                'token_type' => 'bearer',
                'expires_in' => (int) config('jwt.ttl', 20) * 60,
                'must_change_password' => $mustChangePassword,
                'user' => $this->formatUserPayload($user),
            ],
        ]);
    }

    /**
     * Vérification OTP connexion (Firebase ID Token ou code OTP historique) et génération du JWT.
     */
    public function verifierOtpConnexion(VerifyOtpRequest $request): JsonResponse
    {
        $phone = (string) $request->input('phone');
        $otp = (string) ($request->input('otp') ?? $request->input('code'));
        $firebaseIdToken = trim((string) $request->input('firebase_id_token', ''));
        $deviceToken = $request->input('device_token');

        $otpRecord = null;
        $verifiedViaFirebase = false;

        if ($firebaseIdToken !== '') {
            try {
                $verifiedPhone = $this->firebasePhoneAuthService->verifyIdToken($firebaseIdToken);
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'succes' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            if ($verifiedPhone !== $phone) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Le numéro vérifié par SMS ne correspond pas au numéro saisi.',
                ], 422);
            }

            $verifiedViaFirebase = true;
        } else {
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

        if ($otpRecord !== null) {
            $otpRecord->delete();
        }
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
        } elseif ($user->session_token !== null) {
            $user->update(['session_token' => null]);
        }

        $this->activityLogService->log(
            userId: $user->id,
            action: 'user_login',
            properties: [
                'ip' => $request->ip(),
                'auth_method' => $verifiedViaFirebase ? 'firebase_phone_otp' : 'legacy_otp',
            ],
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
     * Mot de passe oublié — Étape 1 : demande ou renvoi d'un OTP de réinitialisation.
     */
    public function requestPasswordReset(
        RequestPasswordResetRequest $request,
        PasswordResetService $passwordResetService,
    ): JsonResponse {
        $result = $passwordResetService->requestReset(
            phone: (string) $request->validated('phone'),
            useFirebaseOtp: $request->boolean('use_firebase_otp'),
            ip: $request->ip(),
        );

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * Mot de passe oublié — Étape 2 : vérifie l'OTP (max 2 essais, 5 min) et réinitialise le mot de passe.
     */
    public function confirmPasswordReset(
        ConfirmPasswordResetRequest $request,
        PasswordResetService $passwordResetService,
    ): JsonResponse {
        $validated = $request->validated();

        $result = $passwordResetService->confirmReset(
            phone: (string) $validated['phone'],
            newPassword: (string) $validated['password'],
            code: isset($validated['code']) ? (string) $validated['code'] : null,
            firebaseIdToken: isset($validated['firebase_id_token']) ? (string) $validated['firebase_id_token'] : null,
            ip: $request->ip(),
        );

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * Mot de passe oublié — Enregistre côté serveur un échec OTP détecté sur le SDK Firebase mobile
     * afin de garantir le blocage strict après 2 essais.
     */
    public function recordPasswordResetFailure(
        RequestPasswordResetRequest $request,
        PasswordResetService $passwordResetService,
    ): JsonResponse {
        $result = $passwordResetService->recordFailedAttempt(
            phone: (string) $request->validated('phone'),
            ip: $request->ip(),
        );

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * Renouvellement du JWT avec le refresh token (CDC 11.4 & ADR-002).
     */
    public function refresh(Request $request): JsonResponse
    {
        $rawToken = (string) ($request->input('refresh_token') ?: $request->bearerToken());

        if (! $rawToken) {
            return response()->json([
                'succes' => false,
                'message' => 'Token de renouvellement manquant.',
            ], 401);
        }

        // Cas 1 : Refresh token opaque stocké en cache (émis au login/register/SSO) avec rotation & détection de réutilisation
        $cached = Cache::get("refresh_token:{$rawToken}");
        if ($cached !== null) {
            $userId = is_array($cached) ? (int) ($cached['user_id'] ?? 0) : (int) $cached;
            $familyId = is_array($cached) ? ($cached['family_id'] ?? null) : null;
            $alreadyUsed = is_array($cached) && ! empty($cached['used']);

            if ($alreadyUsed) {
                // Réutilisation suspecte d'un refresh token déjà tourné : révoquer toute la famille !
                if (is_string($familyId) && $familyId !== '') {
                    $activeFamilyToken = Cache::pull("refresh_family_active:{$familyId}");
                    if (is_string($activeFamilyToken) && $activeFamilyToken !== '') {
                        Cache::forget("refresh_token:{$activeFamilyToken}");
                    }
                }
                if ($userId > 0) {
                    User::whereKey($userId)->update(['session_token' => null]);
                    $this->activityLogService->log(
                        userId: $userId,
                        action: 'refresh_token_reuse_detected',
                        entityType: 'user',
                        entityId: $userId,
                        properties: ['family_revoked' => $familyId !== null],
                        severity: 'critical',
                    );
                }

                return response()->json([
                    'succes' => false,
                    'message' => 'Session révoquée par sécurité (jeton déjà utilisé). Veuillez vous reconnecter.',
                ], 401);
            }

            $user = User::find($userId);
            if (! $user || ! $user->is_active) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Votre compte est désactivé ou suspendu.',
                ], 403);
            }

            // Rotation stricte : marque l'ancien token comme consommé (tombstone pour détecter le replay)
            $ttlMinutes = max(60, (int) config('jwt.refresh_ttl', 10080));
            if (is_string($familyId) && $familyId !== '') {
                Cache::put(
                    "refresh_token:{$rawToken}",
                    ['user_id' => $user->id, 'family_id' => $familyId, 'used' => true],
                    now()->addMinutes($ttlMinutes),
                );
            } else {
                Cache::forget("refresh_token:{$rawToken}");
            }

            $newRefreshToken = self::issueRotatingRefreshToken($user, is_string($familyId) ? $familyId : null);
            $newAccessToken = JWTAuth::fromUser($user);

            if (in_array($user->role, ['merchant', 'driver', 'courier'], true)) {
                $jti = (string) JWTAuth::setToken($newAccessToken)->getPayload()->get('jti');
                $user->update(['session_token' => $jti]);
            }

            return response()->json([
                'succes' => true,
                'donnees' => [
                    'access_token' => $newAccessToken,
                    'refresh_token' => $newRefreshToken,
                    'token_type' => 'bearer',
                    'expires_in' => (int) config('jwt.ttl', 20) * 60,
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
            'roles' => $user->effectiveRoles(),
            'city_id' => $user->city_id,
            'city_name' => $user->city?->name,
            'support_phone' => app(SettingsService::class)->getCitySupportPhone(
                $user->city_id !== null ? (int) $user->city_id : null,
            ),
            'default_address' => $user->default_address,
            'language' => $user->language ?? 'fr',
            'notifications_enabled' => (bool) ($user->notifications_enabled ?? true),
            'photo_url' => $user->photo_url,
            'is_active' => (bool) ($user->is_active ?? true),
            'must_change_password' => $user->mustChangePassword(),
            'pin_reset_required_at' => $user->pinResetRequiredAt(),
        ];
    }

    private function masquerTelephone(string $phone): string
    {
        return substr($phone, 0, 2).'****'.substr($phone, -3);
    }

    private function genererRefreshToken(User $user): string
    {
        return self::issueRotatingRefreshToken($user);
    }

    /**
     * Génère un refresh token opaque rattaché à une famille de rotation (détection de rejeu).
     */
    public static function issueRotatingRefreshToken(User $user, ?string $familyId = null): string
    {
        $ttlMinutes = max(60, (int) config('jwt.refresh_ttl', 10080));
        $expiresAt = now()->addMinutes($ttlMinutes);
        $family = $familyId ?: Str::uuid()->toString();
        $token = Str::random(64);

        Cache::put("refresh_token_user:{$user->id}", ['token' => $token, 'family_id' => $family], $expiresAt);
        Cache::put("refresh_family_active:{$family}", $token, $expiresAt);
        Cache::put(
            "refresh_token:{$token}",
            ['user_id' => $user->id, 'family_id' => $family, 'used' => false],
            $expiresAt,
        );

        return $token;
    }

    /**
     * Révoque immédiatement le refresh token actif d'un utilisateur.
     */
    public static function revokeUserRefreshTokens(int $userId): void
    {
        $meta = Cache::pull("refresh_token_user:{$userId}");
        if (is_string($meta) && $meta !== '') {
            Cache::forget("refresh_token:{$meta}");
        } elseif (is_array($meta)) {
            if (! empty($meta['token']) && is_string($meta['token'])) {
                Cache::forget("refresh_token:{$meta['token']}");
            }
            if (! empty($meta['family_id']) && is_string($meta['family_id'])) {
                $active = Cache::pull("refresh_family_active:{$meta['family_id']}");
                if (is_string($active) && $active !== '') {
                    Cache::forget("refresh_token:{$active}");
                }
            }
        }
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
