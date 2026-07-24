<?php

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
|   2. A full args schema, so WP validates and sanitises for free.
|
| Only the health check exists so far; real routes arrive per work package.
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
