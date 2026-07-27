<?php

if (!defined('ABSPATH')) {
    exit();
}

/*
|--------------------------------------------------------------------------
| Domain constants
|--------------------------------------------------------------------------
|
| Enum values, limits and defaults referenced by services and by REST `args`
| schemas. Single source of truth: validation, the OpenAPI document and the
| admin UI's select options all read from here.
|
| Read via the wpBones config model, e.g. FitnessClub()->config('fitnessclub.enums.gender').
|
*/

return [
    'db_prefix' => 'fc_',

    'enums' => [
        'gender'            => ['male', 'female', 'other', 'undisclosed'],
        'fitness_level'     => ['beginner', 'intermediate', 'advanced', 'elite'],
        'activity_level'    => ['sedentary', 'light', 'moderate', 'active', 'very_active'],
        'plan_owner_type'   => ['platform', 'trainer'],
        'plan_type'         => ['workout', 'nutrition', 'combined'],
        'difficulty'        => ['beginner', 'intermediate', 'advanced'],
        'workout_type'      => ['strength', 'cardio', 'hiit', 'flexibility', 'recovery'],
        'exercise_type'     => ['compound', 'isolation', 'cardio', 'flexibility'],
        'exercise_metric'   => ['reps', 'seconds', 'distance'],
        'session_status'    => ['in_progress', 'paused', 'completed', 'abandoned'],
        'assignment_status' => ['assigned', 'in_progress', 'completed', 'archived'],

        // Users request trainers; trainers accept or decline (Q4).
        'trainer_link_status' => ['pending', 'active', 'inactive', 'declined', 'withdrawn'],

        'subscription_cycle'  => ['weekly', 'monthly', 'quarterly', 'yearly'],
        'subscription_status' => ['trialing', 'active', 'past_due', 'paused', 'cancelled', 'expired'],
        'payment_type'        => ['subscription', 'one_time', 'refund'],
        'payment_status'      => ['pending', 'completed', 'failed', 'refunded', 'disputed'],
        'meal_type'           => ['breakfast', 'lunch', 'dinner', 'snack', 'other'],
        'food_category'       => [
            'protein', 'grains', 'vegetables', 'fruit', 'dairy',
            'nuts', 'fats', 'supplements', 'beverages', 'other',
        ],
        'record_type'       => ['max_weight', 'max_reps', 'max_volume', 'best_time'],
        'data_source'       => ['manual', 'device', 'trainer', 'admin'],
        'notification_type' => [
            'workout', 'message', 'achievement',
            'subscription', 'system', 'progress', 'support',
        ],
        'ticket_category' => ['billing', 'technical', 'general', 'training', 'other'],
        'ticket_priority' => ['low', 'medium', 'high', 'urgent'],
        'ticket_status'   => ['open', 'in_progress', 'waiting_user', 'resolved', 'closed'],
        'author_role'     => ['user', 'trainer', 'admin'],
    ],

    /*
    | Free-tier entitlements — the floor when a user has no active subscription.
    | Entitlements fail OPEN to this baseline, never closed. max_trainers = 0:
    | requesting a trainer requires an active plan (Q14).
    */
    'free_tier' => [
        'can_log_workouts'       => true,
        'can_log_nutrition'      => false,
        'can_log_health'         => true,
        'can_view_stats'         => true,
        'can_message'            => false,
        'max_messages_per_week'  => 0,
        'max_trainers'           => 0,
        'max_active_workouts'    => 3,
        'has_video_workouts'     => false,
        'has_personalized_plans' => false,
        'has_food_plans'         => false,
    ],

    'limits' => [
        // Rate limiting (spec 14.3) — see plans/03-backend.md#rate-limiting.
        'api_per_minute_auth'    => 100,
        'api_per_minute_public'  => 10,
        'login_per_minute_ip'    => 5,
        'register_per_hour_ip'   => 5,
        'password_reset_per_hour' => 3,

        // Dunning: how long a past-due subscription keeps its features before
        // it is suspended. Long enough that a temporary decline does not cost
        // somebody their history; short enough to matter (W3.2).
        'dunning_grace_days'      => 14,
        // Confirming a reset (key + new password) is per IP — a stolen key is
        // tried in bulk, one email at a time.
        'password_reset_confirm_per_hour_ip' => 10,
        // Minimum length for a password set through the app. WordPress itself
        // has no minimum; "12345" would otherwise be accepted.
        'password_min_length'    => 10,
        'messages_per_minute'    => 30,
        'checkout_per_hour'      => 10,

        // Trainer requests (Q4/Q14).
        'trainer_requests_pending'    => 3,
        'trainer_requests_per_week'   => 10,
        'trainer_request_expiry_days' => 14,

        // Pagination.
        'per_page_default' => 20,
        'per_page_max'     => 100,

        // Sessions older than this are marked abandoned by cron.
        'stale_session_hours' => 24,

        // Activity feed retention, in months. Audit rows are exempt.
        'activity_retention_months' => 12,
    ],

    /*
    | Plugin-owned identity. The plugin does not use WordPress accounts: a
    | WordPress administrator has no access to the app without an fc_accounts
    | row. See plugin/Auth/.
    */
    'auth' => [
        // Idle timeout, and the cap that never slides. Without the absolute
        // cap, a stolen cookie on an app that polls stays valid forever —
        // the theft keeps refreshing it.
        'idle_minutes'           => 720,
        'absolute_hours'         => 24,
        'remember_idle_days'     => 14,
        'remember_absolute_days' => 90,

        // How rarely the idle window is slid forward. Without a floor this is a
        // database write on every authenticated request.
        'touch_interval_seconds' => 300,

        // A reset link is a bearer credential sitting in an inbox, so it lives
        // for an hour rather than WordPress' 24.
        'reset_ttl_minutes' => 60,
        'invite_ttl_hours'  => 72,

        // Per-account lockout. RateLimiter throttles per IP, which is no
        // obstacle to attempts against one account spread over many addresses.
        'lockout_threshold' => 10,
        'lockout_minutes'   => 15,

        // bcrypt work factor. Raising it is safe: password_needs_rehash()
        // upgrades each account on its next successful sign-in.
        'bcrypt_cost' => 12,

        // Words for generated credentials (adminFalcon / passFalcon7K3Q).
        // Deliberately short, unambiguous and unmistakable when read aloud or
        // copied off a screenshot.
        'words' => [
            'Falcon', 'Harbor', 'Cedar', 'Onyx', 'Sable', 'Quartz', 'Ember', 'Cobalt',
            'Juniper', 'Marlin', 'Pepper', 'Ridge', 'Saffron', 'Tundra', 'Willow', 'Zephyr',
            'Anchor', 'Bramble', 'Cinder', 'Drift', 'Fable', 'Gallop', 'Hollow', 'Iris',
            'Kettle', 'Lantern', 'Meadow', 'Nectar', 'Orchid', 'Pilot', 'Quiver', 'Rustic',
        ],
    ],

    'defaults' => [
        'water_glass_ml' => 250,
        'water_goal_ml'  => 2000,
        'rest_seconds'   => 60,
        'currency'       => 'USD',

        // Used for the calorie estimate when a user has not entered a weight.
        // Onboarding asks for one; until then an estimate beats no number.
        'body_weight_kg' => 70.0,
    ],

    /*
    | MET (metabolic equivalent) per workout type, for the calorie estimate
    | kcal = MET x body-weight-kg x hours. Compendium-of-Physical-Activities
    | mid-range values: the honest resolution of a session-level estimate is
    | roughly +/-20%, so per-exercise precision here would be false precision.
    | Replaces the prototype's `elapsedSeconds * 6.5` for every user and workout.
    */
    'met_values' => [
        'strength'    => 5.0,
        'cardio'      => 7.5,
        'hiit'        => 9.0,
        'flexibility' => 2.5,
        'recovery'    => 2.3,
        'default'     => 5.0,
    ],
];
