<?php

namespace FitnessClub\Services;

use FitnessClub\Support\UserClock;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `GET /user/dashboard` — the whole screen in one response
 * (plans/02-api-contract.md#get-userdashboard--the-aggregate).
 *
 * The prototype's dashboard read six independent datasets, which is six
 * round-trips before anything renders. On a phone that is the difference between
 * a 400 ms and a two-second first paint, so the datasets are assembled here and
 * cached per member for a minute (see Support\DashboardCache).
 *
 * ## Reading tables whose services do not exist yet
 *
 * Nutrition, water, health and messaging are Phase 2 work, but their tables are
 * already migrated and three of the four keep **rollup rows** —
 * `fc_nutrition_days` holds the day's totals and goals, `fc_message_threads`
 * holds the last preview and the unread count. Reading a rollup is not
 * re-implementing the service that maintains it: NutritionService will still own
 * *recomputing* those totals when a meal is logged. So the payload is
 * contract-complete now, and W2.1/W2.4 change what writes those rows, not what
 * this reads.
 *
 * The one thing not faked: a section with no data returns its zero-state
 * (`consumed_ml: 0`, `null` goals, an empty list) rather than a plausible
 * number. An invented calorie total is worse than an empty ring.
 */
final class DashboardService
{
    private WorkoutService $workouts;

    private StreakService $streaks;

    private ProgressService $progress;

    private ActivityService $activity;

    /**
     * Rows read once and reused across the sections that want them. Instance
     * state, not static: a static cache would outlive the request in a CLI or
     * test process and hand the next member the previous one's profile.
     *
     * @var array<int,array<string,mixed>>
     */
    private array $profiles = [];

    /** @var array<string,array<string,mixed>> */
    private array $nutritionDays = [];

    public function __construct(
        ?WorkoutService $workouts = null,
        ?StreakService $streaks = null,
        ?ProgressService $progress = null,
        ?ActivityService $activity = null
    ) {
        $this->workouts = $workouts ?? new WorkoutService();
        $this->streaks  = $streaks ?? new StreakService();
        $this->progress = $progress ?? new ProgressService();
        $this->activity = $activity ?? new ActivityService();
    }

    /**
     * @param string|null $today The member's calendar day, `Y-m-d`. Defaults to
     *                           their real one; passed explicitly by tests,
     *                           which otherwise race the date rollover — every
     *                           section here reads "today", so a suite that
     *                           inserts a row at 23:59:59 and asserts at
     *                           00:00:00 is asking a different question than the
     *                           one it set up.
     * @return array<string,mixed>
     */
    public function forUser(int $wpUserId, int $fcUserId, ?string $today = null): array
    {
        $today   = $today ?? UserClock::today($fcUserId);
        $profile = $this->profile($fcUserId);
        $streak  = $this->streaks->current($fcUserId, $today);
        $cards   = $this->workoutCards($fcUserId, $today);

        return [
            'generated_at' => gmdate('c'),
            'date'         => $today,
            'greeting'     => [
                'name'        => $this->firstName($profile, $wpUserId),
                'streak_days' => $streak,
            ],
            'stats' => [
                'streak_days'              => $streak,
                'calories_burned_today'    => $this->caloriesBurnedToday($fcUserId, $today),
                'current_weight_kg'        => $this->floatOrNull($profile['weight_kg'] ?? null),
                'goal_progress_percentage' => $this->goalProgress($fcUserId, $profile),
            ],
            'todays_workout'   => $cards['today'],
            'upcoming_workout' => $cards['upcoming'],
            'water'            => $this->water($fcUserId, $today),
            'nutrition'        => $this->nutrition($fcUserId, $today),
            'weekly_chart'     => $this->progress->weeklySeries($fcUserId, $today),
            'monthly_stats'    => $this->progress->monthlyStats($fcUserId, $today),
            'recent_activity'  => $this->activity->feed($wpUserId, 5),
            'message_previews' => $this->messagePreviews($fcUserId),
        ];
    }

    /**
     * What to open, and what is next.
     *
     * Precedence for *today's* card: a session the member left running, then a
     * workout scheduled for today, then the next unfinished assignment. The
     * resumable one wins outright — someone who paused mid-workout wants that
     * workout back, whatever the calendar says.
     *
     * @return array{today:array<string,mixed>|null,upcoming:array<string,mixed>|null}
     */
    private function workoutCards(int $fcUserId, string $today): array
    {
        $listing = $this->workouts->listForUser($fcUserId, [
            'per_page' => 50,
            'status'   => null,
        ]);

        $items = $listing['items'] ?? [];

        $resumable = null;
        $scheduled = null;
        $next      = null;
        $upcoming  = null;

        foreach ($items as $item) {
            if (null !== $item['resumable_session_id'] && null === $resumable) {
                $resumable = $item;
            }

            $isOpen = 'completed' !== $item['status'] && 'archived' !== $item['status'];

            if ($isOpen && $item['scheduled_for'] === $today && null === $scheduled) {
                $scheduled = $item;
            }

            if ($isOpen && null === $next) {
                $next = $item;
            }

            // The soonest thing scheduled after today, whatever order the list
            // arrived in — "upcoming" has to be a comparison, not a position.
            if (
                $isOpen
                && is_string($item['scheduled_for'])
                && $item['scheduled_for'] > $today
                && (null === $upcoming || $item['scheduled_for'] < $upcoming['scheduled_for'])
            ) {
                $upcoming = $item;
            }
        }

        $todays = $resumable ?? $scheduled ?? $next;

        // Never show the same workout twice on one screen.
        if (null !== $upcoming && null !== $todays && $upcoming['id'] === $todays['id']) {
            $upcoming = null;
        }

        return ['today' => $todays, 'upcoming' => $upcoming];
    }

    /**
     * Calories from sessions completed on the member's today.
     */
    private function caloriesBurnedToday(int $fcUserId, string $today): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(calories_burned), 0)
               FROM {$wpdb->prefix}fc_workout_sessions
              WHERE user_id = %d AND status = 'completed' AND log_date = %s",
            $fcUserId,
            $today
        ));
    }

    /**
     * Progress from the starting weight toward the target, 0–100.
     *
     * Measured from the **earliest recorded** weight, not from today's, because
     * "68 % of the way there" is only meaningful against where the member
     * started. Null — not zero — when there is no target or no history to
     * measure from, and null when start and target are equal: a goal already met
     * is not a division by zero.
     *
     * @param array<string,mixed> $profile
     */
    private function goalProgress(int $fcUserId, array $profile): ?float
    {
        global $wpdb;

        $current = $this->floatOrNull($profile['weight_kg'] ?? null);
        $target  = $this->floatOrNull($profile['target_weight_kg'] ?? null);

        if (null === $current || null === $target) {
            return null;
        }

        $start = $this->floatOrNull($wpdb->get_var($wpdb->prepare(
            "SELECT weight_kg FROM {$wpdb->prefix}fc_health_stats
              WHERE user_id = %d AND weight_kg IS NOT NULL
              ORDER BY record_date ASC, id ASC
              LIMIT 1",
            $fcUserId
        )));

        if (null === $start || abs($start - $target) < 0.01) {
            return null;
        }

        $progress = (($start - $current) / ($start - $target)) * 100;

        return round(max(0, min(100, $progress)), 1);
    }

    /**
     * @return array{consumed_ml:int,goal_ml:int,glass_ml:int}
     */
    private function water(int $fcUserId, string $today): array
    {
        $day       = $this->nutritionDay($fcUserId, $today);
        $defaults  = (array) FitnessClub()->config('fitnessclub.defaults', []);

        return [
            'consumed_ml' => (int) ($day['water_ml'] ?? 0),
            'goal_ml'     => (int) ($day['goal_water_ml'] ?? null ?: ($defaults['water_goal_ml'] ?? 2000)),
            'glass_ml'    => (int) ($defaults['water_glass_ml'] ?? 250),
        ];
    }

    /**
     * Today's macros as value/goal pairs, the shape the rings and cards read.
     *
     * Goals come from the day row when the member has one (a food plan stamps
     * them there), otherwise from their profile's `nutrition_goals` — and are
     * null when neither exists, so a card can say "set a goal" instead of
     * drawing a ring against an imaginary 2 000 kcal.
     *
     * @return array<string,array{value:float|int,goal:float|int|null}>
     */
    private function nutrition(int $fcUserId, string $today): array
    {
        $day     = $this->nutritionDay($fcUserId, $today);
        $profile = $this->profile($fcUserId);
        $goals   = json_decode((string) ($profile['nutrition_goals'] ?? ''), true);
        $goals   = is_array($goals) ? $goals : [];

        $pair = static function ($value, $dayGoal, $profileGoal, bool $asInt) {
            $goal = $dayGoal ?? $profileGoal ?? null;

            return [
                'value' => $asInt ? (int) $value : round((float) $value, 1),
                'goal'  => null === $goal ? null : ($asInt ? (int) $goal : round((float) $goal, 1)),
            ];
        };

        return [
            'calories' => $pair(
                $day['total_calories'] ?? 0,
                $day['goal_calories'] ?? null,
                $goals['calories'] ?? null,
                true
            ),
            'protein_g' => $pair(
                $day['total_protein_g'] ?? 0,
                $day['goal_protein_g'] ?? null,
                $goals['protein_g'] ?? null,
                false
            ),
            'carbs_g' => $pair(
                $day['total_carbs_g'] ?? 0,
                $day['goal_carbs_g'] ?? null,
                $goals['carbs_g'] ?? null,
                false
            ),
            'fat_g' => $pair(
                $day['total_fat_g'] ?? 0,
                $day['goal_fat_g'] ?? null,
                $goals['fat_g'] ?? null,
                false
            ),
        ];
    }

    /**
     * Open threads, newest first — the "Messages" strip.
     *
     * Reads the thread rollup rather than the messages table: the preview and
     * the unread count are maintained on write, and a dashboard should not be
     * running a correlated subquery per thread to rebuild them.
     *
     * @return array<int,array<string,mixed>>
     */
    private function messagePreviews(int $fcUserId, int $limit = 3): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.id, t.last_message_at, t.last_message_preview, t.user_unread_count,
                    tr.display_name AS trainer_name, tr.avatar_url
               FROM {$wpdb->prefix}fc_message_threads t
               INNER JOIN {$wpdb->prefix}fc_trainers tr ON tr.id = t.trainer_id
              WHERE t.user_id = %d AND t.status = 'open' AND t.last_message_at IS NOT NULL
              ORDER BY t.last_message_at DESC
              LIMIT %d",
            $fcUserId,
            max(1, $limit)
        ), ARRAY_A);

        return array_map(static function (array $row): array {
            return [
                'thread_id'       => (int) $row['id'],
                'trainer_name'    => $row['trainer_name'],
                'avatar_url'      => $row['avatar_url'],
                'preview'         => $row['last_message_preview'],
                'unread_count'    => (int) $row['user_unread_count'],
                'last_message_at' => gmdate('c', strtotime((string) $row['last_message_at'] . ' UTC')),
            ];
        }, $rows ?: []);
    }

    /**
     * The member's profile row, read once per request however many sections
     * want it.
     *
     * @return array<string,mixed>
     */
    private function profile(int $fcUserId): array
    {
        global $wpdb;

        if (!isset($this->profiles[$fcUserId])) {
            $this->profiles[$fcUserId] = $wpdb->get_row($wpdb->prepare(
                "SELECT display_name, weight_kg, target_weight_kg, nutrition_goals, timezone
                   FROM {$wpdb->prefix}fc_users WHERE id = %d LIMIT 1",
                $fcUserId
            ), ARRAY_A) ?: [];
        }

        return $this->profiles[$fcUserId];
    }

    /**
     * Today's nutrition rollup row, or an empty array before anything is logged.
     *
     * @return array<string,mixed>
     */
    private function nutritionDay(int $fcUserId, string $today): array
    {
        global $wpdb;

        $key = $fcUserId . '|' . $today;

        if (!isset($this->nutritionDays[$key])) {
            $this->nutritionDays[$key] = $wpdb->get_row($wpdb->prepare(
                "SELECT water_ml, goal_water_ml, goal_calories, goal_protein_g, goal_carbs_g, goal_fat_g,
                        total_calories, total_protein_g, total_carbs_g, total_fat_g
                   FROM {$wpdb->prefix}fc_nutrition_days
                  WHERE user_id = %d AND log_date = %s LIMIT 1",
                $fcUserId,
                $today
            ), ARRAY_A) ?: [];
        }

        return $this->nutritionDays[$key];
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function firstName(array $profile, int $wpUserId): string
    {
        $name = trim((string) ($profile['display_name'] ?? ''));

        if ('' === $name) {
            $user = get_userdata($wpUserId);
            $name = $user ? (string) $user->display_name : '';
        }

        $first = explode(' ', trim($name))[0] ?? '';

        return '' === $first ? __('there', 'fitnessclub') : $first;
    }

    private function floatOrNull($value): ?float
    {
        return null === $value || '' === $value ? null : (float) $value;
    }
}
