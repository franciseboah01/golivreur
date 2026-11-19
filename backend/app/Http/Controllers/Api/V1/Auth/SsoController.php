<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SupportAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/**
 * Contrôleur SSO Inter-Apps sécurisé (OAuth2 Authorization Code + PKCE S256).
 * Permet de basculer entre GL Client, GL Business et GL Driver sans ressaisir son mot de passe.
 * Aucune table supplémentaire : les codes à usage unique (TTL 60s) sont stockés en Cache.
 */
class SsoController extends Controller
{
    private const CODE_TTL_SECONDS = 60;

    private const APP_SCHEME_MAP = [
        'client' => 'golivreur-client://sso',
        'business' => 'golivreur-business://sso',
        'driver' => 'golivreur-driver://sso',
    ];

    private const APP_ROLE_MAP = [
        'client' => 'client',
        'business' => 'merchant',
        'driver' => 'driver',
    ];

    public function __construct(
        private readonly SupportAuditService $auditService,
    ) {}

    /**
     * Étape 1 — Génère un code d'autorisation à usage unique lié au code_challenge PKCE (S256).
     */
    public function authorizeSwitch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target_app' => ['required', 'string', 'in:client,business,driver'],
            'code_challenge' => ['required', 'string', 'min:43', 'max:128'],
            'code_challenge_method' => ['nullable', 'string', 'in:S256'],
        ]);

        /** @var User|null $user */
        $user = auth('api')->user();
        if (! $user || ! $user->is_active) {
            return response()->json([
                'succes' => false,
                'message' => 'Session invalide ou compte inactif.',
            ], 401);
        }

        $targetApp = (string) $validated['target_app'];
        $requiredRole = self::APP_ROLE_MAP[$targetApp];

        if (! $user->hasRole($requiredRole)) {
            $this->auditService->record(
                advisorId: $user->id,
                targetUserId: $user->id,
                action: 'sso_authorize_denied_role_missing',
                result: 'failure',
                context: ['target_app' => $targetApp, 'required_role' => $requiredRole],
                ipAddress: $request->ip(),
                severity: 'warning',
            );

            return response()->json([
                'succes' => false,
                'code' => 'ROLE_MISSING',
                'required_role' => $requiredRole,
                'user_roles' => $user->effectiveRoles(),
                'suggested_action' => match ($targetApp) {
                    'driver' => 'register_driver',
                    'business' => 'register_merchant',
                    default => 'register_client',
                },
                'message' => match ($targetApp) {
                    'driver' => 'Votre compte ne dispose pas encore du profil Livreur. Complétez votre inscription livreur pour utiliser GL Driver.',
                    'business' => 'Votre compte ne dispose pas encore d\'un commerce validé. Inscrivez votre commerce pour utiliser GL Business.',
                    default => 'Votre compte ne dispose pas du profil Client actif.',
                },
            ], 403);
        }

        $plainCode = Str::random(64);
        $codeHash = hash('sha256', $plainCode);

        Cache::put(
            "sso_auth_code:{$codeHash}",
            [
                'user_id' => $user->id,
                'target_app' => $targetApp,
                'code_challenge' => (string) $validated['code_challenge'],
                'issued_at' => now()->toIso8601String(),
            ],
            now()->addSeconds(self::CODE_TTL_SECONDS),
        );

        $this->auditService->record(
            advisorId: $user->id,
            targetUserId: $user->id,
            action: 'sso_code_issued',
            result: 'success',
            context: ['target_app' => $targetApp],
            ipAddress: $request->ip(),
        );

        $scheme = self::APP_SCHEME_MAP[$targetApp];

        return response()->json([
            'succes' => true,
            'donnees' => [
                'code' => $plainCode,
                'target_app' => $targetApp,
                'deep_link_uri' => "{$scheme}?code={$plainCode}",
                'expires_in' => self::CODE_TTL_SECONDS,
            ],
        ]);
    }

    /**
     * Étape 2 — Échange le code à usage unique + code_verifier PKCE contre un couple Access/Refresh Token.
     */
    public function exchangeCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'min:32', 'max:128'],
            'code_verifier' => ['required', 'string', 'min:43', 'max:128'],
            'target_app' => ['required', 'string', 'in:client,business,driver'],
        ]);

        $codeHash = hash('sha256', (string) $validated['code']);
        // Consommation atomique (usage unique strict)
        $payload = Cache::pull("sso_auth_code:{$codeHash}");

        if (! is_array($payload)) {
            return response()->json([
                'succes' => false,
                'message' => 'Code SSO invalide, expiré ou déjà utilisé.',
            ], 401);
        }

        $targetApp = (string) $validated['target_app'];
        if (($payload['target_app'] ?? null) !== $targetApp) {
            return response()->json([
                'succes' => false,
                'message' => 'Ce code SSO ne correspond pas à cette application.',
            ], 403);
        }

        // Vérification cryptographique PKCE S256 (RFC 7636)
        $computedChallenge = rtrim(strtr(base64_encode(hash('sha256', (string) $validated['code_verifier'], true)), '+/', '-_'), '=');
        if (! hash_equals((string) ($payload['code_challenge'] ?? ''), $computedChallenge)) {
            $this->auditService->record(
                advisorId: (int) ($payload['user_id'] ?? 0) ?: null,
                targetUserId: (int) ($payload['user_id'] ?? 0) ?: null,
                action: 'sso_exchange_pkce_mismatch',
                result: 'failure',
                context: ['target_app' => $targetApp],
                ipAddress: $request->ip(),
                severity: 'critical',
            );

            return response()->json([
                'succes' => false,
                'message' => 'Échec de vérification PKCE. Reconnexion manuelle requise.',
            ], 401);
        }

        $user = User::with(['city', 'merchant', 'courier'])->find($payload['user_id'] ?? 0);
        if (! $user || ! $user->is_active) {
            return response()->json([
                'succes' => false,
                'message' => 'Compte introuvable ou désactivé.',
            ], 403);
        }

        $requiredRole = self::APP_ROLE_MAP[$targetApp];
        if (! $user->hasRole($requiredRole)) {
            return response()->json([
                'succes' => false,
                'code' => 'ROLE_MISSING',
                'required_role' => $requiredRole,
                'user_roles' => $user->effectiveRoles(),
                'message' => 'Rôle requis absent pour cette application.',
            ], 403);
        }

        $accessToken = JWTAuth::fromUser($user);
        if (in_array($targetApp, ['business', 'driver'], true) || in_array($user->role, ['merchant', 'driver', 'courier'], true)) {
            $jti = (string) JWTAuth::setToken($accessToken)->getPayload()->get('jti');
            $user->update(['session_token' => $jti]);
        }

        $refreshToken = AuthController::issueRotatingRefreshToken($user);

        $this->auditService->record(
            advisorId: $user->id,
            targetUserId: $user->id,
            action: 'sso_exchange_success',
            result: 'success',
            context: ['target_app' => $targetApp],
            ipAddress: $request->ip(),
        );

        return response()->json([
            'succes' => true,
            'message' => 'Session transférée avec succès.',
            'donnees' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_type' => 'bearer',
                'expires_in' => (int) config('jwt.ttl', 20) * 60,
                'must_change_password' => $user->mustChangePassword(),
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'phone' => $user->phone,
                    'email' => $user->email,
                    'role' => $user->role,
                    'roles' => $user->effectiveRoles(),
                    'city_id' => $user->city_id,
                    'city_name' => $user->city?->name,
                    'default_address' => $user->default_address,
                    'language' => $user->language ?? 'fr',
                    'notifications_enabled' => (bool) ($user->notifications_enabled ?? true),
                    'photo_url' => $user->photo_url,
                    'is_active' => (bool) ($user->is_active ?? true),
                    'must_change_password' => $user->mustChangePassword(),
                    'pin_reset_required_at' => $user->pinResetRequiredAt(),
                ],
            ],
        ]);
    }
}
