<?php

namespace FitnessClub\Database\Seeders;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The full prototype dataset.
 *
 * OPT-IN ONLY — this is never wired into database/seeders/, because WP Bones
 * includes that directory on every activation and demo users must not appear on
 * a production site. Run it deliberately: `php bin/seed.php --demo`.
 *
 * Deliberately exercises the paths that are easy to get wrong later:
 *
 *  - Alex Morgan is assigned to BOTH Sarah Chen and Mike Torres (Q3), with Sarah
 *    primary, so multi-trainer behaviour is visible from the first screen rather
 *    than discovered in Phase 3.
 *  - One trainer link is left `pending` and one `declined` so the request queue
 *    (Q4) has something in it.
 *  - Workouts are assigned by two different trainers, so assignment attribution
 *    and the shared-read rule (Q13) are exercised.
 *  - One completed workout session with real per-set logs, so volume, PRs and
 *    the celebration payload all have data.
 */
class DemoSeeder extends Seeder
{
    /** @var array<string,int> login => fc_users.id */
    private array $users = [];

    /** @var array<string,int> login => fc_trainers.id */
    private array $trainers = [];

    /** @var array<string,int> slug => fc_workouts.id */
    private array $workouts = [];

    /** @var array<string,int> name => fc_foods.id */
    private array $foods = [];

    /** @var array<string,int> slug => fc_plans.id */
    private array $plans = [];

    public function run(): void
    {
        $this->loadPlans();
        $this->seedTrainers();
        $this->seedUsers();
        $this->seedAssignments();
        $this->seedFoods();
        $this->seedWorkouts();
        $this->seedUserWorkouts();
        $this->seedSubscriptions();
        $this->seedHealth();
        $this->seedNutrition();
        $this->seedCompletedSession();
        $this->seedMessages();
        $this->seedNotifications();
        $this->seedActivity();
        $this->seedTickets();
    }

    private function loadPlans(): void
    {
        $rows = $this->wpdb->get_results('SELECT id, slug FROM `' . $this->table('plans') . '`');

        foreach ($rows as $row) {
            $this->plans[$row->slug] = (int) $row->id;
        }

        if (!$this->plans) {
            $this->note('  ! no plans found — run PlatformPlansSeeder first');
        }
    }

    private function seedTrainers(): void
    {
        foreach (DemoData::trainers() as $t) {
            $wpId = $this->wpUser($t['login'], $t['email'], $t['name'], 'fc_trainer');

            if (!$wpId) {
                continue;
            }

            $this->trainers[$t['login']] = $this->upsert('trainers', ['wp_user_id' => $wpId], [
                'display_name'      => $t['name'],
                'bio'               => $t['bio'],
                'specialization'    => $t['specialization'],
                'phone'             => $t['phone'],
                'hourly_rate'       => 65.00,
                'currency'          => 'USD',
                'rating'            => $t['rating'],
                'rating_count'      => $t['rating_count'],
                'status'            => 'active',
                'accepting_clients' => 1,
                'max_clients'       => 40,
                'created_at'        => $t['joined'] . ' 09:00:00',
                'updated_at'        => $this->now(),
            ]);
        }

        $this->note('trainers: ' . count($this->trainers));
    }

    private function seedUsers(): void
    {
        foreach (DemoData::users() as $u) {
            $wpId = $this->wpUser($u['login'], $u['email'], $u['name'], 'fc_user');

            if (!$wpId) {
                continue;
            }

            $this->users[$u['login']] = $this->upsert('users', ['wp_user_id' => $wpId], [
                'display_name'        => $u['name'],
                'phone'               => $u['phone'],
                'date_of_birth'       => $u['dob'],
                'gender'              => $u['gender'],
                'height_cm'           => $u['height'],
                'weight_kg'           => $u['weight'],
                'target_weight_kg'    => $u['target_weight'],
                'body_fat_percentage' => $u['body_fat'],
                'fitness_level'       => $u['level'],
                'fitness_goal'        => $u['goal'],
                'activity_level'      => $u['activity'],
                'nutrition_goals'     => wp_json_encode([
                    'calories' => 2400, 'protein_g' => 180, 'carbs_g' => 240,
                    'fat_g' => 80, 'water_ml' => 2000,
                ]),
                'preferences' => wp_json_encode([
                    'notifications' => [
                        'workout_reminders'    => true,
                        'trainer_messages'     => true,
                        'progress_updates'     => true,
                        'subscription_updates' => false,
                    ],
                    'privacy' => ['profile_visible' => true, 'share_progress' => false],
                    'units'   => 'metric',
                ]),
                'timezone'     => 'UTC',
                'locale'       => 'en_US',
                'onboarded_at' => $u['joined'] . ' 10:00:00',
                'created_at'   => $u['joined'] . ' 10:00:00',
                'updated_at'   => $this->now(),
            ]);
        }

        $this->note('users: ' . count($this->users));
    }

