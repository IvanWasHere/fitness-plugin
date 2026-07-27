<?php

namespace FitnessClub\Services\Admin;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * What every admin resource is, declaratively (plans/05-admin-app.md, W2.5).
 *
 * The admin prototype's one good idea was `CrudTable(config)` — a single generic
 * table driven by a declarative column spec, so a new resource is one config
 * rather than a new screen. This is the server half of the same idea: one
 * paginated query builder driven by one registry, so a new resource is one entry
 * rather than a new controller.
 *
 * ## Nothing here is ever user input
 *
 * Every column name, join and sort expression in this file is a literal. The
 * query builder interpolates them into SQL directly — it has to, because column
 * names cannot be bound as placeholders — and that is only safe because the
 * *set* of possible values is fixed here rather than derived from the request.
 * A request names a sort key; the registry decides what SQL that maps to, and an
 * unknown key falls back to the default. Adding a value here that comes from
 * anywhere but a literal would turn this into an injection point.
 *
 * ## Keys
 *
 * - `table`       physical table, without the wpdb prefix
 * - `alias`       SQL alias used by `select`, `joins`, `search`, `sort`, `filters`
 * - `select`      column expressions for the list query
 * - `joins`       raw JOIN clauses, `{p}` standing in for the table prefix
 * - `search`      expressions the `q` parameter matches against, ORed
 * - `sort`        public sort key → SQL expression
 * - `filters`     public filter name → ['expr' => …, 'op' => exact|gte|lte|like]
 * - `writable`    column → type, for create and update
 * - `required`    columns that must be present and non-empty on create
 * - `capability`  the plugin capability required
 * - `audit`       true when writes must record who changed someone else's data (Q10)
 * - `soft_scope`  a WHERE fragment always applied (e.g. only member accounts)
 */
