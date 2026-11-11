<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloque les opérations commerce lorsque le compte est suspendu (is_active = false).
 */
class ActiveMerchantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = auth()->id();

        if (! $userId) {
            return $next($request);
        }

        $merchant = Merchant::where('user_id', $userId)->first();

        if ($merchant && ! $merchant->is_active) {
            return response()->json([
                'succes' => false,
                'message' => 'Votre compte commerce est suspendu ou inactif.',
            ], 403);
        }

        return $next($request);
    }
}
