<?php

use FitnessClub\Http\Controllers\Api\AuthController;
use FitnessClub\Http\Controllers\Api\SessionController;
use FitnessClub\Http\Controllers\Api\WorkoutController;
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

/*
|--------------------------------------------------------------------------
| Workouts — /workouts/*, /exercises/{id}
|--------------------------------------------------------------------------
|
| Read-only. Every route resolves through the caller's fc_user_workouts
| assignments, so an id from someone else's programme is a 404 rather than a
| leak — the ownership rule is in the query, not in a check that can be skipped.
|
*/

$idArg = [
    'id' => [
        'required'          => true,
        'type'              => 'integer',
        'minimum'           => 1,
        'sanitize_callback' => 'absint',
    ],
];

$pagingArgs = [
    'page' => [
        'type'    => 'integer',
        'minimum' => 1,
        'default' => 1,
    ],
    'per_page' => [
        'type'    => 'integer',
        'minimum' => 1,
        'maximum' => 100,
    ],
];

Route::get('/workouts', WorkoutController::class . '@index', [
    'permission_callback' => [WorkoutController::class, 'canRead'],
    'args'                => $pagingArgs + [
        'status' => [
            'type' => 'string',
            'enum' => ['assigned', 'in_progress', 'completed', 'archived'],
        ],
        'difficulty' => [
            'type' => 'string',
            'enum' => ['beginner', 'intermediate', 'advanced'],
        ],
        'type' => [
            'type' => 'string',
            'enum' => ['strength', 'cardio', 'hiit', 'flexibility', 'recovery'],
        ],
        'q' => [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ],
    ],
]);

Route::get('/workouts/(?P<id>\d+)', WorkoutController::class . '@show', [
    'permission_callback' => [WorkoutController::class, 'canRead'],
    'args'                => $idArg,
]);

Route::get('/exercises/(?P<id>\d+)', WorkoutController::class . '@exercise', [
    'permission_callback' => [WorkoutController::class, 'canRead'],
    'args'                => $idArg,
]);

/*
|--------------------------------------------------------------------------
| Sessions — /sessions/*
|--------------------------------------------------------------------------
|
| The session is an explicit resource with an id (plans/02-api-contract.md):
| the spec's identifier-free start/pause/complete forces the server to guess
| which session is meant, which breaks as soon as a user has two devices.
|
| Reads need app access; writes need fc_log_workouts.
|
*/

Route::get('/sessions', SessionController::class . '@index', [
    'permission_callback' => [SessionController::class, 'canRead'],
    'args'                => $pagingArgs,
]);

Route::get('/sessions/active', SessionController::class . '@active', [
    'permission_callback' => [SessionController::class, 'canRead'],
]);

Route::get('/sessions/(?P<id>\d+)', SessionController::class . '@show', [
    'permission_callback' => [SessionController::class, 'canRead'],
    'args'                => $idArg,
]);

Route::post('/sessions', SessionController::class . '@start', [
    'permission_callback' => [SessionController::class, 'canLog'],
    'args'                => [
        'workout_id' => [
            'required'          => true,
            'type'              => 'integer',
            'minimum'           => 1,
            'sanitize_callback' => 'absint',
        ],
    ],
]);

Route::patch('/sessions/(?P<id>\d+)', SessionController::class . '@transition', [
    'permission_callback' => [SessionController::class, 'canLog'],
    'args'                => $idArg + [
        'action' => [
            'required' => true,
            'type'     => 'string',
            'enum'     => ['pause', 'resume'],
        ],
    ],
]);

Route::patch('/sessions/(?P<id>\d+)/cursor', SessionController::class . '@cursor', [
    'permission_callback' => [SessionController::class, 'canLog'],
    'args'                => $idArg + [
        'exercise_index' => [
            'required' => true,
            'type'     => 'integer',
            'minimum'  => 0,
        ],
        'set_index' => [
            'required' => true,
            'type'     => 'integer',
            'minimum'  => 0,
        ],
    ],
]);

Route::post('/sessions/(?P<id>\d+)/sets', SessionController::class . '@logSet', [
    'permission_callback' => [SessionController::class, 'canLog'],
    'args'                => $idArg + [
        'exercise_id' => [
            'required'          => true,
            'type'              => 'integer',
            'minimum'           => 1,
            'sanitize_callback' => 'absint',
        ],
        // Idempotency key: (session, exercise, set_index) identifies the set, so
        // a retry on bad gym wifi corrects it instead of logging a phantom one.
        'set_index' => [
            'required' => true,
            'type'     => 'integer',
            'minimum'  => 0,
        ],
        // Every measure is nullable, and the schema has to say so: a timed hold
        // has no reps, a bodyweight set has no weight, and the first set of a
        // workout has no rest before it. Declaring these as plain `integer` makes
        // WordPress reject an explicit null with 400 rest_invalid_param — which
        // the player's offline queue then discards as unretryable, losing the set
        // while reporting it saved.
        'reps' => [
            'type'    => ['integer', 'null'],
            'minimum' => 0,
            'maximum' => 10000,
        ],
        'weight_kg' => [
            'type'    => ['number', 'null'],
            'minimum' => 0,
            'maximum' => 1000,
        ],
        'duration_seconds' => [
            'type'    => ['integer', 'null'],
            'minimum' => 0,
            'maximum' => 86400,
        ],
        'rest_taken_seconds' => [
            'type'    => ['integer', 'null'],
            'minimum' => 0,
            'maximum' => 86400,
        ],
        'rpe' => [
            'type'    => ['integer', 'null'],
            'minimum' => 1,
            'maximum' => 10,
        ],
    ],
]);

Route::post('/sessions/(?P<id>\d+)/complete', SessionController::class . '@complete', [
    'permission_callback' => [SessionController::class, 'canLog'],
    'args'                => $idArg,
]);

Route::post('/sessions/(?P<id>\d+)/abandon', SessionController::class . '@abandon', [
    'permission_callback' => [SessionController::class, 'canLog'],
    'args'                => $idArg,
]);

Route::patch('/sessions/(?P<id>\d+)/review', SessionController::class . '@review', [
    'permission_callback' => [SessionController::class, 'canLog'],
    'args'                => $idArg + [
        'notes' => [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
        ],
        'perceived_exertion' => [
            'type'    => 'integer',
            'minimum' => 1,
            'maximum' => 10,
        ],
        'difficulty_rating' => [
            'type'    => 'integer',
            'minimum' => 1,
            'maximum' => 5,
        ],
        'sets' => [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'exercise_id'      => ['type' => 'integer', 'minimum' => 1],
                    'set_index'        => ['type' => 'integer', 'minimum' => 0],
                    'reps'             => ['type' => ['integer', 'null'], 'minimum' => 0],
                    'weight_kg'        => ['type' => ['number', 'null'], 'minimum' => 0],
                    'duration_seconds' => ['type' => ['integer', 'null'], 'minimum' => 0],
                    'rpe'              => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 10],
                ],
            ],
        ],
    ],
]);
