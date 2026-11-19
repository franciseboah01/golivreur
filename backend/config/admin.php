<?php

return [
    /*
     * Chemin du panneau d'administration Filament (personnalisable via ADMIN_PANEL_PATH
     * afin de ne pas exposer un simple /admin public en production).
     */
    'path' => trim((string) env('ADMIN_PANEL_PATH', 'admin'), '/'),

    /*
     * Restriction stricte par liste blanche IP / VPN (CIDR supporté).
     * Activée explicitement via ADMIN_IP_WHITELIST_ENABLED=true ou dès que
     * ADMIN_ALLOWED_IPS contient au moins une IP/plage CIDR.
     */
    'ip_whitelist_enabled' => filter_var(env('ADMIN_IP_WHITELIST_ENABLED', false), FILTER_VALIDATE_BOOL),

    'allowed_ips' => array_values(array_filter(array_map(
        static fn (string $ip): string => trim($ip),
        explode(',', (string) env('ADMIN_ALLOWED_IPS', '')),
    ))),

    /*
     * Authentification à deux facteurs (2FA) obligatoire pour tous les comptes admin.
     * En production, activée par défaut même si two_factor_enabled n'a pas encore été coché.
     */
    'enforce_2fa' => filter_var(
        env('ADMIN_ENFORCE_2FA', env('APP_ENV') === 'production'),
        FILTER_VALIDATE_BOOL,
    ),

    'two_factor_ttl_minutes' => (int) env('ADMIN_2FA_TTL_MINUTES', 5),
    'two_factor_session_hours' => (int) env('ADMIN_2FA_SESSION_HOURS', 12),
];
