<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Signing Secret
    |--------------------------------------------------------------------------
    |
    | The HMAC secret used to sign and verify access tokens. Falls back to the
    | application key so the app works out of the box, but a dedicated
    | JWT_SECRET should be set in production.
    |
    */

    'secret' => env('JWT_SECRET', env('APP_KEY')),

    'algo' => env('JWT_ALGO', 'HS256'),

    /*
    |--------------------------------------------------------------------------
    | Token Lifetimes
    |--------------------------------------------------------------------------
    |
    | Access tokens are short-lived stateless JWTs. Refresh tokens are opaque,
    | stored hashed in the database, and rotated on every use.
    |
    */

    'access_ttl' => (int) env('JWT_ACCESS_TTL', 15 * 60),        // seconds (15 min)

    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 30 * 24 * 60 * 60), // seconds (30 days)

];
