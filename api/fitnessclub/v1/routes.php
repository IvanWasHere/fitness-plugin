<?php

use FitnessClub\Http\Controllers\Api\AdminController;
use FitnessClub\Http\Controllers\Api\AuthController;
use FitnessClub\Http\Controllers\Api\BillingController;
use FitnessClub\Http\Controllers\Api\HealthController;
use FitnessClub\Http\Controllers\Api\MessageController;
use FitnessClub\Http\Controllers\Api\NotificationController;
use FitnessClub\Http\Controllers\Api\NutritionController;
use FitnessClub\Http\Controllers\Api\ProgressController;
use FitnessClub\Http\Controllers\Api\SessionController;
use FitnessClub\Http\Controllers\Api\SupportController;
use FitnessClub\Http\Controllers\Api\UserController;
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
| The plugin's own session cookie plus its own CSRF token (Auth\Csrf) — no
| WordPress auth cookie and no `wp_rest` nonce is involved, and a WordPress
| administrator with no fc_accounts row is anonymous here.
|
| The public routes are guarded by requireGuest() rather than __return_true:
| they are reachable without a session, but not *with* one, so a stray call
| cannot silently swap the signed-in account.
|
| There is no nonce-refresh route, and no need for one: the CSRF token lives
| exactly as long as the session it is bound to, so it cannot go stale while the
| session is still good.
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
    // Open, unlike the other public routes: the token *is* the authorisation,
    // and someone who still has a session on this browser must be able to follow
    // the link they were emailed.
    'permission_callback' => '__return_true',
    'args'                => [
        // One opaque `{selector}.{verifier}` value, where the old contract took
        // a WordPress reset key alongside the login it belonged to. The login is
        // no longer needed — the selector identifies the row — and not asking
        // for it means the link cannot leak who it was issued to.
        'token' => [
            'required'          => true,
            'type'              => 'string',
            'description'       => 'Token from the emailed link.',
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
| The member's own record — /user/*
|--------------------------------------------------------------------------
|
| No id in any of these paths, by design: the subject is always the caller,
| resolved from the session. An endpoint that takes the user id as a parameter
| is an endpoint that will eventually be handed someone else's.
|
*/

Route::get('/user/dashboard', UserController::class . '@dashboard', [
    'permission_callback' => [UserController::class, 'canRead'],
    'args'                => [
        // Skip the 60-second cache. The client sends it after finishing a
        // workout, where "your numbers update within a minute" is not an
        // acceptable answer to "did that count?".
        'refresh' => [
            'type'    => 'boolean',
            'default' => false,
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

/*
|--------------------------------------------------------------------------
| Nutrition — /nutrition/*, /foods/*
|--------------------------------------------------------------------------
|
| Reads need app access. **Writes additionally need the `can_log_nutrition`
| entitlement**, which the free tier does not grant — that check lives in the
| controller rather than here, because it answers `fc_feature_unavailable` with
| the feature name so the client can offer an upgrade instead of an error.
|
| Dates are `Y-m-d` in the *member's* timezone, resolved server-side: a client
| that sends its own "today" gets a different answer at 00:30 than the server
| would (see Support\UserClock).
|
*/

$dateArg = [
    'date' => [
        'type'              => 'string',
        'description'       => 'Y-m-d. Defaults to the member\'s today.',
        'sanitize_callback' => 'sanitize_text_field',
    ],
];

Route::get('/nutrition/day', NutritionController::class . '@day', [
    'permission_callback' => [NutritionController::class, 'canRead'],
    'args'                => $dateArg,
]);

Route::get('/nutrition/logs', NutritionController::class . '@history', [
    'permission_callback' => [NutritionController::class, 'canRead'],
    'args'                => [
        'from' => [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'to' => [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ],
    ],
]);

// The item array is validated in NutritionService rather than by the args
// schema: an item is either a food reference (nutrients copied from the food) or
// a free-text entry (nutrients supplied), and expressing "exactly one of these
// two shapes" in a JSON-schema `args` block is less readable than the code that
// resolves it — which has to exist either way.
Route::post('/nutrition/logs', NutritionController::class . '@store', [
    'permission_callback' => [NutritionController::class, 'canLog'],
    'args'                => [
        'meal_type' => [
            'type' => 'string',
            'enum' => ['breakfast', 'lunch', 'dinner', 'snack', 'other'],
        ],
        'log_date' => [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'logged_at' => [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'notes' => [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
        ],
        'items' => [
            'required' => true,
            'type'     => 'array',
        ],
    ],
]);

Route::put('/nutrition/logs/(?P<id>\d+)', NutritionController::class . '@update', [
    'permission_callback' => [NutritionController::class, 'canLog'],
    'args'                => $idArg + [
        'meal_type' => [
            'type' => 'string',
            'enum' => ['breakfast', 'lunch', 'dinner', 'snack', 'other'],
        ],
        'log_date' => [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'logged_at' => [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'notes' => [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
        ],
        'items' => [
            'type' => 'array',
        ],
    ],
]);

Route::delete('/nutrition/logs/(?P<id>\d+)', NutritionController::class . '@destroy', [
    'permission_callback' => [NutritionController::class, 'canLog'],
    'args'                => $idArg,
]);

Route::post('/nutrition/water', NutritionController::class . '@water', [
    'permission_callback' => [NutritionController::class, 'canLog'],
    'args'                => $dateArg + [
        // Signed on purpose: "-1 glass" is how a mis-tap is undone.
        'delta_ml' => [
            'type'    => ['integer', 'null'],
            'minimum' => -5000,
            'maximum' => 5000,
        ],
        'total_ml' => [
            'type'    => ['integer', 'null'],
            'minimum' => 0,
            'maximum' => 20000,
        ],
    ],
]);

Route::put('/nutrition/goals', NutritionController::class . '@goals', [
    'permission_callback' => [NutritionController::class, 'canLog'],
    'args'                => $dateArg + [
        // Every goal is nullable and optional: the editor submits only what the
        // member changed, and a null clears one rather than zeroing it.
        'calories'  => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 20000],
        'protein_g' => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 2000],
        'carbs_g'   => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 2000],
        'fat_g'     => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 2000],
        'water_ml'  => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 20000],
    ],
]);

Route::get('/foods', NutritionController::class . '@foods', [
    'permission_callback' => [NutritionController::class, 'canRead'],
    'args'                => [
        'q' => [
            'type'              => 'string',
            'description'       => 'Typeahead term. Prefix-matched.',
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'category' => [
            'type' => 'string',
            'enum' => [
                'protein', 'grains', 'vegetables', 'fruit', 'dairy',
                'nuts', 'fats', 'supplements', 'beverages', 'other',
            ],
        ],
        'per_page' => [
            'type'    => 'integer',
            'minimum' => 1,
            'maximum' => 50,
            'default' => 20,
        ],
    ],
]);

Route::get('/foods/barcode/(?P<code>[A-Za-z0-9]+)', NutritionController::class . '@barcode', [
    'permission_callback' => [NutritionController::class, 'canRead'],
    'args'                => [
        'code' => [
            'required'          => true,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ],
    ],
]);

/*
|--------------------------------------------------------------------------
| Health & measurements — /health/stats, /health/measurements, /health/summary
|--------------------------------------------------------------------------
|
| Reads need app access; **writes additionally need the `can_log_health`
| entitlement**, checked in the controller so the refusal names the feature.
|
| Note the neighbour: `GET /health` above is the API's liveness probe and has
| nothing to do with the member's body metrics. The paths do not collide — the
| probe's route is an exact match — but the two meanings of the word share a
| prefix, so read the segment after it before assuming which one you are in.
|
| Every measurement is nullable in the schema, and has to be: an explicit null
| *clears* a reading, which is how a mis-entered heart rate is removed without
| deleting the whole day. Declaring these as plain `number` would make WordPress
| reject the clear with 400 rest_invalid_param.
|
| The bounds live in HealthService::FIELDS as well as here. That duplication is
| deliberate: the schema rejects nonsense at the edge with a machine-readable
| error, and the service refuses it again for callers that are not HTTP — the
| CLI seeder and the admin screens in W2.5 go straight to the service.
|
*/

$healthStatArgs = [
    'record_date' => [
        'type'              => 'string',
        'description'       => 'Y-m-d. Defaults to the member\'s today.',
        'sanitize_callback' => 'sanitize_text_field',
    ],
    'weight_kg'           => ['type' => ['number', 'null'], 'minimum' => 20, 'maximum' => 500],
    'body_fat_percentage' => ['type' => ['number', 'null'], 'minimum' => 1, 'maximum' => 70],
    'muscle_mass_kg'      => ['type' => ['number', 'null'], 'minimum' => 5, 'maximum' => 200],
    // Sent together or not at all — the service refuses half a reading rather
    // than fabricating the other number, as the prototype did.
    'systolic_pressure'   => ['type' => ['integer', 'null'], 'minimum' => 60, 'maximum' => 260],
    'diastolic_pressure'  => ['type' => ['integer', 'null'], 'minimum' => 30, 'maximum' => 200],
    'heart_rate_resting'  => ['type' => ['integer', 'null'], 'minimum' => 25, 'maximum' => 220],
    'sleep_hours'         => ['type' => ['number', 'null'], 'minimum' => 0, 'maximum' => 24],
    'mood_score'          => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 5],
    'energy_score'        => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 10],
    'stress_score'        => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 5],
    'notes'               => [
        'type'              => ['string', 'null'],
        'sanitize_callback' => 'sanitize_textarea_field',
    ],
];

$rangeArgs = [
    'from' => [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ],
    'to' => [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ],
];

Route::get('/health/summary', HealthController::class . '@summary', [
    'permission_callback' => [HealthController::class, 'canRead'],
]);

Route::get('/health/stats', HealthController::class . '@stats', [
    'permission_callback' => [HealthController::class, 'canRead'],
    'args'                => $rangeArgs + [
        'metrics' => [
            'type'              => 'string',
            'description'       => 'Comma-separated field keys, e.g. weight_kg,sleep_hours.',
            'sanitize_callback' => 'sanitize_text_field',
        ],
    ],
]);

Route::post('/health/stats', HealthController::class . '@store', [
    'permission_callback' => [HealthController::class, 'canLog'],
    'args'                => $healthStatArgs,
]);

Route::put('/health/stats/(?P<id>\d+)', HealthController::class . '@update', [
    'permission_callback' => [HealthController::class, 'canLog'],
    'args'                => $idArg + $healthStatArgs,
]);

Route::delete('/health/stats/(?P<id>\d+)', HealthController::class . '@destroy', [
    'permission_callback' => [HealthController::class, 'canLog'],
    'args'                => $idArg,
]);

Route::get('/health/measurements', HealthController::class . '@measurements', [
    'permission_callback' => [HealthController::class, 'canRead'],
    'args'                => $rangeArgs,
]);

Route::post('/health/measurements', HealthController::class . '@storeMeasurements', [
    'permission_callback' => [HealthController::class, 'canLog'],
    'args'                => [
        'record_date' => [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'chest_cm'     => ['type' => ['number', 'null'], 'minimum' => 30, 'maximum' => 250],
        'waist_cm'     => ['type' => ['number', 'null'], 'minimum' => 30, 'maximum' => 250],
        'hips_cm'      => ['type' => ['number', 'null'], 'minimum' => 30, 'maximum' => 250],
        'arms_cm'      => ['type' => ['number', 'null'], 'minimum' => 10, 'maximum' => 100],
        'thighs_cm'    => ['type' => ['number', 'null'], 'minimum' => 20, 'maximum' => 150],
        'shoulders_cm' => ['type' => ['number', 'null'], 'minimum' => 40, 'maximum' => 250],
        'neck_cm'      => ['type' => ['number', 'null'], 'minimum' => 20, 'maximum' => 100],
        'calves_cm'    => ['type' => ['number', 'null'], 'minimum' => 15, 'maximum' => 100],
        'notes'        => [
            'type'              => ['string', 'null'],
            'sanitize_callback' => 'sanitize_textarea_field',
        ],
    ],
]);

/*
|--------------------------------------------------------------------------
| Progress — /progress/*
|--------------------------------------------------------------------------
|
| Read-only, and everything is bucketed server-side. `range` is a real
| parameter here, not decoration: the prototype's week/month/3M/year toggle set
| state and re-rendered identical data (gap §2, line 993). The server changes
| the *granularity* with the span — days close up, weeks at a quarter, months
| across a year — because a year of daily points is 365 values a chart of this
| size cannot draw.
|
*/

$rangeArg = [
    'range' => [
        'type'    => 'string',
        'enum'    => ['week', 'month', 'quarter', 'year'],
        'default' => 'month',
    ],
];

Route::get('/progress', ProgressController::class . '@index', [
    'permission_callback' => [ProgressController::class, 'canRead'],
    'args'                => $rangeArg,
]);

// The name is matched loosely and decoded in the controller: exercise names
// carry spaces, hyphens and parentheses ("Bench Press (Incline)"), and a
// stricter pattern here would 404 half the library rather than answer it.
Route::get('/progress/exercises/(?P<name>[^/]+)', ProgressController::class . '@exercise', [
    'permission_callback' => [ProgressController::class, 'canRead'],
    'args'                => $rangeArg + [
        'name' => [
            'required'          => true,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ],
    ],
]);

Route::get('/progress/records', ProgressController::class . '@records', [
    'permission_callback' => [ProgressController::class, 'canRead'],
]);

Route::get('/progress/consistency', ProgressController::class . '@consistency', [
    'permission_callback' => [ProgressController::class, 'canRead'],
    'args'                => [
        'year' => [
            'type'    => 'integer',
            'minimum' => 2000,
            'maximum' => 2100,
        ],
    ],
]);

/*
|--------------------------------------------------------------------------
| Notifications, activity and preferences
|--------------------------------------------------------------------------
|
| Keyed on the **account**, not the member profile: a trainer and an
| administrator each have an inbox and neither has an fc_users row. So these
| are gated on "signed in and active" rather than on `fc_access_app`, and the
| ownership scoping lives in every query — there is no user id in any of these
| paths for a caller to swap.
|
| Preferences are the exception: they are stored on `fc_users.preferences` and
| so do require a member profile.
|
| Every route that changes read state answers with the resulting `unread_count`,
| so the nav badge never has to make a second request to learn what it should
| say — and never renders the number the member has just cleared.
|
*/

Route::get('/notifications', NotificationController::class . '@index', [
    'permission_callback' => [NotificationController::class, 'canRead'],
    'args'                => $pagingArgs + [
        'unread_only' => [
            'type'    => 'boolean',
            'default' => false,
        ],
    ],
]);

Route::post('/notifications/read-all', NotificationController::class . '@readAll', [
    'permission_callback' => [NotificationController::class, 'canRead'],
]);

// Registered after `read-all` deliberately. WordPress matches routes in
// registration order, and `(?P<id>\d+)` cannot match "read-all" — but the two
// are one path segment apart, and the ordering makes the intent explicit rather
// than dependent on a regex that someone may later loosen.
Route::post('/notifications/(?P<id>\d+)/read', NotificationController::class . '@read', [
    'permission_callback' => [NotificationController::class, 'canRead'],
    'args'                => $idArg,
]);

Route::delete('/notifications/(?P<id>\d+)', NotificationController::class . '@destroy', [
    'permission_callback' => [NotificationController::class, 'canRead'],
    'args'                => $idArg,
]);

Route::get('/activity', NotificationController::class . '@activity', [
    'permission_callback' => [NotificationController::class, 'canRead'],
    'args'                => $pagingArgs + [
        'types' => [
            'type'              => 'string',
            'description'       => 'Comma-separated event types, e.g. workout.completed,nutrition.logged.',
            'sanitize_callback' => 'sanitize_text_field',
        ],
    ],
]);

Route::get('/user/preferences', NotificationController::class . '@preferences', [
    'permission_callback' => [NotificationController::class, 'canManagePreferences'],
]);

// The body is `{ notifications: { achievement: false, … } }` and is validated in
// the service rather than by the args schema: the keys are the notification-type
// enum, and expressing "an object whose keys are these seven strings and whose
// values are booleans" in a JSON-schema `args` block is less readable than the
// loop that has to exist anyway to merge them.
Route::put('/user/preferences', NotificationController::class . '@updatePreferences', [
    'permission_callback' => [NotificationController::class, 'canManagePreferences'],
    'args'                => [
        'notifications' => [
            'required' => true,
            'type'     => 'object',
        ],
    ],
]);

/*
|--------------------------------------------------------------------------
| Admin — /admin/*
|--------------------------------------------------------------------------
|
| Uniform CRUD over the resources in Services\Admin\ResourceRegistry, which is
| also what decides the capability each one needs. The contract says these
| require `manage_options`; that predates plugin-owned identity (W1.2R/W1.3R),
| so the gate is the plugin's own `fc_*` capabilities — a WordPress
| administrator with no fc_accounts row is anonymous to this API by design.
|
| `{resource}` is matched as a path segment and resolved against the registry,
| so an unknown name is a 404 rather than a query against a table that does not
| exist. The literal routes below are registered *before* the generic ones for
| the same reason as `/notifications/read-all`: the patterns cannot collide, but
| the ordering states the intent.
|
| These endpoints do no ownership scoping — acting on other people's records is
| what an administrator is for. The audit trail replaces it (Q10).
|
*/

Route::get('/admin/dashboard', AdminController::class . '@dashboard', [
    'permission_callback' => [AdminController::class, 'canAccess'],
]);

Route::get('/admin/resources', AdminController::class . '@resources', [
    'permission_callback' => [AdminController::class, 'canAccess'],
]);

Route::get('/admin/settings', AdminController::class . '@settings', [
    'permission_callback' => [AdminController::class, 'canAccess'],
]);

// Groups are validated key-by-key in AdminSettingsService against a whitelist:
// the options row is one JSON blob, and accepting arbitrary keys would let a
// client typo write a permanent orphan into it.
Route::put('/admin/settings', AdminController::class . '@updateSettings', [
    'permission_callback' => [AdminController::class, 'canAccess'],
    'args'                => [
        'general'  => ['type' => 'object'],
        'email'    => ['type' => 'object'],
        'features' => ['type' => 'object'],
    ],
]);

$resourceArg = [
    'resource' => [
        'required'          => true,
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
    ],
];

// The filter names differ per resource, so they are not declared here — the
// controller reads exactly the ones the registry names for the resource in
// hand, and anything else in the query string is ignored rather than trusted.
Route::get('/admin/(?P<resource>[a-z-]+)', AdminController::class . '@index', [
    'permission_callback' => [AdminController::class, 'canAccess'],
    'args'                => $resourceArg + $pagingArgs + [
        'q'     => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
        'sort'  => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
        'order' => ['type' => 'string', 'enum' => ['asc', 'desc']],
    ],
]);

Route::post('/admin/(?P<resource>[a-z-]+)', AdminController::class . '@store', [
    'permission_callback' => [AdminController::class, 'canAccess'],
    'args'                => $resourceArg,
]);

Route::get('/admin/(?P<resource>[a-z-]+)/(?P<id>\d+)', AdminController::class . '@show', [
    'permission_callback' => [AdminController::class, 'canAccess'],
    'args'                => $resourceArg + $idArg,
]);

Route::put('/admin/(?P<resource>[a-z-]+)/(?P<id>\d+)', AdminController::class . '@update', [
    'permission_callback' => [AdminController::class, 'canAccess'],
    'args'                => $resourceArg + $idArg,
]);

Route::delete('/admin/(?P<resource>[a-z-]+)/(?P<id>\d+)', AdminController::class . '@destroy', [
    'permission_callback' => [AdminController::class, 'canAccess'],
    'args'                => $resourceArg + $idArg,
]);

/*
|--------------------------------------------------------------------------
| Messaging — /messages/*
|--------------------------------------------------------------------------
|
| Account-keyed, like notifications: both a member and a trainer hold
| conversations and a trainer has no fc_users row. Access is not a capability
| question — `Guard::participatesInThread()` decides, inside every service call,
| because being a party to the thread is the only thing that grants it. A thread
| you are not on answers 404, never 403: a 403 would confirm the conversation
| exists, which for private messages is itself a disclosure.
|
| Sending is gated twice and for different reasons: `can_message` in the
| controller, so the refusal names the feature and the client can offer an
| upgrade; and the **per-thread** weekly quota in the service, which needs the
| thread because a member coached by two trainers has a separate allowance for
| each (Q3). The quota answers 429 with limit/used/resets_at.
|
| There is no typing endpoint. The prototype's indicator showed permanently
| whenever the trainer was "online" and was tied to nothing; real typing state
| costs a write per keystroke-burst for cosmetic value, and 02 recommends
| dropping it at launch.
|
*/

Route::get('/messages/threads', MessageController::class . '@threads', [
    'permission_callback' => [MessageController::class, 'canAccess'],
]);

// Before the `{id}` routes: `unread-count` and `poll` are literal segments that
// `\d+` cannot match, but the ordering states the intent rather than relying on
// a pattern nobody may loosen later.
Route::get('/messages/unread-count', MessageController::class . '@unreadCount', [
    'permission_callback' => [MessageController::class, 'canAccess'],
]);

Route::get('/messages/poll', MessageController::class . '@poll', [
    'permission_callback' => [MessageController::class, 'canAccess'],
    'args'                => [
        // The last id the client has seen, not a timestamp: ids are monotonic
        // and immune to clock skew between the browser and the server.
        'since' => ['type' => 'integer', 'minimum' => 0],
    ],
]);

Route::get('/messages/threads/(?P<id>\d+)', MessageController::class . '@thread', [
    'permission_callback' => [MessageController::class, 'canAccess'],
    'args'                => $idArg + [
        'before' => ['type' => 'integer', 'minimum' => 1],
        'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
    ],
]);

Route::post('/messages/threads/(?P<id>\d+)', MessageController::class . '@send', [
    'permission_callback' => [MessageController::class, 'canAccess'],
    'args'                => $idArg + [
        'message' => [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
        ],
        'attachments' => ['type' => 'array'],
    ],
]);

Route::post('/messages/threads/(?P<id>\d+)/read', MessageController::class . '@read', [
    'permission_callback' => [MessageController::class, 'canAccess'],
    'args'                => $idArg,
]);

// Multipart: no args schema, because the payload is a file rather than JSON.
// Type is checked by reading the bytes, not by trusting the filename.
Route::post('/messages/attachments', MessageController::class . '@upload', [
    'permission_callback' => [MessageController::class, 'canAccess'],
]);

/*
|--------------------------------------------------------------------------
| Billing — /billing/*
|--------------------------------------------------------------------------
|
| Member-scoped; administrators manage subscriptions through /admin/* instead.
|
| `POST /billing/checkout` takes a plan_id and a cycle and **never a price** —
| the server resolves the amount from the plan. A client-supplied price is, per
| 02, the single most commonly exploited endpoint in subscription plugins, and
| the args schema below has no field it could arrive in.
|
| The webhook is the **only public write endpoint in the plugin**: a gateway
| cannot authenticate as anybody. Its security is the adapter's signature
| verification, which happens before the body is parsed. It also needs an
| explicit exemption from the CSRF gate — `AuthProvider::guard()` refuses any
| non-GET without a token, so without
| `AuthProvider::isSignatureVerifiedRoute()` naming this path it would answer
| 403 `fc_csrf_missing` to every delivery.
|
*/

Route::get('/billing/subscription', BillingController::class . '@subscription', [
    'permission_callback' => [BillingController::class, 'canRead'],
]);

Route::get('/billing/plans', BillingController::class . '@plans', [
    'permission_callback' => [BillingController::class, 'canRead'],
    'args'                => [
        'trainer_id' => ['type' => 'integer', 'minimum' => 1],
    ],
]);

Route::get('/billing/payments', BillingController::class . '@paymentHistory', [
    'permission_callback' => [BillingController::class, 'canRead'],
    'args'                => $pagingArgs,
]);

Route::post('/billing/checkout', BillingController::class . '@checkout', [
    'permission_callback' => [BillingController::class, 'canManage'],
    'args'                => [
        'plan_id' => [
            'required'          => true,
            'type'              => 'integer',
            'minimum'           => 1,
            'sanitize_callback' => 'absint',
        ],
        'cycle' => [
            'required' => true,
            'type'     => 'string',
            'enum'     => ['weekly', 'monthly', 'quarterly', 'yearly'],
        ],
        'return_url' => [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ],
    ],
]);

$subscriptionArg = [
    'subscription_id' => [
        'required'          => true,
        'type'              => 'integer',
        'minimum'           => 1,
        'sanitize_callback' => 'absint',
    ],
];

Route::post('/billing/subscription/cancel', BillingController::class . '@cancel', [
    'permission_callback' => [BillingController::class, 'canManage'],
    'args'                => $subscriptionArg + [
        // Defaults true: the member keeps what they paid for. Immediate
        // cancellation exists for refunds and is an administrator's action.
        'at_period_end' => ['type' => 'boolean', 'default' => true],
    ],
]);

Route::post('/billing/subscription/resume', BillingController::class . '@resume', [
    'permission_callback' => [BillingController::class, 'canManage'],
    'args'                => $subscriptionArg,
]);

Route::post('/billing/subscription/change', BillingController::class . '@change', [
    'permission_callback' => [BillingController::class, 'canManage'],
    'args'                => $subscriptionArg + [
        'plan_id' => [
            'required'          => true,
            'type'              => 'integer',
            'minimum'           => 1,
            'sanitize_callback' => 'absint',
        ],
        'cycle' => [
            'required' => true,
            'type'     => 'string',
            'enum'     => ['weekly', 'monthly', 'quarterly', 'yearly'],
        ],
    ],
]);

// Public by necessity. `__return_true` appears exactly once in this file and
// this is the second place it is justified (the other is the health probe).
Route::post('/billing/webhook/(?P<gateway>[a-z0-9_-]+)', BillingController::class . '@webhook', [
    'permission_callback' => '__return_true',
    'args'                => [
        'gateway' => [
            'required'          => true,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
        ],
    ],
]);

/*
|--------------------------------------------------------------------------
| Support — /support/*
|--------------------------------------------------------------------------
|
| One set of endpoints for members and staff: which you are decides whether
| `GET /support/tickets` is your own tickets or the whole queue, and whether a
| ticket's internal notes come back with it. That branch lives in TicketService,
| because two parallel route trees would mean two places for the internal-note
| filter to be forgotten — and an internal note leaking is showing a customer
| what staff said about them.
|
| Gated only on "signed in and active". Raising a ticket is how somebody reports
| that the rest of the app is refusing them, so gating it on a feature
| capability would lock the door and post the key inside.
|
*/

Route::get('/support/faq', SupportController::class . '@faq', [
    'permission_callback' => [SupportController::class, 'canAccess'],
]);

Route::get('/support/tickets', SupportController::class . '@index', [
    'permission_callback' => [SupportController::class, 'canAccess'],
    'args'                => $pagingArgs + [
        'status'      => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
        'priority'    => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
        'category'    => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
        // Honoured for staff only; the service ignores it for a member, whose
        // list is already scoped to themselves.
        'assigned_to' => ['type' => 'integer', 'minimum' => 1],
        'q'           => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
    ],
]);

Route::post('/support/tickets', SupportController::class . '@store', [
    'permission_callback' => [SupportController::class, 'canAccess'],
    'args'                => [
        'subject' => [
            'required'          => true,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'message' => [
            'required'          => true,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
        ],
        'category' => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
        // A request, not a promise: staff reset it from the queue, or every
        // ticket would arrive urgent.
        'priority' => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
    ],
]);

Route::get('/support/tickets/(?P<id>\d+)', SupportController::class . '@show', [
    'permission_callback' => [SupportController::class, 'canAccess'],
    'args'                => $idArg,
]);

Route::post('/support/tickets/(?P<id>\d+)/replies', SupportController::class . '@reply', [
    'permission_callback' => [SupportController::class, 'canAccess'],
    'args'                => $idArg + [
        'message' => [
            'required'          => true,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
        ],
        'is_internal_note' => ['type' => 'boolean', 'default' => false],
    ],
]);

Route::patch('/support/tickets/(?P<id>\d+)', SupportController::class . '@update', [
    'permission_callback' => [SupportController::class, 'canAccess'],
    'args'                => $idArg + [
        'status'   => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
        'priority' => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
        'assigned_to_account_id' => ['type' => 'integer', 'minimum' => 0],
    ],
]);

Route::post('/foods', NutritionController::class . '@createFood', [
    'permission_callback' => [NutritionController::class, 'canLog'],
    'args'                => [
        'name' => [
            'required'          => true,
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'brand' => [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ],
        'category' => [
            'type' => 'string',
            'enum' => [
                'protein', 'grains', 'vegetables', 'fruit', 'dairy',
                'nuts', 'fats', 'supplements', 'beverages', 'other',
            ],
        ],
        'serving_size'  => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
        'serving_grams' => ['type' => ['number', 'null'], 'minimum' => 0],
        'calories'      => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10000],
        'protein_g'     => ['type' => 'number', 'minimum' => 0, 'maximum' => 1000],
        'carbs_g'       => ['type' => 'number', 'minimum' => 0, 'maximum' => 1000],
        'fat_g'         => ['type' => 'number', 'minimum' => 0, 'maximum' => 1000],
        'fiber_g'       => ['type' => ['number', 'null'], 'minimum' => 0],
        'sugar_g'       => ['type' => ['number', 'null'], 'minimum' => 0],
        'sodium_mg'     => ['type' => ['number', 'null'], 'minimum' => 0],
    ],
]);
