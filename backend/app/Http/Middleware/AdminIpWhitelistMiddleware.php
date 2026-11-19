<?php

namespace App\Http\Middleware;

use App\Services\SupportAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restriction stricte par liste blanche IP / VPN sur le panneau d'administration.
 * Bloque toute requête hors des IP/CIDR autorisés avant même l'affichage de la page de connexion.
 */
class AdminIpWhitelistMiddleware
{
    public function __construct(
        private readonly SupportAuditService $auditService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = config('admin.allowed_ips', []);
        $isEnabled = (bool) config('admin.ip_whitelist_enabled', false) || ! empty($allowedIps);

        if (! $isEnabled) {
            return $next($request);
        }

        $clientIp = (string) ($request->ip() ?? '');

        if ($clientIp !== '' && is_array($allowedIps) && IpUtils::checkIp($clientIp, $allowedIps)) {
            return $next($request);
        }

        $this->auditService->record(
            advisorId: auth()->id() ? (int) auth()->id() : null,
            targetUserId: null,
            action: 'admin_ip_blocked',
            result: 'failure',
            context: [
                'path' => $request->path(),
                'client_ip' => $clientIp,
            ],
            ipAddress: $clientIp,
            severity: 'warning',
        );

        abort(403, 'Accès au panneau d\'administration restreint au réseau VPN / IP autorisées.');
    }
}
