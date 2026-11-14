<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user() ?: $request->user();

        if ($user) {
            $freshUser = $user->fresh();
            if (! $freshUser || ! $freshUser->is_active) {
                return response()->json([
                    'succes' => false,
                    'message' => 'Votre compte est désactivé ou suspendu. Veuillez contacter le support.',
                ], 403);
            }
        }

        return $next($request);
    }
}
