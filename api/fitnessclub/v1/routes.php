<?php

use FitnessClub\Http\Controllers\Api\AuthController;
use FitnessClub\WPBones\Routing\API\Route;

if (!defined('ABSPATH')) {
    exit();
}

/*
|--------------------------------------------------------------------------
| FitnessClub REST API — v1
|--------------------------------------------------------------------------
|
| Served at /wp-json/fitnessclub/v1/*. The namespace comes from this file's
| folder path (api/fitnessclub/v1), scanned by wpBones' RestProvider.
|
| Two rules for every route added here (see plans/02-api-contract.md):
|   1. A real permission_callback — __return_true only for genuinely public routes.
|   2. A full args schema, so WP validates and sanitises for free — and so the
|      OpenAPI document can be generated from these definitions rather than
|      written by hand.
|
*/

Route::get('/health', function () {
    return Route::response([
        'ok'      => true,
        'plugin'  => 'fitnessclub',
        'version' => defined('FITNESSCLUB_VERSION') ? FITNESSCLUB_VERSION : null,
        'time'    => gmdate('c'),
    ]);
}, [
    'permission_callback' => '__return_true',
]);

/*
|--------------------------------------------------------------------------
| Auth — /auth/*
|--------------------------------------------------------------------------
|
| Cookie + nonce. The public routes are guarded by requireGuest() rather than
| __return_true: they are reachable without a session, but not *with* one, so a
| stray call cannot silently swap the signed-in user.
|
| Note there is no /auth/nonce route — refreshing an expired nonce cannot work
| over REST. See FitnessClub\Ajax\NonceProvider for the reason and the mechanism.
|
*/

Route::post('/auth/login', AuthController::class . '@login', [
    'permission_callback' => [AuthController::class, 'requireGuest'],
    'args'                => [
        'user_login' => [
            'required'          => true,
            'type'              => 'string',
            'description'       => 'Email address or username.',
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'password' => [
            'required'    => true,
            'type'        => 'string',
            'description' => 'Account password. Never sanitised — a password is bytes, not text.',
        ],
        'remember' => [
            'required' => false,
            'type'     => 'boolean',
            'default'  => false,
        ],
    ],
]);

Route::post('/auth/register', AuthController::class . '@register', [
    'permission_callback' => [AuthController::class, 'requireGuest'],
    'args'                => [
        'email' => [
            'required'          => true,
            'type'              => 'string',
            'format'            => 'email',
            'sanitize_callback' => 'sanitize_email',
        ],
        'password' => [
            'required' => true,
            'type'     => 'string',
        ],
        'display_name' => [
            'required'          => false,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ],
    ],
]);

Route::post('/auth/logout', AuthController::class . '@logout', [
    'permission_callback' => [AuthController::class, 'requireSession'],
]);

Route::get('/auth/me', AuthController::class . '@me', [
    'permission_callback' => [AuthController::class, 'requireSession'],
]);

Route::post('/auth/password/forgot', AuthController::class . '@forgotPassword', [
    'permission_callback' => [AuthController::class, 'requireGuest'],
    'args'                => [
        'user_login' => [
            'required'          => true,
            'type'              => 'string',
            'description'       => 'Email address or username.',
            'sanitize_callback' => 'sanitize_text_field',
        ],
    ],
]);

Route::post('/auth/password/reset', AuthController::class . '@resetPassword', [
    // Open, unlike the other public routes: the reset *key* is the authorisation,
    // and someone who still has a session on this browser must be able to follow
    // the link they were emailed — wp-login.php behaves the same way.
    'permission_callback' => '__return_true',
    'args'                => [
        'key' => [
            'required'          => true,
            'type'              => 'string',
            'description'       => 'Reset key from the emailed link.',
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'login' => [
            'required'          => true,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'password' => [
            'required' => true,
            'type'     => 'string',
        ],
    ],
]);