    /**
     * Trainer links. Alex has two active trainers (Q3); the queue also carries a
     * pending request and a declined one so the Q4 flow has real states.
     */
    private function seedAssignments(): void
    {
        /*
         * Every link below is a VALID state under the Q14 rule: a user must hold
         * an active plan to have a trainer at all, and active + pending links may
         * not exceed that plan's max_trainers.
         *
         *   Alex (Pro, cap 2)     2 active   -> exactly at cap; the directory
         *                                       must render `limit_reached`
         *   Sam (Pro, cap 2)      1 active + 1 declined (declined does not count)
         *   Taylor (Team, cap 3)  1 active + 1 pending -> room for one more
         *   Jamie, Casey (Free, cap 0) -> no links at all, and cannot request
         *
         * Free-tier users deliberately have NO pending requests. An earlier
         * revision gave Jamie one, which the rule forbids — fixture data that
         * contradicts the spec teaches the wrong thing and hides bugs.
         */
        $links = [
            ['alex.morgan',   'sarah.chen',  'active',   1],
            ['alex.morgan',   'mike.torres', 'active',   0],
            ['sam.wilson',    'lisa.park',   'active',   1],
            ['sam.wilson',    'david.kim',   'declined', 0],
            ['taylor.brooks', 'sarah.chen',  'active',   1],
            ['taylor.brooks', 'david.kim',   'pending',  0],
        ];

        $count = 0;
        foreach ($links as [$userLogin, $trainerLogin, $status, $primary]) {
            if (!isset($this->users[$userLogin], $this->trainers[$trainerLogin])) {
                continue;
            }

            // Clear any link for this pair left behind by an earlier seed run in
            // a different status, so re-seeding converges instead of stacking.
            $this->wpdb->query($this->wpdb->prepare(
                'DELETE FROM `' . $this->table('user_trainers') . '`
                  WHERE user_id = %d AND trainer_id = %d AND status <> %s',
                $this->users[$userLogin],
                $this->trainers[$trainerLogin],
                $status
            ));

            $this->upsert(
                'user_trainers',
                [
                    'user_id'    => $this->users[$userLogin],
                    'trainer_id' => $this->trainers[$trainerLogin],
                    'status'     => $status,
                ],
                [
                    'is_primary'      => $primary,
                    'requested_at'    => gmdate('Y-m-d H:i:s', strtotime('-40 days')),
                    'request_message' => $status === 'pending'
                        ? 'Looking for help with a sustainable cutting plan.'
                        : null,
                    'responded_at'   => $status === 'pending' ? null : gmdate('Y-m-d H:i:s', strtotime('-39 days')),
                    'decline_reason' => $status === 'declined' ? 'At capacity for new clients this month.' : null,
                    'assigned_date'  => $status === 'active' ? $this->daysAgo(39) : null,
                    'updated_at'     => $this->now(),
                ]
            );
            $count++;
        }

        $this->note("trainer links: {$count} (alex has 2 active, 1 pending, 1 declined in queue)");
    }

    private function seedFoods(): void
    {
        foreach (DemoData::foods() as [$name, $category, $kcal, $p, $c, $f]) {
            $this->foods[$name] = $this->upsert('foods', ['name' => $name], [
                'category'    => $category,
                'serving_size' => 'as listed',
                'calories'    => $kcal,
                'protein_g'   => $p,
                'carbs_g'     => $c,
                'fat_g'       => $f,
                'source'      => 'system',
                'is_verified' => 1,
                'updated_at'  => $this->now(),
            ]);
        }

        $this->note('foods: ' . count($this->foods));
    }

