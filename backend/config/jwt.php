<?php

// Config JWT — php-open-source-saver/jwt-auth (ADR-002).
// Access token court (60 min), refresh token long (30 jours = 43200 min) avec rotation,
// blacklist Redis activee pour permettre la revocation immediate (logout, blocage compte).

return [

    'secret' => env('JWT_SECRET'),

    'keys' => [
        'public' => env('JWT_PUBLIC_KEY'),
        'private' => env('JWT_PRIVATE_KEY'),
        'passphrase' => env('JWT_PASSPHRASE'),
    ],

    // Duree de vie de l'access token, en minutes.
    'ttl' => env('JWT_TTL', 60),

    // Duree de vie du refresh token, en minutes (30 jours).
    'refresh_ttl' => env('JWT_REFRESH_TTL', 43200),

    'algo' => env('JWT_ALGO', 'HS256'),

    'required_claims' => [
        'iss',
        'iat',
        'exp',
        'nbf',
        'sub',
        'jti',
    ],

    'persistent_claims' => [],

    'lock_subject' => true,

    'leeway' => env('JWT_LEEWAY', 0),

    // Blacklist Redis : revocation immediate possible (logout, compte bloque).
    'blacklist_enabled' => env('JWT_BLACKLIST_ENABLED', true),

    'blacklist_grace_period' => env('JWT_BLACKLIST_GRACE_PERIOD', 0),

    'decrypt_cookies' => false,

    'providers' => [
        'jwt' => PHPOpenSourceSaver\JWTAuth\Providers\JWT\Lcobucci::class,
        'auth' => PHPOpenSourceSaver\JWTAuth\Providers\Auth\Illuminate::class,
        'storage' => PHPOpenSourceSaver\JWTAuth\Providers\Storage\Illuminate::class,
    ],

];