final class ResourceRegistry
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return [
            /*
             * Members. The list joins the account for name/email — `fc_users` is
             * the profile, `fc_accounts` is the identity — plus the active plan
             * and a derived workout count, both of which the prototype stored as
             * denormalised columns it never recomputed.
             */
            'users' => [
                'table'  => 'fc_users',
                'alias'  => 'u',
                'select' => [
                    'u.id', 'u.account_id', 'u.display_name', 'u.phone', 'u.avatar_url',
                    'u.date_of_birth', 'u.gender', 'u.height_cm', 'u.weight_kg',
                    'u.target_weight_kg', 'u.fitness_level', 'u.fitness_goal',
                    'u.activity_level', 'u.timezone', 'u.created_at',
                    'a.login AS login', 'a.email AS email', 'a.status AS status',
                    'a.last_login_at AS last_login_at',
                    'p.plan_name AS plan_name', 'p.slug AS plan_slug', 's.plan_id AS plan_id',
                    '(SELECT COUNT(*) FROM {p}fc_workout_sessions ws
                        WHERE ws.user_id = u.id AND ws.status = %s) AS workouts_completed',
                ],
                // The count above binds one value, so the builder needs to know.
                'select_params' => ['completed'],
                'joins' => [
                    'INNER JOIN {p}fc_accounts a ON a.id = u.account_id',
                    "LEFT JOIN {p}fc_subscriptions s ON s.user_id = u.id AND s.status = 'active'",
                    'LEFT JOIN {p}fc_plans p ON p.id = s.plan_id',
                ],
                'search' => ['u.display_name', 'a.login', 'a.email'],
                'sort'   => [
                    'id'         => 'u.id',
                    'name'       => 'u.display_name',
                    'email'      => 'a.email',
                    'status'     => 'a.status',
                    'created_at' => 'u.created_at',
                    'last_login' => 'a.last_login_at',
                    'weight'     => 'u.weight_kg',
                ],
                'filters' => [
                    'status'  => ['expr' => 'a.status', 'op' => 'exact'],
                    'plan_id' => ['expr' => 's.plan_id', 'op' => 'exact'],
                    'trainer_id' => [
                        'expr' => 'u.id',
                        'op'   => 'in_subquery',
                        'sub'  => 'SELECT ut.user_id FROM {p}fc_user_trainers ut
                                    WHERE ut.trainer_id = %d AND ut.status = \'active\'',
                    ],
                ],
                'default_sort' => ['id', 'desc'],
                // Identity fields (login, email, password, role) are deliberately
                // absent: those belong to fc_accounts and changing them is an
                // account operation, not a profile edit. The prototype conflated
                // the two because it had one table.
                'writable' => [
                    'display_name'     => 'text',
                    'phone'            => 'text',
                    'date_of_birth'    => 'date',
                    'gender'           => 'text',
                    'height_cm'        => 'decimal',
                    'weight_kg'        => 'decimal',
                    'target_weight_kg' => 'decimal',
                    'fitness_level'    => 'text',
                    'fitness_goal'     => 'text',
                    'activity_level'   => 'text',
                    'timezone'         => 'text',
                ],
                'required'   => ['display_name'],
                'capability' => 'fc_manage_users',
                // Creating a member means creating an account first, which is an
                // identity operation with its own rules (password, email
                // uniqueness, welcome mail). Out of scope for a CRUD table.
                'creatable'  => false,
                'audit'      => false,
            ],

            'trainers' => [
                'table'  => 'fc_trainers',
                'alias'  => 't',
                'select' => [
                    't.id', 't.account_id', 't.display_name', 't.bio', 't.specialization',
                    't.avatar_url', 't.phone', 't.hourly_rate', 't.currency', 't.rating',
                    't.rating_count', 't.max_clients', 't.accepting_clients', 't.status',
                    't.created_at',
                    'a.login AS login', 'a.email AS email',
                    '(SELECT COUNT(*) FROM {p}fc_user_trainers ut
                        WHERE ut.trainer_id = t.id AND ut.status = %s) AS client_count',
                ],
                'select_params' => ['active'],
                'joins'  => ['INNER JOIN {p}fc_accounts a ON a.id = t.account_id'],
                'search' => ['t.display_name', 'a.email', 't.specialization'],
                'sort'   => [
                    'id'         => 't.id',
                    'name'       => 't.display_name',
                    'email'      => 'a.email',
                    'rating'     => 't.rating',
                    'status'     => 't.status',
                    'created_at' => 't.created_at',
                ],
                'filters' => [
                    'status'         => ['expr' => 't.status', 'op' => 'exact'],
                    'specialization' => ['expr' => 't.specialization', 'op' => 'like'],
                ],
                'default_sort' => ['id', 'desc'],
                'writable' => [
                    'display_name'      => 'text',
                    'bio'               => 'textarea',
                    'specialization'    => 'text',
                    'phone'             => 'text',
                    'avatar_url'        => 'url',
                    // Display-only per Q2 — trainers are traced, not paid — but
                    // still editable, because it shows on the public profile.
                    'hourly_rate'       => 'decimal',
                    'max_clients'       => 'int',
                    'accepting_clients' => 'bool',
                    'status'            => 'text',
                ],
                'required'   => ['display_name'],
                'capability' => 'fc_manage_trainers',
                'creatable'  => false,
                'audit'      => false,
                // Rating is computed from client feedback, never typed in. The
                // prototype had it as a free number field.
                'readonly'   => ['rating', 'rating_count'],
            ],

            'workouts' => [
                'table'  => 'fc_workouts',
                'alias'  => 'w',
                'select' => [
                    'w.id', 'w.workout_name', 'w.slug', 'w.description', 'w.workout_type',
                    'w.difficulty', 'w.estimated_duration_minutes', 'w.calories_burn_estimate',
                    'w.muscle_groups', 'w.equipment', 'w.cover_image_url', 'w.video_url',
                    'w.trainer_id', 'w.is_template', 'w.is_active', 'w.created_at',
                    '(SELECT COUNT(*) FROM {p}fc_exercises e WHERE e.workout_id = w.id) AS exercise_count',
                    '(SELECT COUNT(*) FROM {p}fc_user_workouts uw WHERE uw.workout_id = w.id) AS assigned_count',
                    'tr.display_name AS trainer_name',
                ],
                'joins'  => ['LEFT JOIN {p}fc_trainers tr ON tr.id = w.trainer_id'],
                'search' => ['w.workout_name', 'w.description', 'w.muscle_groups'],
                'sort'   => [
                    'id'         => 'w.id',
                    'name'       => 'w.workout_name',
                    'difficulty' => 'w.difficulty',
                    'duration'   => 'w.estimated_duration_minutes',
                    'created_at' => 'w.created_at',
                ],
                'filters' => [
                    'difficulty' => ['expr' => 'w.difficulty', 'op' => 'exact'],
                    'type'       => ['expr' => 'w.workout_type', 'op' => 'exact'],
                    'trainer_id' => ['expr' => 'w.trainer_id', 'op' => 'exact'],
                    'is_active'  => ['expr' => 'w.is_active', 'op' => 'exact'],
                ],
                'default_sort' => ['id', 'desc'],
                'writable' => [
                    'workout_name'               => 'text',
                    'description'                => 'textarea',
                    'workout_type'               => 'text',
                    'difficulty'                 => 'text',
                    'estimated_duration_minutes' => 'int',
                    'calories_burn_estimate'     => 'int',
                    'muscle_groups'              => 'json_list',
                    'equipment'                  => 'json_list',
                    'cover_image_url'            => 'url',
                    'video_url'                  => 'url',
                    'trainer_id'                 => 'int_or_null',
                    'is_template'                => 'bool',
                    'is_active'                  => 'bool',
                ],
                'required'   => ['workout_name'],
                'capability' => 'fc_manage_all',
                'creatable'  => true,
                'audit'      => false,
                /** Nested child rows edited on the same form. */
                'nested'     => 'exercises',
            ],

            'foods' => [
                'table'  => 'fc_foods',
                'alias'  => 'f',
                'select' => [
                    'f.id', 'f.name', 'f.brand', 'f.category', 'f.serving_size',
                    'f.serving_grams', 'f.calories', 'f.protein_g', 'f.carbs_g', 'f.fat_g',
                    'f.fiber_g', 'f.sugar_g', 'f.sodium_mg', 'f.barcode', 'f.source',
                    'f.is_verified', 'f.created_at',
                ],
                'joins'  => [],
                'search' => ['f.name', 'f.brand'],
                'sort'   => [
                    'id'       => 'f.id',
                    'name'     => 'f.name',
                    'calories' => 'f.calories',
                    'category' => 'f.category',
                ],
                'filters' => [
                    'category'    => ['expr' => 'f.category', 'op' => 'exact'],
                    'source'      => ['expr' => 'f.source', 'op' => 'exact'],
                    'is_verified' => ['expr' => 'f.is_verified', 'op' => 'exact'],
                ],
                'default_sort' => ['id', 'desc'],
                'writable' => [
                    'name'          => 'text',
                    'brand'         => 'text',
                    'category'      => 'text',
                    'serving_size'  => 'text',
                    'serving_grams' => 'decimal',
                    'calories'      => 'int',
                    'protein_g'     => 'decimal',
                    'carbs_g'       => 'decimal',
                    'fat_g'         => 'decimal',
                    'fiber_g'       => 'decimal',
                    'sugar_g'       => 'decimal',
                    'sodium_mg'     => 'decimal',
                    'barcode'       => 'text',
                    'is_verified'   => 'bool',
                ],
                'required'   => ['name'],
                'capability' => 'fc_manage_all',
                'creatable'  => true,
                'audit'      => false,
            ],

            /*
             * Meal logs and health entries are the two Q10 resources: staff may
             * edit a member's own records, so every write is audit-logged with
             * before/after and the row is stamped `source='admin'`.
             *
             * Both join the member's name — the prototype showed "User #1".
             */
            'meals' => [
                'table'  => 'fc_nutrition_logs',
                'alias'  => 'm',
                'select' => [
                    'm.id', 'm.user_id', 'm.log_date', 'm.logged_at', 'm.meal_type',
                    'm.total_calories', 'm.total_protein_g', 'm.total_carbs_g',
                    'm.total_fat_g', 'm.notes', 'm.source', 'm.last_edited_by_account_id',
                    'u.display_name AS user_name',
                    '(SELECT COUNT(*) FROM {p}fc_nutrition_log_items i
                        WHERE i.nutrition_log_id = m.id) AS item_count',
                ],
                'joins'  => ['INNER JOIN {p}fc_users u ON u.id = m.user_id'],
                'search' => ['u.display_name', 'm.notes'],
                'sort'   => [
                    'id'       => 'm.id',
                    'user'     => 'u.display_name',
                    'date'     => 'm.log_date',
                    'calories' => 'm.total_calories',
                ],
                'filters' => [
                    'user_id'   => ['expr' => 'm.user_id', 'op' => 'exact'],
                    'meal_type' => ['expr' => 'm.meal_type', 'op' => 'exact'],
                    'from'      => ['expr' => 'm.log_date', 'op' => 'gte'],
                    'to'        => ['expr' => 'm.log_date', 'op' => 'lte'],
                ],
                'default_sort' => ['id', 'desc'],
                'writable' => [
                    'meal_type' => 'text',
                    'log_date'  => 'date',
                    'notes'     => 'textarea',
                ],
                'required'   => [],
                'capability' => 'fc_edit_user_health',
                'creatable'  => false,
                'audit'      => true,
            ],

            'health-entries' => [
                'table'  => 'fc_health_stats',
                'alias'  => 'h',
                'select' => [
                    'h.id', 'h.user_id', 'h.record_date', 'h.recorded_at', 'h.weight_kg',
                    'h.body_fat_percentage', 'h.muscle_mass_kg', 'h.bmi',
                    'h.systolic_pressure', 'h.diastolic_pressure', 'h.heart_rate_resting',
                    'h.sleep_hours', 'h.mood_score', 'h.energy_score', 'h.stress_score',
                    'h.notes', 'h.source', 'h.last_edited_by_account_id',
                    'u.display_name AS user_name',
                ],
                'joins'  => ['INNER JOIN {p}fc_users u ON u.id = h.user_id'],
                'search' => ['u.display_name', 'h.notes'],
                'sort'   => [
                    'id'     => 'h.id',
                    'user'   => 'u.display_name',
                    'date'   => 'h.record_date',
                    'weight' => 'h.weight_kg',
                ],
                'filters' => [
                    'user_id' => ['expr' => 'h.user_id', 'op' => 'exact'],
                    'from'    => ['expr' => 'h.record_date', 'op' => 'gte'],
                    'to'      => ['expr' => 'h.record_date', 'op' => 'lte'],
                ],
                'default_sort' => ['id', 'desc'],
                'writable' => [
                    'record_date'         => 'date',
                    'weight_kg'           => 'decimal',
                    'body_fat_percentage' => 'decimal',
                    'muscle_mass_kg'      => 'decimal',
                    'systolic_pressure'   => 'int',
                    'diastolic_pressure'  => 'int',
                    'heart_rate_resting'  => 'int',
                    'sleep_hours'         => 'decimal',
                    'mood_score'          => 'int',
                    'energy_score'        => 'int',
                    'stress_score'        => 'int',
                    'notes'               => 'textarea',
                ],
                'required'   => [],
                'capability' => 'fc_edit_user_health',
                'creatable'  => false,
                'audit'      => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get(string $resource): ?array
    {
        return self::all()[$resource] ?? null;
    }

    /**
     * @return string[]
     */
    public static function names(): array
    {
        return array_keys(self::all());
    }
}
