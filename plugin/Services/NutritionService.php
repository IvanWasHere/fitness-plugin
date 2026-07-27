<?php

namespace FitnessClub\Services;

use FitnessClub\Support\DomainException;
use FitnessClub\Support\UserClock;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Meals, macros, water and goals (plans/03-backend.md, W2.1).
 *
 * ## Three rules that shape everything here
 *
 * **1. Nutrient values are copied, never joined.** A log item stores the
 * calories and macros as they were when it was logged. If an administrator
 * corrects a food's macros next month, last month's diary must not silently
 * change underneath the person who wrote it — a food diary whose history edits
 * itself is not a record of anything.
 *
 * **2. Totals are denormalised and recomputed on every child write.** Both the
 * meal row and the day rollup carry sums. Summing on read would be correct but
 * would put a two-table aggregate on the dashboard's critical path; recomputing
 * on write puts it where nobody is waiting.
 *
 * **3. Goals are snapshotted per day.** `fc_nutrition_days` holds the goals that
 * were in force on that date, so raising a calorie target next week does not
 * retroactively rewrite whether last week was met.
 *
 * Water lives on the day, not on a meal. The spec put `water_intake_ml` on each
 * meal row, which makes "5 of 8 glasses today" a SUM over rows that may not
 * exist — a member who drank water but logged no food would show zero.
 */
final class NutritionService
{
    /** Meal buckets, in the order the day is read. */
    public const MEAL_TYPES = ['breakfast', 'lunch', 'dinner', 'snack', 'other'];

    /**
     * The whole Nutrition screen for one day: meals, totals, goals, water.
     *
     * @return array<string,mixed>
     */
    public function day(int $fcUserId, ?string $date = null): array
    {
        $date = $this->normaliseDate($date) ?? UserClock::today($fcUserId);

        $rollup   = $this->dayRow($fcUserId, $date);
        $meals    = $this->mealsFor($fcUserId, $date);
        $goals    = $this->goalsFor($fcUserId, $rollup);
        $defaults = (array) FitnessClub()->config('fitnessclub.defaults', []);

        $consumed = (int) ($rollup['water_ml'] ?? 0);
        $goalWater = (int) ($goals['water_ml'] ?? $defaults['water_goal_ml'] ?? 2000);
        $glass     = max(1, (int) ($defaults['water_glass_ml'] ?? 250));

        return [
            'date'   => $date,
            'meals'  => $meals,
            'totals' => [
                'calories'  => (int) ($rollup['total_calories'] ?? 0),
                'protein_g' => round((float) ($rollup['total_protein_g'] ?? 0), 1),
                'carbs_g'   => round((float) ($rollup['total_carbs_g'] ?? 0), 1),
                'fat_g'     => round((float) ($rollup['total_fat_g'] ?? 0), 1),
            ],
            'goals' => $goals,
            'water' => [
                'consumed_ml' => $consumed,
                'goal_ml'     => $goalWater,
                'glass_ml'    => $glass,
                // Whole glasses, for the row of dots. Rounded down: a half-drunk
                // glass is not a glass.
                'glasses'      => (int) floor($consumed / $glass),
                'glasses_goal' => (int) ceil($goalWater / $glass),
            ],
        ];
    }

