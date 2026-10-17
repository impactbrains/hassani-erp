<?php

return [
    // API v1 uses bearer tokens only (website/app API keys and rider-app logins); no cookie SPA mode.
    'stateful' => [],
    'guard' => [],
    // API keys do not expire on their own; they are revoked in Settings → API keys.
    // Rider-app tokens are given their own expiry when issued.
    'expiration' => null,
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'hsn_'),
    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