    private function seedWorkouts(): void
    {
        $exerciseCount = 0;

        foreach (DemoData::workouts() as $w) {
            $trainerId = $this->trainers[$w['trainer']] ?? null;

            $id = $this->upsert('workouts', ['slug' => $w['slug']], [
                'trainer_id'                 => $trainerId,
                'workout_name'               => $w['name'],
                'description'                => $w['description'],
                'workout_type'               => $w['type'],
                'difficulty'                 => $w['difficulty'],
                'estimated_duration_minutes' => $w['duration'],
                'calories_burn_estimate'     => $w['calories'],
                'muscle_groups'              => wp_json_encode($w['muscles']),
                'equipment'                  => wp_json_encode($w['equipment']),
                'is_template'                => 1,
                'is_active'                  => 1,
                'updated_at'                 => $this->now(),
            ]);

            $this->workouts[$w['slug']] = $id;

            foreach ($w['exercises'] as $i => $ex) {
                [$name, $type, $sets, $reps, $weight, $rest, $notes] = $ex;
                $metric = $ex[7] ?? 'reps';

                $this->upsert(
                    'exercises',
                    ['workout_id' => $id, 'exercise_name' => $name],
                    [
                        'exercise_type'        => $type,
                        'muscle_groups'        => wp_json_encode($w['muscles']),
                        'notes'                => $notes,
                        'default_sets'         => $sets,
                        'default_reps'         => $reps,
                        'default_weight_kg'    => $weight,
                        'default_rest_seconds' => $rest,
                        'metric'               => $metric,
                        'order_index'          => $i,
                        'updated_at'           => $this->now(),
                    ]
                );
                $exerciseCount++;
            }
        }

        $this->note('workouts: ' . count($this->workouts) . " ({$exerciseCount} exercises)");
    }

    private function seedUserWorkouts(): void
    {
        $alex = $this->users['alex.morgan'] ?? null;

        if (!$alex) {
            return;
        }

        $count = 0;
        foreach (DemoData::workouts() as $i => $w) {
            $workoutId = $this->workouts[$w['slug']] ?? null;

            if (!$workoutId) {
                continue;
            }

            // Attribute assignments to the trainer who authored the workout, so
            // the "assigned by" label and the Q13 shared-read rule have data.
            $assigner = $this->trainerWpUserId($w['trainer']);

            $this->upsert(
                'user_workouts',
                ['user_id' => $alex, 'workout_id' => $workoutId],
                [
                    'assigned_by_wp_user_id' => $assigner,
                    'assigned_date'          => $this->daysAgo(30 - $i),
                    'scheduled_for'          => $this->daysAgo(-($i % 7)),
                    'progress_percentage'    => $w['progress'],
                    'times_completed'        => (int) floor($w['progress'] / 25),
                    'status'                 => $w['progress'] >= 100 ? 'completed'
                        : ($w['progress'] > 0 ? 'in_progress' : 'assigned'),
                    'updated_at' => $this->now(),
                ]
            );
            $count++;
        }

        $this->note("workout assignments: {$count} (two different assigning trainers)");
    }

    private function seedSubscriptions(): void
    {
        $map = ['alex.morgan' => 'pro', 'jamie.lee' => 'free', 'sam.wilson' => 'pro',
                'taylor.brooks' => 'team', 'jordan.riley' => 'pro', 'casey.nguyen' => 'free'];

        $count = 0;
        foreach ($map as $login => $planSlug) {
            if (!isset($this->users[$login], $this->plans[$planSlug])) {
                continue;
            }

            $planId  = $this->plans[$planSlug];
            $isFree  = $planSlug === 'free';
            $price   = $isFree ? 0.00 : ($planSlug === 'team' ? 29.99 : 14.99);
            $trainer = $login === 'alex.morgan' ? ($this->trainers['sarah.chen'] ?? null) : null;

            $subId = $this->upsert(
                'subscriptions',
                ['user_id' => $this->users[$login], 'plan_id' => $planId],
                [
                    'trainer_id'           => $trainer,
                    'start_date'           => $this->daysAgo(160),
                    'end_date'             => $this->daysAgo(-22),
                    'subscription_type'    => 'monthly',
                    'status'               => $login === 'jordan.riley' ? 'past_due' : 'active',
                    'auto_renew'           => 1,
                    'cancel_at_period_end' => 0,
                    'price_paid'           => $price,
                    'currency'             => 'USD',
                    'gateway'              => 'manual',
                    'updated_at'           => $this->now(),
                ]
            );

            if (!$isFree) {
                foreach ([0, 30, 60] as $n => $ago) {
                    $this->upsert(
                        'payments',
                        ['transaction_id' => "demo_{$login}_{$n}"],
                        [
                            'user_id'         => $this->users[$login],
                            'subscription_id' => $subId,
                            'amount'          => $price,
                            'currency'        => 'USD',
                            'payment_type'    => 'subscription',
                            'gateway'         => 'manual',
                            'payment_method'  => 'card',
                            'status'          => ($login === 'jordan.riley' && $n === 0) ? 'failed' : 'completed',
                            'payment_date'    => $this->daysAgo($ago) . ' 12:00:00',
                            'updated_at'      => $this->now(),
                        ]
                    );
                }
            }

            $count++;
        }

        $this->note("subscriptions: {$count} (+ payment history)");
    }

