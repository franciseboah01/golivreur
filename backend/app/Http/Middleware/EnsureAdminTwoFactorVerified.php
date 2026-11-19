<?php

namespace App\Http\Middleware;

use App\Filament\Pages\AdminTwoFactorPage;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige une authentification à deux facteurs (2FA) valide en session pour tous les comptes admin.
 */
class EnsureAdminTwoFactorVerified
{
    public const SESSION_VERIFIED_AT_KEY = 'admin_2fa_verified_at';

    public const SESSION_USER_ID_KEY = 'admin_2fa_verified_user_id';

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $requires2fa = (bool) config('admin.enforce_2fa', false) || (bool) $user->two_factor_enabled;
        if (! $requires2fa) {
            return $next($request);
        }

        $adminPath = trim((string) config('admin.path', 'admin'), '/');
        $path = trim($request->path(), '/');

        // Autoriser la page de challenge 2FA et la déconnexion pour éviter toute boucle de redirection
        if (
            $path === "{$adminPath}/two-factor"
            || $path === "{$adminPath}/logout"
            || str_starts_with($path, 'livewire/')
        ) {
            return $next($request);
        }

        $verifiedAt = $request->session()->get(self::SESSION_VERIFIED_AT_KEY);
        $verifiedUserId = (int) $request->session()->get(self::SESSION_USER_ID_KEY, 0);
        $maxHours = max(1, (int) config('admin.two_factor_session_hours', 12));

        $isValidSession = is_int($verifiedAt)
            && $verifiedUserId === (int) $user->id
            && (time() - $verifiedAt) < ($maxHours * 3600);

        if ($isValidSession) {
            return $next($request);
        }

        return redirect()->to(AdminTwoFactorPage::getUrl());
    }
}
