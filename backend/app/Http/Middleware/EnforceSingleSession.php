<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class EnforceSingleSession
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user() ?: $request->user();

        if ($user && in_array($user->role, ['merchant', 'driver', 'courier'])) {
            $freshUser = $user->fresh();
            if ($freshUser && $freshUser->session_token) {
                try {
                    $token = JWTAuth::parseToken();
                    $jti = (string) $token->getPayload()->get('jti');

                    if ($jti !== (string) $freshUser->session_token) {
                        try {
                            $token->invalidate();
                        } catch (\Throwable $e) {
                            // Ignorer si déjà invalidé
                        }

                        return response()->json([
                            'succes' => false,
                            'message' => 'Session expirée ou ouverte sur un autre appareil.',
                        ], 401);
                    }
                } catch (\Throwable $e) {
                    return response()->json([
                        'succes' => false,
                        'message' => 'Session invalide ou expirée.',
                    ], 401);
                }
            }
        }

        return $next($request);
    }
}
