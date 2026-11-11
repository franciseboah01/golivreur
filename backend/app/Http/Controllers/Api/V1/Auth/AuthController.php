<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Jobs\SendPushNotification;
use App\Models\City;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'purpose' => 'register',
            'expires_at' => now()->addMinutes(5),
        ]);

        // Mémoriser les informations d'inscription pour l'étape 2 (/verify-register)
        $pendingName = trim((string) $request->input('name', ''));
        $pendingCityId = $request->input('city_id');
        if ($pendingName !== '' || $pendingCityId !== null) {
            Cache::put("register_meta:{$phone}", [
                'name' => $pendingName,
                'city_id' => $pendingCityId,
            ], now()->addMinutes(15));
        }

        $this->smsService->envoyerOtp($phone, $code, 'inscription');

        $responseData = [
            'succes' => true,
            'message' => "Code envoyé au {$this->masquerTelephone($phone)}.",
        ];

        if (app()->environment('local', 'testing')) {
            $responseData['debug_otp'] = $code;
        }

        return response()->json($responseData, 200);
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

        $isMasterDevOtp = app()->environment('local', 'testing') && $otp === '1234';

        if (! $isMasterDevOtp && (! $otpRecord || ! Hash::check($otp, $otpRecord->code_hash))) {
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

        // Marquer l'OTP utilisé et consommer les métadonnées d'inscription
        if ($otpRecord) {
            $otpRecord->update(['is_used' => true]);
        }
        Cache::forget("register_meta:{$phone}");
        RateLimiter::clear("otp_register:{$phone}");

        $requestedCityId = $request->input('city_id') ?? ($meta['city_id'] ?? null);
        $validCityId = $requestedCityId && City::whereKey($requestedCityId)->exists()
            ? (int) $requestedCityId
            : null;

        // Créer l'utilisateur
        $user = User::create([
            'name' => $name,
            'phone' => $phone,
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
        RateLimiter::hit($rateLimitKey, 600);

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
        OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'purpose' => 'login',
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->smsService->envoyerOtp($phone, $code, 'connexion');

        $responseData = [
            'succes' => true,
            'message' => "Code envoyé au {$this->masquerTelephone($phone)}.",
        ];

        if (app()->environment('local', 'testing')) {
            $responseData['debug_otp'] = $code;
        }

        return response()->json($responseData);
    }

    /**
     * Connexion directe par mot de passe / identifiants (Gérants Commerces, Livreurs).
     */
    public function loginPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => 'required|string',
            'password' => 'required|string',
        ]);

        $phone = $validated['phone'];
        $password = $validated['password'];

        $user = User::where('phone', $phone)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $user) {
            return response()->json([
                'succes' => false,
                'message' => 'Numéro de téléphone non reconnu ou compte désactivé.',
            ], 404);
        }

        $isDev = app()->environment('local', 'testing');
        $validDevPasswords = ['123456', '1234', 'password', '1234-JK-01'];
        $passwordMatches = ($user->password && Hash::check($password, $user->password)) ||
                           ($isDev && in_array($password, $validDevPasswords));

        if (! $passwordMatches) {
            return response()->json([
                'succes' => false,
                'message' => 'Mot de passe ou identifiant incorrect.',
            ], 422);
        }

        if (in_array($user->role, ['merchant', 'driver'])) {
            $this->revoquerSessionPrecedente($user);
        }

        $token = JWTAuth::fromUser($user);
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

        $isMasterDevOtp = app()->environment('local', 'testing') && $otp === '1234';

        if (! $isMasterDevOtp && (! $otpRecord || ! Hash::check($otp, $otpRecord->code_hash))) {
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

        if ($otpRecord) {
            $otpRecord->update(['is_used' => true]);
        }
        RateLimiter::clear("otp_login:{$phone}");

        // Session unique pour merchants et drivers
        if (in_array($user->role, ['merchant', 'driver'])) {
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
     * Renouvellement du JWT avec le refresh token.
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $newToken = JWTAuth::refresh(JWTAuth::getToken());

            return response()->json([
                'succes' => true,
                'donnees' => [
                    'access_token' => $newToken,
                    'token_type' => 'bearer',
                    'expires_in' => config('jwt.ttl') * 60,
                ],
            ]);
        } catch (\Exception $e) {
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
            JWTAuth::invalidate(JWTAuth::getToken());
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
        // Refresh token stocké en cache Redis (7 jours)
        $token = Str::random(64);
        Cache::put("refresh_token:{$user->id}", $token, now()->addDays(7));

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
            $user->update(['session_token' => null]);
        }
    }
}
