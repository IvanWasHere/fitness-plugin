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
| folder path (api/fitnessclub/v1), not from anything declared here.
|
| Two rules for every route added below, no exceptions:
|
|   1. permission_callback is mandatory and must express the real rule.
|      `__return_true` is only ever correct for genuinely public endpoints.
|      Capability alone is not authorisation — resource ownership is checked
|      in the controller/service (plans/03-backend.md).
|
|   2. Declare a full `args` schema. WP then validates and sanitises for free,
|      and the OpenAPI document is generated from the same definition
|      (plans/02-api-contract.md#cross-cutting).
|
| The full contract is specified in plans/02-api-contract.md. Routes are added
| per work package; this file currently carries only the health check used to
| verify wiring.
|
*/

Route::get('/health', function () {
    return Route::response([
        'ok'      => true,
        'plugin'  => 'fitnessclub',
        'version' => defined('FITNESSCLUB_VERSION') ? FITNESSCLUB_VERSION : null,
        'schema'  => (int) get_option(\FitnessClub\Database\Upgrade\Manager::OPTION_VERSION, 0),
        'time'    => gmdate('c'),
    ]);
}, [
    'permission_callback' => '__return_true',
]);