    private function seedHealth(): void
    {
        $alex = $this->users['alex.morgan'] ?? null;

        if (!$alex) {
            return;
        }

        $bodyFat = [];
        foreach (DemoData::bodyFatSeries() as [$days, $value]) {
            $bodyFat[$days] = $value;
        }

        $height = 1.75;
        $rows   = 0;

        foreach (DemoData::weightSeries() as [$days, $kg]) {
            $this->upsert(
                'health_stats',
                ['user_id' => $alex, 'record_date' => $this->daysAgo($days)],
                [
                    'recorded_at'         => $this->daysAgo($days) . ' 07:00:00',
                    'weight_kg'           => $kg,
                    'body_fat_percentage' => $bodyFat[$days] ?? null,
                    'bmi'                 => round($kg / ($height ** 2), 2),
                    'systolic_pressure'   => 118 + ($days % 4),
                    'diastolic_pressure'  => 76 + ($days % 3),
                    'heart_rate_resting'  => 60 + ($days % 6),
                    'sleep_hours'         => 6.8 + (($days % 3) * 0.6),
                    'mood_score'          => 3 + ($days % 3),
                    'energy_score'        => 5 + ($days % 4),
                    'stress_score'        => 2 + ($days % 2),
                    'source'              => 'manual',
                    'updated_at'          => $this->now(),
                ]
            );
            $rows++;
        }

        foreach (DemoData::measurements() as $m) {
            $this->upsert(
                'body_measurements',
                ['user_id' => $alex, 'record_date' => $this->daysAgo($m['days'])],
                [
                    'chest_cm'     => $m['chest'],
                    'waist_cm'     => $m['waist'],
                    'arms_cm'      => $m['arms'],
                    'thighs_cm'    => $m['thighs'],
                    'shoulders_cm' => $m['shoulders'],
                    'updated_at'   => $this->now(),
                ]
            );
        }

        $this->note("health stats: {$rows} days + " . count(DemoData::measurements()) . ' measurement snapshots');
    }

    private function seedNutrition(): void
    {
        $alex = $this->users['alex.morgan'] ?? null;

        if (!$alex) {
            return;
        }

        $today = gmdate('Y-m-d');

        $this->upsert('nutrition_days', ['user_id' => $alex, 'log_date' => $today], [
            'water_ml'        => 1250,
            'goal_calories'   => 2400,
            'goal_protein_g'  => 180.0,
            'goal_carbs_g'    => 240.0,
            'goal_fat_g'      => 80.0,
            'goal_water_ml'   => 2000,
            'total_calories'  => 1650,
            'total_protein_g' => 120.0,
            'total_carbs_g'   => 180.0,
            'total_fat_g'     => 45.0,
            'updated_at'      => $this->now(),
        ]);

        foreach (DemoData::meals() as [$type, $time, $kcal, $p, $c, $f, $items]) {
            $logId = $this->upsert(
                'nutrition_logs',
                ['user_id' => $alex, 'log_date' => $today, 'meal_type' => $type],
                [
                    'logged_at'       => $today . ' ' . $time,
                    'total_calories'  => $kcal,
                    'total_protein_g' => $p,
                    'total_carbs_g'   => $c,
                    'total_fat_g'     => $f,
                    'source'          => 'manual',
                    'updated_at'      => $this->now(),
                ]
            );

            foreach ($items as [$foodName, $qty]) {
                $foodId = $this->foods[$foodName] ?? null;

                if (!$foodId) {
                    continue;
                }

                $food = $this->wpdb->get_row($this->wpdb->prepare(
                    'SELECT calories, protein_g, carbs_g, fat_g FROM `' . $this->table('foods') . '` WHERE id = %d',
                    $foodId
                ));

                // Nutrients are COPIED, not joined — correcting a food later must
                // not silently rewrite history.
                $this->upsert(
                    'nutrition_log_items',
                    ['nutrition_log_id' => $logId, 'food_id' => $foodId],
                    [
                        'quantity'  => $qty,
                        'unit'      => 'serving',
                        'calories'  => (int) round($food->calories * $qty),
                        'protein_g' => round($food->protein_g * $qty, 2),
                        'carbs_g'   => round($food->carbs_g * $qty, 2),
                        'fat_g'     => round($food->fat_g * $qty, 2),
                    ]
                );
            }
        }

        $this->note('nutrition: 1 day, ' . count(DemoData::meals()) . ' meals with items');
    }