    /**
     * Logged meals across a date range, newest day first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function range(int $fcUserId, string $from, string $to): array
    {
        global $wpdb;

        $from = $this->normaliseDate($from) ?? UserClock::today($fcUserId);
        $to   = $this->normaliseDate($to) ?? UserClock::today($fcUserId);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT log_date,
                    COUNT(*) AS meals,
                    COALESCE(SUM(total_calories), 0) AS calories,
                    COALESCE(SUM(total_protein_g), 0) AS protein_g,
                    COALESCE(SUM(total_carbs_g), 0) AS carbs_g,
                    COALESCE(SUM(total_fat_g), 0) AS fat_g
               FROM {$wpdb->prefix}fc_nutrition_logs
              WHERE user_id = %d AND log_date BETWEEN %s AND %s
              GROUP BY log_date
              ORDER BY log_date DESC",
            $fcUserId,
            $from,
            $to
        ), ARRAY_A);

        return array_map(static fn(array $row): array => [
            'date'      => $row['log_date'],
            'meals'     => (int) $row['meals'],
            'calories'  => (int) $row['calories'],
            'protein_g' => round((float) $row['protein_g'], 1),
            'carbs_g'   => round((float) $row['carbs_g'], 1),
            'fat_g'     => round((float) $row['fat_g'], 1),
        ], $rows ?: []);
    }

    /**
     * Log a meal and its items.
     *
     * @param array<string,mixed> $payload meal_type, log_date, logged_at, notes, items[]
     * @return array<string,mixed> The stored meal.
     */
    public function logMeal(int $fcUserId, array $payload): array
    {
        global $wpdb;

        $items = $this->prepareItems($payload['items'] ?? []);

        if ([] === $items) {
            throw new DomainException(
                'fc_meal_empty',
                __('A meal needs at least one item.', 'fitnessclub'),
                400
            );
        }

        $date = $this->normaliseDate($payload['log_date'] ?? null) ?? UserClock::today($fcUserId);
        $now  = gmdate('Y-m-d H:i:s');

        $wpdb->query('START TRANSACTION');

        try {
            $wpdb->insert($wpdb->prefix . 'fc_nutrition_logs', [
                'user_id'    => $fcUserId,
                'log_date'   => $date,
                'logged_at'  => $this->normaliseDateTime($payload['logged_at'] ?? null) ?? $now,
                'meal_type'  => $this->normaliseMealType($payload['meal_type'] ?? null),
                'notes'      => $this->trimOrNull($payload['notes'] ?? null),
                'source'     => 'manual',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $mealId = (int) $wpdb->insert_id;

            $this->writeItems($mealId, $items);
            $this->recomputeMeal($mealId);
            $this->recomputeDay($fcUserId, $date);

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        $this->announce($fcUserId, 'nutrition.logged');

        return $this->meal($fcUserId, $mealId);
    }

    /**
     * Replace a meal's contents.
     *
     * Items are replaced wholesale rather than diffed: the edit modal submits
     * the meal as it should now be, and reconciling item identity across an edit
     * buys nothing when the child rows carry no history of their own.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function updateMeal(int $fcUserId, int $mealId, array $payload): array
    {
        global $wpdb;

        $existing = $this->mealRow($fcUserId, $mealId);
        $oldDate  = (string) $existing['log_date'];

        $hasItems = array_key_exists('items', $payload);
        $items    = $hasItems ? $this->prepareItems($payload['items']) : [];

        if ($hasItems && [] === $items) {
            throw new DomainException(
                'fc_meal_empty',
                __('A meal needs at least one item.', 'fitnessclub'),
                400
            );
        }

        $now     = gmdate('Y-m-d H:i:s');
        $newDate = array_key_exists('log_date', $payload)
            ? ($this->normaliseDate($payload['log_date']) ?? $oldDate)
            : $oldDate;

        $wpdb->query('START TRANSACTION');

        try {
            $fields = ['updated_at' => $now, 'log_date' => $newDate];

            if (array_key_exists('meal_type', $payload)) {
                $fields['meal_type'] = $this->normaliseMealType($payload['meal_type']);
            }
            if (array_key_exists('notes', $payload)) {
                $fields['notes'] = $this->trimOrNull($payload['notes']);
            }
            if (array_key_exists('logged_at', $payload)) {
                $fields['logged_at'] = $this->normaliseDateTime($payload['logged_at']) ?? $now;
            }

            $wpdb->update($wpdb->prefix . 'fc_nutrition_logs', $fields, ['id' => $mealId]);

            if ($hasItems) {
                $wpdb->delete($wpdb->prefix . 'fc_nutrition_log_items', ['nutrition_log_id' => $mealId]);
                $this->writeItems($mealId, $items);
            }

            $this->recomputeMeal($mealId);
            $this->recomputeDay($fcUserId, $newDate);

            // Moving a meal to another date leaves the old day's rollup stale.
            if ($newDate !== $oldDate) {
                $this->recomputeDay($fcUserId, $oldDate);
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        $this->announce($fcUserId, 'nutrition.updated');

        return $this->meal($fcUserId, $mealId);
    }

    public function deleteMeal(int $fcUserId, int $mealId): void
    {
        global $wpdb;

        $existing = $this->mealRow($fcUserId, $mealId);
        $date     = (string) $existing['log_date'];

        // Items cascade on the foreign key; the delete is scoped by user_id as
        // well as id so a mismatched pair can never remove somebody else's row.
        $wpdb->delete($wpdb->prefix . 'fc_nutrition_logs', ['id' => $mealId, 'user_id' => $fcUserId]);

        $this->recomputeDay($fcUserId, $date);
        $this->announce($fcUserId, 'nutrition.deleted');
    }

    /**
     * One meal with its items.
     *
     * @return array<string,mixed>
     */
    public function meal(int $fcUserId, int $mealId): array
    {
        return $this->presentMeal($this->mealRow($fcUserId, $mealId), $this->itemsFor([$mealId]));
    }

    /**
     * Set the day's water, by delta or absolute total.
     *
     * The prototype does both: "+1 glass" sends a delta, clicking the fifth dot
     * directly sends a total. A delta-only API would make that second gesture a
     * read-then-write race with itself on a double tap.
     *
     * @return array<string,mixed> The water block, as `day()` returns it.
     */
    public function setWater(int $fcUserId, ?int $deltaMl, ?int $totalMl, ?string $date = null): array
    {
        global $wpdb;

        $date = $this->normaliseDate($date) ?? UserClock::today($fcUserId);

        if (null === $deltaMl && null === $totalMl) {
            throw new DomainException(
                'fc_water_missing_amount',
                __('Send either delta_ml or total_ml.', 'fitnessclub'),
                400
            );
        }

        $this->ensureDayRow($fcUserId, $date);

        if (null !== $totalMl) {
            $wpdb->update(
                $wpdb->prefix . 'fc_nutrition_days',
                ['water_ml' => max(0, $totalMl), 'updated_at' => gmdate('Y-m-d H:i:s')],
                ['user_id' => $fcUserId, 'log_date' => $date]
            );
        } else {
            // One statement, so two taps in flight cannot both read the same
            // value and write the same increment. GREATEST keeps a "-1 glass"
            // from going negative.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}fc_nutrition_days
                    SET water_ml = GREATEST(0, water_ml + %d), updated_at = UTC_TIMESTAMP()
                  WHERE user_id = %d AND log_date = %s",
                $deltaMl,
                $fcUserId,
                $date
            ));
        }

        $this->announce($fcUserId, 'nutrition.water');

        return $this->day($fcUserId, $date)['water'];
    }

    /**
     * Update the member's nutrition goals.
     *
     * Written to the profile (the standing goal) **and** to today's rollup (the
     * snapshot). Past days keep the goals they were judged against.
     *
     * @param array<string,mixed> $goals calories, protein_g, carbs_g, fat_g, water_ml
     * @return array<string,mixed>
     */
    public function setGoals(int $fcUserId, array $goals, ?string $date = null): array
    {
        global $wpdb;

        $date  = $this->normaliseDate($date) ?? UserClock::today($fcUserId);
        $clean = [];

        foreach (['calories', 'protein_g', 'carbs_g', 'fat_g', 'water_ml'] as $key) {
            if (!array_key_exists($key, $goals) || null === $goals[$key]) {
                continue;
            }

            $clean[$key] = 'calories' === $key || 'water_ml' === $key
                ? max(0, (int) $goals[$key])
                : round(max(0, (float) $goals[$key]), 2);
        }

        if ([] === $clean) {
            throw new DomainException(
                'fc_goals_empty',
                __('No goals were supplied.', 'fitnessclub'),
                400
            );
        }

        $stored = $this->profileGoals($fcUserId);
        $merged = array_merge($stored, $clean);

        $wpdb->update(
            $wpdb->prefix . 'fc_users',
            ['nutrition_goals' => wp_json_encode($merged), 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $fcUserId]
        );

        $this->ensureDayRow($fcUserId, $date);

        $wpdb->update(
            $wpdb->prefix . 'fc_nutrition_days',
            [
                'goal_calories'  => $merged['calories'] ?? null,
                'goal_protein_g' => $merged['protein_g'] ?? null,
                'goal_carbs_g'   => $merged['carbs_g'] ?? null,
                'goal_fat_g'     => $merged['fat_g'] ?? null,
                'goal_water_ml'  => $merged['water_ml'] ?? null,
                'updated_at'     => gmdate('Y-m-d H:i:s'),
            ],
            ['user_id' => $fcUserId, 'log_date' => $date]
        );

        $this->announce($fcUserId, 'nutrition.goals');

        return $this->day($fcUserId, $date)['goals'];
    }

    // ------------------------------------------------------------------ internals

    /**
     * Recompute one meal's totals from its items.
     */
    private function recomputeMeal(int $mealId): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_nutrition_logs l
                JOIN (
                    SELECT %d AS id,
                           COALESCE(SUM(calories), 0)  AS c,
                           COALESCE(SUM(protein_g), 0) AS p,
                           COALESCE(SUM(carbs_g), 0)   AS cb,
                           COALESCE(SUM(fat_g), 0)     AS f
                      FROM {$wpdb->prefix}fc_nutrition_log_items
                     WHERE nutrition_log_id = %d
                ) t ON t.id = l.id
                SET l.total_calories  = t.c,
                    l.total_protein_g = t.p,
                    l.total_carbs_g   = t.cb,
                    l.total_fat_g     = t.f",
            $mealId,
            $mealId
        ));
    }

    /**
     * Recompute a day's rollup from its meals.
     *
     * The row is created if absent — a day with meals must have a rollup, and
     * this is the only place that guarantee is enforced.
     */
    public function recomputeDay(int $fcUserId, string $date): void
    {
        global $wpdb;

        $this->ensureDayRow($fcUserId, $date);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_nutrition_days d
                JOIN (
                    SELECT COALESCE(SUM(total_calories), 0)  AS c,
                           COALESCE(SUM(total_protein_g), 0) AS p,
                           COALESCE(SUM(total_carbs_g), 0)   AS cb,
                           COALESCE(SUM(total_fat_g), 0)     AS f
                      FROM {$wpdb->prefix}fc_nutrition_logs
                     WHERE user_id = %d AND log_date = %s
                ) t
                SET d.total_calories  = t.c,
                    d.total_protein_g = t.p,
                    d.total_carbs_g   = t.cb,
                    d.total_fat_g     = t.f,
                    d.updated_at      = UTC_TIMESTAMP()
              WHERE d.user_id = %d AND d.log_date = %s",
            $fcUserId,
            $date,
            $fcUserId,
            $date
        ));
    }

    /**
     * Create the day's rollup row if it does not exist, seeding it with the
     * member's standing goals so the snapshot is right from the first write.
     */
    private function ensureDayRow(int $fcUserId, string $date): void
    {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_nutrition_days WHERE user_id = %d AND log_date = %s LIMIT 1",
            $fcUserId,
            $date
        ));

        if (null !== $exists) {
            return;
        }

        $goals = $this->profileGoals($fcUserId);
        $now   = gmdate('Y-m-d H:i:s');

        // INSERT IGNORE, not a plain insert: two writes racing to create the
        // same day would both pass the check above, and the unique key on
        // (user_id, log_date) turns the loser into an error rather than a no-op.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}fc_nutrition_days
                (user_id, log_date, water_ml, goal_calories, goal_protein_g, goal_carbs_g, goal_fat_g,
                 goal_water_ml, created_at, updated_at)
             VALUES (%d, %s, 0, %s, %s, %s, %s, %s, %s, %s)",
            $fcUserId,
            $date,
            $goals['calories'] ?? null,
            $goals['protein_g'] ?? null,
            $goals['carbs_g'] ?? null,
            $goals['fat_g'] ?? null,
            $goals['water_ml'] ?? null,
            $now,
            $now
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function dayRow(int $fcUserId, string $date): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT water_ml, goal_calories, goal_protein_g, goal_carbs_g, goal_fat_g, goal_water_ml,
                    total_calories, total_protein_g, total_carbs_g, total_fat_g
               FROM {$wpdb->prefix}fc_nutrition_days
              WHERE user_id = %d AND log_date = %s LIMIT 1",
            $fcUserId,
            $date
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /**
     * The goals in force for a day: the snapshot if there is one, else the
     * member's standing goals, else null per field.
     *
     * Null rather than a default — a goal nobody set is not a goal, and a ring
     * drawn against an invented 2 000 kcal is a lie the member never told.
     *
     * @param array<string,mixed>|null $rollup
     * @return array<string,int|float|null>
     */
    private function goalsFor(int $fcUserId, ?array $rollup): array
    {
        $profile = $this->profileGoals($fcUserId);

        $pick = static function (?array $rollup, string $column, array $profile, string $key) {
            $snapshot = $rollup[$column] ?? null;

            return null !== $snapshot ? $snapshot : ($profile[$key] ?? null);
        };

        $calories = $pick($rollup, 'goal_calories', $profile, 'calories');
        $protein  = $pick($rollup, 'goal_protein_g', $profile, 'protein_g');
        $carbs    = $pick($rollup, 'goal_carbs_g', $profile, 'carbs_g');
        $fat      = $pick($rollup, 'goal_fat_g', $profile, 'fat_g');
        $water    = $pick($rollup, 'goal_water_ml', $profile, 'water_ml');

        return [
            'calories'  => null === $calories ? null : (int) $calories,
            'protein_g' => null === $protein ? null : round((float) $protein, 1),
            'carbs_g'   => null === $carbs ? null : round((float) $carbs, 1),
            'fat_g'     => null === $fat ? null : round((float) $fat, 1),
            'water_ml'  => null === $water ? null : (int) $water,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function profileGoals(int $fcUserId): array
    {
        global $wpdb;

        $json = $wpdb->get_var($wpdb->prepare(
            "SELECT nutrition_goals FROM {$wpdb->prefix}fc_users WHERE id = %d LIMIT 1",
            $fcUserId
        ));

        $goals = json_decode((string) $json, true);

        return is_array($goals) ? $goals : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function mealsFor(int $fcUserId, string $date): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, log_date, logged_at, meal_type, total_calories, total_protein_g,
                    total_carbs_g, total_fat_g, notes, source
               FROM {$wpdb->prefix}fc_nutrition_logs
              WHERE user_id = %d AND log_date = %s
              ORDER BY logged_at ASC, id ASC",
            $fcUserId,
            $date
        ), ARRAY_A) ?: [];

        $items = $this->itemsFor(array_map(static fn(array $r): int => (int) $r['id'], $rows));

        return array_map(fn(array $row): array => $this->presentMeal($row, $items), $rows);
    }

    /**
     * Items for several meals at once, keyed by meal id — one query rather than
     * one per meal.
     *
     * @param int[] $mealIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function itemsFor(array $mealIds): array
    {
        global $wpdb;

        if ([] === $mealIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($mealIds), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
        $sql = "SELECT id, nutrition_log_id, food_id, custom_name, quantity, unit,
                       calories, protein_g, carbs_g, fat_g
                  FROM {$wpdb->prefix}fc_nutrition_log_items
                 WHERE nutrition_log_id IN ({$placeholders})
                 ORDER BY id ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$mealIds), ARRAY_A) ?: [];

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['nutrition_log_id']][] = [
                'id'          => (int) $row['id'],
                'food_id'     => null === $row['food_id'] ? null : (int) $row['food_id'],
                'name'        => $row['custom_name'],
                'quantity'    => round((float) $row['quantity'], 2),
                'unit'        => $row['unit'],
                'calories'    => (int) $row['calories'],
                'protein_g'   => round((float) $row['protein_g'], 1),
                'carbs_g'     => round((float) $row['carbs_g'], 1),
                'fat_g'       => round((float) $row['fat_g'], 1),
            ];
        }

        return $grouped;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,array<int,array<string,mixed>>> $items
     * @return array<string,mixed>
     */
    private function presentMeal(array $row, array $items): array
    {
        $id = (int) $row['id'];

        return [
            'id'        => $id,
            'date'      => $row['log_date'],
            'logged_at' => $this->iso($row['logged_at']),
            'meal_type' => $row['meal_type'],
            'calories'  => (int) $row['total_calories'],
            'protein_g' => round((float) $row['total_protein_g'], 1),
            'carbs_g'   => round((float) $row['total_carbs_g'], 1),
            'fat_g'     => round((float) $row['total_fat_g'], 1),
            'notes'     => $row['notes'],
            // 'admin' means a trainer or administrator entered this on the
            // member's behalf (Q10) — the screen labels it so the member is not
            // left wondering where a meal came from.
            'source'    => $row['source'] ?? 'manual',
            'items'     => $items[$id] ?? [],
        ];
    }

    /**
     * Load a meal, scoped by owner. An id belonging to somebody else is a 404,
     * never a 403 — a 403 confirms the row exists.
     *
     * @return array<string,mixed>
     */
    private function mealRow(int $fcUserId, int $mealId): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_nutrition_logs WHERE id = %d AND user_id = %d LIMIT 1",
            $mealId,
            $fcUserId
        ), ARRAY_A);

        if (!is_array($row)) {
            throw new DomainException(
                'fc_meal_not_found',
                __('That meal does not exist.', 'fitnessclub'),
                404
            );
        }

        return $row;
    }

    /**
     * Normalise submitted items, resolving foods to their nutrients.
     *
     * Nutrients come from the food row **scaled by quantity**, or from the
     * caller for a free-text entry. A caller may not override a known food's
     * macros: that is the one way the copied-not-joined rule could be turned
     * into "whatever the client felt like".
     *
     * @param mixed $submitted
     * @return array<int,array<string,mixed>>
     */
    private function prepareItems($submitted): array
    {
        if (!is_array($submitted)) {
            return [];
        }

        $foods = $this->foodsById($submitted);
        $items = [];

        foreach ($submitted as $item) {
            if (!is_array($item)) {
                continue;
            }

            $foodId   = isset($item['food_id']) ? (int) $item['food_id'] : 0;
            $quantity = isset($item['quantity']) ? max(0.01, (float) $item['quantity']) : 1.0;
            $food     = $foods[$foodId] ?? null;

            if ($foodId > 0 && null === $food) {
                throw new DomainException(
                    'fc_food_not_found',
                    __('One of those foods no longer exists.', 'fitnessclub'),
                    404
                );
            }

            if (null !== $food) {
                $items[] = [
                    'food_id'     => $foodId,
                    'custom_name' => trim((string) $food['name']),
                    'quantity'    => $quantity,
                    'unit'        => $this->trimOrNull($item['unit'] ?? null) ?? $food['serving_size'],
                    'calories'    => (int) round((int) $food['calories'] * $quantity),
                    'protein_g'   => round((float) $food['protein_g'] * $quantity, 2),
                    'carbs_g'     => round((float) $food['carbs_g'] * $quantity, 2),
                    'fat_g'       => round((float) $food['fat_g'] * $quantity, 2),
                ];

                continue;
            }

            $name = $this->trimOrNull($item['custom_name'] ?? $item['name'] ?? null);

            // A free-text item with no name and no food is not an item.
            if (null === $name) {
                continue;
            }

            $items[] = [
                'food_id'     => null,
                'custom_name' => $name,
                'quantity'    => $quantity,
                'unit'        => $this->trimOrNull($item['unit'] ?? null),
                'calories'    => max(0, (int) ($item['calories'] ?? 0)),
                'protein_g'   => round(max(0, (float) ($item['protein_g'] ?? 0)), 2),
                'carbs_g'     => round(max(0, (float) ($item['carbs_g'] ?? 0)), 2),
                'fat_g'       => round(max(0, (float) ($item['fat_g'] ?? 0)), 2),
            ];
        }

        return $items;
    }

    /**
     * Fetch every referenced food in one query.
     *
     * @param array<int,mixed> $submitted
     * @return array<int,array<string,mixed>>
     */
    private function foodsById(array $submitted): array
    {
        global $wpdb;

        $ids = [];
        foreach ($submitted as $item) {
            if (is_array($item) && !empty($item['food_id'])) {
                $ids[] = (int) $item['food_id'];
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));
        if ([] === $ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
        $sql = "SELECT id, name, serving_size, calories, protein_g, carbs_g, fat_g
                  FROM {$wpdb->prefix}fc_foods WHERE id IN ({$placeholders})";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$ids), ARRAY_A) ?: [];

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }

        return $map;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function writeItems(int $mealId, array $items): void
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        foreach ($items as $item) {
            $wpdb->insert($wpdb->prefix . 'fc_nutrition_log_items', $item + [
                'nutrition_log_id' => $mealId,
                'created_at'       => $now,
            ]);
        }
    }

    private function normaliseMealType($value): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, self::MEAL_TYPES, true) ? $value : 'other';
    }

    private function normaliseDate($value): ?string
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', trim($value));

        return $date && $date->format('Y-m-d') === trim($value) ? $date->format('Y-m-d') : null;
    }

    private function normaliseDateTime($value): ?string
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        $timestamp = strtotime($value);

        return false === $timestamp ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function trimOrNull($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    private function iso($value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $timestamp = strtotime((string) $value . ' UTC');

        return false === $timestamp ? null : gmdate('c', $timestamp);
    }

    /**
     * Every nutrition write moves a number on the dashboard, so say so — the
     * cached aggregate drops itself. See Support\DashboardCache.
     */
    private function announce(int $fcUserId, string $reason): void
    {
        do_action('fitnessclub/user_data_changed', $fcUserId, $reason);
    }
}
