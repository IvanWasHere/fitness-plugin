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
        'record_type'      => ['max_weight', 'max_reps', 'max_volume', 'best_time'],
        'data_source'      => ['manual', 'device', 'trainer', 'admin'],
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
    |--------------------------------------------------------------------------
    | Free-tier entitlements
    |--------------------------------------------------------------------------
    |
    | The floor applied when a user has no active subscription. Entitlements
    | fail OPEN to this baseline, never closed — a failed renewal must not lock
    | someone out of their own logged data.
    |
    | max_trainers = 0: requesting a trainer requires an active plan (Q14).
    |
    */
    'free_tier' => [
        'can_log_workouts'      => true,
        'can_log_nutrition'     => false,
        'can_log_health'        => true,
        'can_view_stats'        => true,
        'can_message'           => false,
        'max_messages_per_week' => 0,
        'max_trainers'          => 0,
        'max_active_workouts'   => 3,
        'has_video_workouts'    => false,
        'has_personalized_plans' => false,
        'has_food_plans'        => false,
    ],

    'limits' => [
        // Rate limiting (spec 14.3) — see plans/03-backend.md#rate-limiting.
        'api_per_minute_auth'    => 100,
        'api_per_minute_public'  => 10,
        'login_per_minute_ip'    => 5,
        'password_reset_per_hour' => 3,
        'messages_per_minute'    => 30,
        'checkout_per_hour'      => 10,

        // Trainer requests (Q4/Q14).
        'trainer_requests_pending'  => 3,
        'trainer_requests_per_week' => 10,
        'trainer_request_expiry_days' => 14,

        // Pagination.
        'per_page_default' => 20,
        'per_page_max'     => 100,

        // Sessions older than this are marked abandoned by cron.
        'stale_session_hours' => 24,

        // Activity feed retention, in months. Audit rows are exempt.
        'activity_retention_months' => 12,
    ],

    'defaults' => [
        'water_glass_ml'  => 250,
        'water_goal_ml'   => 2000,
        'rest_seconds'    => 60,
        'currency'        => 'USD',
        'brand_name'      => 'FitForge',
        'active_theme'    => 'default',
    ],
];
