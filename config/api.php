<?php

if (!defined('ABSPATH')) {
    exit();
}

return [
    /*
    |--------------------------------------------------------------------------
    | WordPress REST API
    |--------------------------------------------------------------------------
    |
    | Left open: some plugin endpoints are deliberately public (the payment
    | webhook, password reset). Authentication is enforced per-route by each
    | route's permission_callback, which is the only place that can express
    | "this endpoint is public and that one is not".
    |
    */
    'wp' => [
        'require_authentication' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom REST routes
    |--------------------------------------------------------------------------
    |
    | RestProvider scans /api and derives the REST namespace from the folder
    | path, so api/fitnessclub/v1/routes.php serves
    | /wp-json/fitnessclub/v1/*.
    |
    */
    'custom' => [
        'path'    => '/api',
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | HTTP Basic is OFF and must stay off. WP Bones' bundled handler
    | authenticates from PHP_AUTH_USER on every REST request, which is an
    | additional credential path we do not want exposed.
    |
    | Embedded apps authenticate with the WP session cookie + X-WP-Nonce.
    | JWT for external clients is Phase 4 — see plans/03-backend.md#authentication.
    |
    */
    'auth' => [
        'basic' => false,
    ],
];