    /**
     * One fully-logged completed session, so volume, PR detection and the
     * celebration payload all have real data behind them.
     */
    private function seedCompletedSession(): void
    {
        $alex      = $this->users['alex.morgan'] ?? null;
        $workoutId = $this->workouts['upper-body-power'] ?? null;

        if (!$alex || !$workoutId) {
            return;
        }

        $started = gmdate('Y-m-d H:i:s', strtotime('-1 day 09:00:00'));
        $ended   = gmdate('Y-m-d H:i:s', strtotime('-1 day 09:45:30'));

        $sessionId = $this->upsert(
            'workout_sessions',
            ['user_id' => $alex, 'workout_id' => $workoutId, 'log_date' => $this->daysAgo(1)],
            [
                'started_at'             => $started,
                'ended_at'               => $ended,
                'duration_seconds'       => 2730,
                'paused_seconds'         => 145,
                'status'                 => 'completed',
                'current_exercise_index' => 7,
                'current_set_index'      => 0,
                'completion_percentage'  => 100.00,
                'calories_burned'        => 296,
                'perceived_exertion'     => 8,
                'difficulty_rating'      => 4,
                'notes'                  => 'Felt strong. Bench moved well.',
                'updated_at'             => $this->now(),
            ]
        );

        $exercises = $this->wpdb->get_results($this->wpdb->prepare(
            'SELECT id, exercise_name, default_sets, default_reps, default_weight_kg
               FROM `' . $this->table('exercises') . '`
              WHERE workout_id = %d ORDER BY order_index',
            $workoutId
        ));

        $totalSets = 0;

        foreach ($exercises as $i => $ex) {
            $volume = $ex->default_sets * $ex->default_reps * $ex->default_weight_kg;

            $logId = $this->upsert(
                'exercise_logs',
                ['session_id' => $sessionId, 'exercise_id' => $ex->id],
                [
                    'order_index'       => $i,
                    'planned_sets'      => $ex->default_sets,
                    'planned_reps'      => $ex->default_reps,
                    'planned_weight_kg' => $ex->default_weight_kg,
                    'actual_sets'       => $ex->default_sets,
                    'actual_reps'       => $ex->default_reps,
                    'actual_weight_kg'  => $ex->default_weight_kg,
                    'total_volume_kg'   => $volume,
                    'was_skipped'       => 0,
                    'updated_at'        => $this->now(),
                ]
            );

            for ($s = 0; $s < (int) $ex->default_sets; $s++) {
                $this->upsert(
                    'set_logs',
                    ['exercise_log_id' => $logId, 'set_index' => $s],
                    [
                        'reps'               => $ex->default_reps,
                        'weight_kg'          => $ex->default_weight_kg,
                        'rest_taken_seconds' => 90,
                        'rpe'                => 7 + ($s % 3),
                        'completed_at'       => gmdate('Y-m-d H:i:s', strtotime($started) + ($i * 300) + ($s * 75)),
                    ]
                );
                $totalSets++;
            }
        }

        // Personal records, matching the prototype's celebration screen.
        foreach (DemoData::strengthSeries() as $name => $series) {
            $this->upsert(
                'personal_records',
                ['user_id' => $alex, 'exercise_name' => $name, 'record_type' => 'max_weight'],
                [
                    'value'       => end($series),
                    'unit'        => 'kg',
                    'session_id'  => $sessionId,
                    'achieved_at' => $ended,
                    'updated_at'  => $this->now(),
                ]
            );
        }

        $this->note("completed session: 1 ({$totalSets} set logs, 3 personal records)");
    }

