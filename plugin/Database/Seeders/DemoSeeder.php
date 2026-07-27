<?php

namespace FitnessClub\Database\Seeders;

use FitnessClub\Auth\Capabilities;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The full prototype dataset. OPT-IN ONLY — never wired into database/seeders/, so
 * demo accounts never appear on a production site. Run via `php bones fitnessclub:seed --demo`.
 *
 * Every demo account signs in with `Seeder::DEMO_PASSWORD`. These are plugin
 * accounts (fc_accounts), not WordPress users — the plugin stopped creating
 * WordPress users when identity moved in-house.
 *
 * Deliberately exercises the paths easy to get wrong later:
 *  - Alex Morgan is coached by BOTH Sarah Chen and Mike Torres (Q3), Sarah primary,
 *    so multi-trainer behaviour is visible from the first screen.
 *  - The trainer links are all valid under the Q14 cap (Pro allows 2). The queue
 *    also holds one pending and one declined request (Q4).
 *  - Workouts are assigned by two different trainers, exercising Q13 shared-read.
 *  - One completed session with real per-set logs → volume, PRs, celebration data.
 */
class DemoSeeder extends Seeder
{
    /** @var array<string,int> */
    private array $users = [];
    /** @var array<string,int> */
    private array $trainers = [];
    /** @var array<string,int> */
    private array $workouts = [];
    /** @var array<string,int> */
    private array $foods = [];
    /** @var array<string,int> */
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
        foreach ($this->wpdb->get_results('SELECT id, slug FROM ' . $this->table('plans')) as $row) {
            $this->plans[$row->slug] = (int) $row->id;
        }
        if (!$this->plans) {
            $this->note('  ! no plans found — activate the plugin first (platform plans seed on activation)');
        }
    }

    private function seedTrainers(): void
    {
        foreach (DemoData::trainers() as $t) {
            $accountId = $this->account($t["login"], $t["email"], $t["name"], Capabilities::ROLE_TRAINER);
            if (!$accountId) {
                continue;
            }
            $this->trainers[$t['login']] = $this->upsert('trainers', ["account_id" => $accountId], [
                'display_name' => $t['name'], 'bio' => $t['bio'], 'specialization' => $t['specialization'],
                'phone' => $t['phone'], 'hourly_rate' => 65.00, 'currency' => 'USD',
                'rating' => $t['rating'], 'rating_count' => $t['rating_count'], 'status' => 'active',
                'accepting_clients' => 1, 'max_clients' => 40,
                'created_at' => $t['joined'] . ' 09:00:00', 'updated_at' => $this->now(),
            ]);
        }
        $this->note('trainers: ' . count($this->trainers));
    }

    private function seedUsers(): void
    {
        foreach (DemoData::users() as $u) {
            $accountId = $this->account($u["login"], $u["email"], $u["name"], Capabilities::ROLE_USER);
            if (!$accountId) {
                continue;
            }
            $this->users[$u['login']] = $this->upsert('users', ["account_id" => $accountId], [
                'display_name' => $u['name'], 'phone' => $u['phone'], 'date_of_birth' => $u['dob'],
                'gender' => $u['gender'], 'height_cm' => $u['height'], 'weight_kg' => $u['weight'],
                'target_weight_kg' => $u['target_weight'], 'body_fat_percentage' => $u['body_fat'],
                'fitness_level' => $u['level'], 'fitness_goal' => $u['goal'], 'activity_level' => $u['activity'],
                'nutrition_goals' => wp_json_encode(['calories' => 2400, 'protein_g' => 180, 'carbs_g' => 240, 'fat_g' => 80, 'water_ml' => 2000]),
                'preferences' => wp_json_encode([
                    'notifications' => ['workout_reminders' => true, 'trainer_messages' => true, 'progress_updates' => true, 'subscription_updates' => false],
                    'privacy' => ['profile_visible' => true, 'share_progress' => false], 'units' => 'metric',
                ]),
                'timezone' => 'UTC', 'locale' => 'en_US',
                'onboarded_at' => $u['joined'] . ' 10:00:00', 'created_at' => $u['joined'] . ' 10:00:00', 'updated_at' => $this->now(),
            ]);
        }
        $this->note('users: ' . count($this->users));
    }

    /**
     * All links are valid under Q14: a user needs an active plan, and active+pending
     * links may not exceed the plan's max_trainers. Alex (Pro, cap 2) sits at exactly
     * two active — a free fixture for the directory's "limit_reached" state.
     */
    private function seedAssignments(): void
    {
        $links = [
            ['alex.morgan', 'sarah.chen', 'active', 1],
            ['alex.morgan', 'mike.torres', 'active', 0],
            ['sam.wilson', 'lisa.park', 'active', 1],
            ['sam.wilson', 'david.kim', 'declined', 0],
            ['taylor.brooks', 'sarah.chen', 'active', 1],
            ['taylor.brooks', 'david.kim', 'pending', 0],
        ];

        $count = 0;
        foreach ($links as [$userLogin, $trainerLogin, $status, $primary]) {
            if (!isset($this->users[$userLogin], $this->trainers[$trainerLogin])) {
                continue;
            }
            // Converge if a prior run left this pair in a different status.
            $this->wpdb->query($this->wpdb->prepare(
                'DELETE FROM ' . $this->table('user_trainers') . ' WHERE user_id = %d AND trainer_id = %d AND status <> %s',
                $this->users[$userLogin],
                $this->trainers[$trainerLogin],
                $status
            ));
            $this->upsert('user_trainers', [
                'user_id' => $this->users[$userLogin], 'trainer_id' => $this->trainers[$trainerLogin], 'status' => $status,
            ], [
                'is_primary' => $primary,
                'requested_at' => gmdate('Y-m-d H:i:s', strtotime('-40 days')),
                'request_message' => 'pending' === $status ? 'Looking for help with a sustainable cutting plan.' : null,
                'responded_at' => 'pending' === $status ? null : gmdate('Y-m-d H:i:s', strtotime('-39 days')),
                'decline_reason' => 'declined' === $status ? 'At capacity for new clients this month.' : null,
                'assigned_date' => 'active' === $status ? $this->daysAgo(39) : null,
                'updated_at' => $this->now(),
            ]);
            $count++;
        }
        $this->note("trainer links: {$count} (alex has 2 active; 1 pending, 1 declined in the queue)");
    }

    private function seedFoods(): void
    {
        foreach (DemoData::foods() as [$name, $category, $kcal, $p, $c, $f]) {
            $this->foods[$name] = $this->upsert('foods', ['name' => $name], [
                'category' => $category, 'serving_size' => 'as listed', 'calories' => $kcal,
                'protein_g' => $p, 'carbs_g' => $c, 'fat_g' => $f, 'source' => 'system', 'is_verified' => 1, 'updated_at' => $this->now(),
            ]);
        }
        $this->note('foods: ' . count($this->foods));
    }

    private function seedWorkouts(): void
    {
        $exerciseCount = 0;
        foreach (DemoData::workouts() as $w) {
            $id = $this->upsert('workouts', ['slug' => $w['slug']], [
                'trainer_id' => $this->trainers[$w['trainer']] ?? null,
                'workout_name' => $w['name'], 'description' => $w['description'], 'workout_type' => $w['type'],
                'difficulty' => $w['difficulty'], 'estimated_duration_minutes' => $w['duration'],
                'calories_burn_estimate' => $w['calories'], 'muscle_groups' => wp_json_encode($w['muscles']),
                'equipment' => wp_json_encode($w['equipment']), 'is_template' => 1, 'is_active' => 1, 'updated_at' => $this->now(),
            ]);
            $this->workouts[$w['slug']] = $id;

            foreach ($w['exercises'] as $i => $ex) {
                [$name, $type, $sets, $reps, $weight, $rest, $notes] = $ex;
                $this->upsert('exercises', ['workout_id' => $id, 'exercise_name' => $name], [
                    'exercise_type' => $type, 'muscle_groups' => wp_json_encode($w['muscles']), 'notes' => $notes,
                    'default_sets' => $sets, 'default_reps' => $reps, 'default_weight_kg' => $weight,
                    'default_rest_seconds' => $rest, 'metric' => $ex[7] ?? 'reps', 'order_index' => $i, 'updated_at' => $this->now(),
                ]);
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
            $this->upsert('user_workouts', ['user_id' => $alex, 'workout_id' => $workoutId], [
                'assigned_by_account_id' => $this->trainerAccountIdFor($w['trainer']),
                'assigned_date' => $this->daysAgo(30 - $i), 'scheduled_for' => $this->daysAgo(-($i % 7)),
                'progress_percentage' => $w['progress'], 'times_completed' => (int) floor($w['progress'] / 25),
                'status' => $w['progress'] >= 100 ? 'completed' : ($w['progress'] > 0 ? 'in_progress' : 'assigned'),
                'updated_at' => $this->now(),
            ]);
            $count++;
        }
        $this->note("workout assignments: {$count} (two assigning trainers)");
    }

    private function seedSubscriptions(): void
    {
        $map = ['alex.morgan' => 'pro', 'jamie.lee' => 'free', 'sam.wilson' => 'pro', 'taylor.brooks' => 'team', 'jordan.riley' => 'pro', 'casey.nguyen' => 'free'];
        $count = 0;
        foreach ($map as $login => $planSlug) {
            if (!isset($this->users[$login], $this->plans[$planSlug])) {
                continue;
            }
            $isFree  = 'free' === $planSlug;
            $price   = $isFree ? 0.00 : ('team' === $planSlug ? 29.99 : 14.99);
            $trainer = 'alex.morgan' === $login ? ($this->trainers['sarah.chen'] ?? null) : null;

            $subId = $this->upsert('subscriptions', ['user_id' => $this->users[$login], 'plan_id' => $this->plans[$planSlug]], [
                'trainer_id' => $trainer, 'start_date' => $this->daysAgo(160), 'end_date' => $this->daysAgo(-22),
                'subscription_type' => 'monthly', 'status' => 'jordan.riley' === $login ? 'past_due' : 'active',
                'auto_renew' => 1, 'cancel_at_period_end' => 0, 'price_paid' => $price, 'currency' => 'USD',
                'gateway' => 'manual', 'updated_at' => $this->now(),
            ]);

            if (!$isFree) {
                foreach ([0, 30, 60] as $n => $ago) {
                    $this->upsert('payments', ['transaction_id' => "demo_{$login}_{$n}"], [
                        'user_id' => $this->users[$login], 'subscription_id' => $subId, 'amount' => $price, 'currency' => 'USD',
                        'payment_type' => 'subscription', 'gateway' => 'manual', 'payment_method' => 'card',
                        'status' => ('jordan.riley' === $login && 0 === $n) ? 'failed' : 'completed',
                        'payment_date' => $this->daysAgo($ago) . ' 12:00:00', 'updated_at' => $this->now(),
                    ]);
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
            $this->upsert('health_stats', ['user_id' => $alex, 'record_date' => $this->daysAgo($days)], [
                'recorded_at' => $this->daysAgo($days) . ' 07:00:00', 'weight_kg' => $kg,
                'body_fat_percentage' => $bodyFat[$days] ?? null, 'bmi' => round($kg / ($height ** 2), 2),
                'systolic_pressure' => 118 + ($days % 4), 'diastolic_pressure' => 76 + ($days % 3),
                'heart_rate_resting' => 60 + ($days % 6), 'sleep_hours' => 6.8 + (($days % 3) * 0.6),
                'mood_score' => 3 + ($days % 3), 'energy_score' => 5 + ($days % 4), 'stress_score' => 2 + ($days % 2),
                'source' => 'manual', 'updated_at' => $this->now(),
            ]);
            $rows++;
        }
        foreach (DemoData::measurements() as $m) {
            $this->upsert('body_measurements', ['user_id' => $alex, 'record_date' => $this->daysAgo($m['days'])], [
                'chest_cm' => $m['chest'], 'waist_cm' => $m['waist'], 'arms_cm' => $m['arms'],
                'thighs_cm' => $m['thighs'], 'shoulders_cm' => $m['shoulders'], 'updated_at' => $this->now(),
            ]);
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
            'water_ml' => 1250, 'goal_calories' => 2400, 'goal_protein_g' => 180.0, 'goal_carbs_g' => 240.0,
            'goal_fat_g' => 80.0, 'goal_water_ml' => 2000, 'total_calories' => 1650, 'total_protein_g' => 120.0,
            'total_carbs_g' => 180.0, 'total_fat_g' => 45.0, 'updated_at' => $this->now(),
        ]);
        foreach (DemoData::meals() as [$type, $time, $kcal, $p, $c, $f, $items]) {
            $logId = $this->upsert('nutrition_logs', ['user_id' => $alex, 'log_date' => $today, 'meal_type' => $type], [
                'logged_at' => $today . ' ' . $time, 'total_calories' => $kcal, 'total_protein_g' => $p,
                'total_carbs_g' => $c, 'total_fat_g' => $f, 'source' => 'manual', 'updated_at' => $this->now(),
            ]);
            foreach ($items as [$foodName, $qty]) {
                $foodId = $this->foods[$foodName] ?? null;
                if (!$foodId) {
                    continue;
                }
                $food = $this->wpdb->get_row($this->wpdb->prepare(
                    'SELECT calories, protein_g, carbs_g, fat_g FROM ' . $this->table('foods') . ' WHERE id = %d',
                    $foodId
                ));
                // Nutrients COPIED, not joined — correcting a food later must not rewrite history.
                $this->upsert('nutrition_log_items', ['nutrition_log_id' => $logId, 'food_id' => $foodId], [
                    'quantity' => $qty, 'unit' => 'serving', 'calories' => (int) round($food->calories * $qty),
                    'protein_g' => round($food->protein_g * $qty, 2), 'carbs_g' => round($food->carbs_g * $qty, 2), 'fat_g' => round($food->fat_g * $qty, 2),
                ]);
            }
        }
        $this->note('nutrition: 1 day, ' . count(DemoData::meals()) . ' meals with items');
    }

    private function seedCompletedSession(): void
    {
        $alex      = $this->users['alex.morgan'] ?? null;
        $workoutId = $this->workouts['upper-body-power'] ?? null;
        if (!$alex || !$workoutId) {
            return;
        }
        $started = gmdate('Y-m-d H:i:s', strtotime('-1 day 09:00:00'));
        $ended   = gmdate('Y-m-d H:i:s', strtotime('-1 day 09:45:30'));

        $sessionId = $this->upsert('workout_sessions', ['user_id' => $alex, 'workout_id' => $workoutId, 'log_date' => $this->daysAgo(1)], [
            'started_at' => $started, 'ended_at' => $ended, 'duration_seconds' => 2730, 'paused_seconds' => 145,
            'status' => 'completed', 'current_exercise_index' => 7, 'current_set_index' => 0, 'completion_percentage' => 100.00,
            'calories_burned' => 296, 'perceived_exertion' => 8, 'difficulty_rating' => 4, 'notes' => 'Felt strong. Bench moved well.', 'updated_at' => $this->now(),
        ]);

        $exercises = $this->wpdb->get_results($this->wpdb->prepare(
            'SELECT id, exercise_name, default_sets, default_reps, default_weight_kg FROM ' . $this->table('exercises') . ' WHERE workout_id = %d ORDER BY order_index',
            $workoutId
        ));
        $totalSets = 0;
        foreach ($exercises as $i => $ex) {
            $volume = $ex->default_sets * $ex->default_reps * $ex->default_weight_kg;
            $logId  = $this->upsert('exercise_logs', ['session_id' => $sessionId, 'exercise_id' => $ex->id], [
                'order_index' => $i, 'planned_sets' => $ex->default_sets, 'planned_reps' => $ex->default_reps, 'planned_weight_kg' => $ex->default_weight_kg,
                'actual_sets' => $ex->default_sets, 'actual_reps' => $ex->default_reps, 'actual_weight_kg' => $ex->default_weight_kg,
                'total_volume_kg' => $volume, 'was_skipped' => 0, 'updated_at' => $this->now(),
            ]);
            for ($s = 0; $s < (int) $ex->default_sets; $s++) {
                $this->upsert('set_logs', ['exercise_log_id' => $logId, 'set_index' => $s], [
                    'reps' => $ex->default_reps, 'weight_kg' => $ex->default_weight_kg, 'rest_taken_seconds' => 90,
                    'rpe' => 7 + ($s % 3), 'completed_at' => gmdate('Y-m-d H:i:s', strtotime($started) + ($i * 300) + ($s * 75)),
                ]);
                $totalSets++;
            }
        }
        foreach (DemoData::strengthSeries() as $name => $series) {
            $this->upsert('personal_records', ['user_id' => $alex, 'exercise_name' => $name, 'record_type' => 'max_weight'], [
                'value' => end($series), 'unit' => 'kg', 'session_id' => $sessionId, 'achieved_at' => $ended, 'updated_at' => $this->now(),
            ]);
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
            $last     = end($messages);
            $threadId = $this->upsert('message_threads', ['user_id' => $alex, 'trainer_id' => $trainerId], [
                'last_message_at' => gmdate('Y-m-d H:i:s', strtotime('-' . $last[2] . ' days')),
                'last_message_preview' => mb_substr($last[1], 0, 200),
                'user_unread_count' => 'sarah.chen' === $trainerLogin ? 2 : 0, 'trainer_unread_count' => 0,
                'status' => 'open', 'updated_at' => $this->now(),
            ]);
            $threads++;
            $alexAccountId    = $this->accountIdFor('alex.morgan');
            $trainerAccountId = $this->trainerAccountIdFor($trainerLogin);
            foreach ($messages as $i => [$direction, $text, $daysAgo]) {
                $this->upsert('messages', ['thread_id' => $threadId, 'message' => $text], [
                    'sender_account_id' => 'user_to_trainer' === $direction ? $alexAccountId : $trainerAccountId,
                    'direction' => $direction, 'is_read' => 'user_to_trainer' === $direction ? 1 : ($i < 2 ? 1 : 0),
                    'created_at' => gmdate('Y-m-d H:i:s', strtotime("-{$daysAgo} days") + ($i * 300)),
                ]);
                $msgs++;
            }
        }
        $this->note("messages: {$threads} threads, {$msgs} messages");
    }

    private function seedNotifications(): void
    {
        $accountId = $this->accountIdFor('alex.morgan');
        if (!$accountId) {
            return;
        }
        foreach (DemoData::notifications() as [$type, $title, $body, $icon, $color, $read, $daysAgo]) {
            $this->upsert('notifications', ['account_id' => $accountId, 'title' => $title], [
                'type' => $type, 'body' => $body, 'icon' => $icon, 'color' => $color, 'is_read' => $read,
                'read_at' => $read ? $this->now() : null, 'created_at' => gmdate('Y-m-d H:i:s', strtotime("-{$daysAgo} days")),
            ]);
        }
        $this->note('notifications: ' . count(DemoData::notifications()));
    }

    private function seedActivity(): void
    {
        $accountId = $this->accountIdFor('alex.morgan');
        if (!$accountId) {
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
            $this->upsert('activity_log', ['account_id' => $accountId, 'type' => $type, 'title' => $title], [
                'detail' => $detail, 'is_audit' => 0, 'created_at' => gmdate('Y-m-d H:i:s', strtotime("-{$daysAgo} days")),
            ]);
        }
        $this->note('activity: ' . count($items));
    }

    private function seedTickets(): void
    {
        $accountId = $this->accountIdFor('alex.morgan');
        if (!$accountId) {
            return;
        }
        $ticketId = $this->upsert('tickets', ['user_account_id' => $accountId, 'subject' => 'Cannot sync workout on mobile'], [
            'message' => 'My last two sessions did not appear on the dashboard until I refreshed.',
            'category' => 'technical', 'priority' => 'medium', 'status' => 'open', 'updated_at' => $this->now(),
        ]);
        $this->upsert('ticket_replies', ['ticket_id' => $ticketId, 'author_account_id' => $accountId], [
            'author_role' => 'user', 'message' => 'Still happening on the latest version.', 'is_internal_note' => 0, 'created_at' => $this->now(),
        ]);
        $this->note('support: 1 ticket, 1 reply');
    }

    private function accountIdFor(string $login): int
    {
        return $this->accountId($login);
    }

    /**
     * Null rather than 0 for an unknown trainer: the columns this feeds
     * (`assigned_by_account_id`, `sender_account_id`) are nullable provenance,
     * and 0 would be a reference to an account that cannot exist.
     */
    private function trainerAccountIdFor(string $login): ?int
    {
        return $this->accountId($login) ?: null;
    }
}