    private function seedMessages(): void
    {
        $alex = $this->users['alex.morgan'] ?? null;

        if (!$alex) {
            return;
        }

        $threads = 0;
        $msgs    = 0;

        foreach (DemoData::conversations() as $trainerLogin => $messages) {
            $trainerId = $this->trainers[$trainerLogin] ?? null;

            if (!$trainerId) {
                continue;
            }

            $last      = end($messages);
            $threadId  = $this->upsert(
                'message_threads',
                ['user_id' => $alex, 'trainer_id' => $trainerId],
                [
                    'last_message_at'      => gmdate('Y-m-d H:i:s', strtotime('-' . $last[2] . ' days')),
                    'last_message_preview' => mb_substr($last[1], 0, 200),
                    'user_unread_count'    => $trainerLogin === 'sarah.chen' ? 2 : 0,
                    'trainer_unread_count' => 0,
                    'status'               => 'open',
                    'updated_at'           => $this->now(),
                ]
            );
            $threads++;

            $alexWpId    = $this->userWpUserId('alex.morgan');
            $trainerWpId = $this->trainerWpUserId($trainerLogin);

            foreach ($messages as $i => [$direction, $text, $daysAgo]) {
                $this->upsert(
                    'messages',
                    ['thread_id' => $threadId, 'message' => $text],
                    [
                        'sender_wp_user_id' => $direction === 'user_to_trainer' ? $alexWpId : $trainerWpId,
                        'direction'         => $direction,
                        'is_read'           => $direction === 'user_to_trainer' ? 1 : ($i < 2 ? 1 : 0),
                        'created_at'        => gmdate('Y-m-d H:i:s', strtotime("-{$daysAgo} days") + ($i * 300)),
                    ]
                );
                $msgs++;
            }
        }

        $this->note("messages: {$threads} threads, {$msgs} messages");
    }

    private function seedNotifications(): void
    {
        $wpId = $this->userWpUserId('alex.morgan');

        if (!$wpId) {
            return;
        }

        foreach (DemoData::notifications() as [$type, $title, $body, $icon, $color, $read, $daysAgo]) {
            $this->upsert(
                'notifications',
                ['wp_user_id' => $wpId, 'title' => $title],
                [
                    'type'       => $type,
                    'body'       => $body,
                    'icon'       => $icon,
                    'color'      => $color,
                    'is_read'    => $read,
                    'read_at'    => $read ? $this->now() : null,
                    'created_at' => gmdate('Y-m-d H:i:s', strtotime("-{$daysAgo} days")),
                ]
            );
        }

        $this->note('notifications: ' . count(DemoData::notifications()));
    }

    private function seedActivity(): void
    {
        $wpId = $this->userWpUserId('alex.morgan');

        if (!$wpId) {
            return;
        }

        $items = [
            ['workout.completed', 'Upper Body Power', 'Completed all 8 exercises', 1],
            ['nutrition.logged', 'Lunch Logged', '580 calories', 0],
            ['health.updated', 'Weight Updated', '78.5 kg', 0],
            ['workout.partial', 'Leg Day Destroyer', 'Completed 5 of 7 exercises', 1],
            ['nutrition.water', 'Water Intake', '8 glasses completed', 1],
        ];

        foreach ($items as [$type, $title, $detail, $daysAgo]) {
            $this->upsert(
                'activity_log',
                ['wp_user_id' => $wpId, 'type' => $type, 'title' => $title],
                [
                    'detail'     => $detail,
                    'is_audit'   => 0,
                    'created_at' => gmdate('Y-m-d H:i:s', strtotime("-{$daysAgo} days")),
                ]
            );
        }

        $this->note('activity: ' . count($items));
    }

    private function seedTickets(): void
    {
        $wpId = $this->userWpUserId('alex.morgan');

        if (!$wpId) {
            return;
        }

        $ticketId = $this->upsert(
            'tickets',
            ['user_wp_user_id' => $wpId, 'subject' => 'Cannot sync workout on mobile'],
            [
                'message'    => 'My last two sessions did not appear on the dashboard until I refreshed.',
                'category'   => 'technical',
                'priority'   => 'medium',
                'status'     => 'open',
                'updated_at' => $this->now(),
            ]
        );

        $this->upsert(
            'ticket_replies',
            ['ticket_id' => $ticketId, 'author_wp_user_id' => $wpId],
            [
                'author_role'      => 'user',
                'message'          => 'Still happening on the latest version.',
                'is_internal_note' => 0,
                'created_at'       => $this->now(),
            ]
        );

        $this->note('support: 1 ticket, 1 reply');
    }

    private function userWpUserId(string $login): int
    {
        $user = get_user_by('login', $login);

        return $user ? (int) $user->ID : 0;
    }

    private function trainerWpUserId(string $login): ?int
    {
        $user = get_user_by('login', $login);

        return $user ? (int) $user->ID : null;
    }
}
