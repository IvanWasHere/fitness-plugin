<?php

namespace FitnessClub\Services;

use FitnessClub\Support\DomainException;
use FitnessClub\Support\UserClock;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Health stats and body measurements (plans/03-backend.md, W2.2).
 *
 * ## Four rules that shape everything here
 *
 * **1. A day is a row, and writing it is an upsert.** `UNIQUE (user_id,
 * record_date)` is the whole storage model: logging weight in the morning and
 * sleep at night must produce one record for the day, not two half-empty ones.
 *
 * **2. A partial write never erases.** Only the fields actually present in the
 * payload are written. The evening's sleep entry leaves the morning's weight
 * alone — the alternative is a form that silently deletes whatever it did not
 * ask about, which is how a member loses a reading they can no longer remember.
 *
 * **3. BMI is derived, never accepted.** Computed from the member's height and
 * the weight the row ends up holding, and NULL when height is unknown. A client
 * that could post its own BMI could post one that disagrees with the weight
 * beside it.
 *
 * **4. `fc_users.weight_kg` is a cache of the newest reading, recomputed rather
 * than assigned.** Every write and every delete re-derives it from the latest
 * row that actually has a weight. Assigning it directly looks equivalent and is
 * not: backdating last Tuesday's weigh-in would overwrite "current weight" with
 * an older number, and deleting today's entry would leave the profile quoting a
 * reading that no longer exists.
 */
final class HealthService
{
    /**
     * The member-writable columns, with the bounds each is validated against.
     *
     * A registry rather than a list of `if`s: the args schema, the upsert, the
     * history query and the summary cards all need to agree about what a metric
     * is, and four hand-maintained copies of that agreement drift.
     *
     * `decimals` is what the value is rounded and presented to; `integer` fields
     * round to whole numbers because a heart rate of 62.4 bpm is a false
     * precision no cuff or watch reports.
     *
     * @var array<string,array{column:string,min:float,max:float,decimals:int,unit:string,label:string}>
     */
    public const FIELDS = [
        'weight_kg' => [
            'column' => 'weight_kg', 'min' => 20, 'max' => 500, 'decimals' => 1,
            'unit' => 'kg', 'label' => 'Weight',
        ],
        'body_fat_percentage' => [
            'column' => 'body_fat_percentage', 'min' => 1, 'max' => 70, 'decimals' => 1,
            'unit' => '%', 'label' => 'Body fat',
        ],
        'muscle_mass_kg' => [
            'column' => 'muscle_mass_kg', 'min' => 5, 'max' => 200, 'decimals' => 1,
            'unit' => 'kg', 'label' => 'Muscle mass',
        ],
        'systolic_pressure' => [
            'column' => 'systolic_pressure', 'min' => 60, 'max' => 260, 'decimals' => 0,
            'unit' => 'mmHg', 'label' => 'Systolic',
        ],
        'diastolic_pressure' => [
            'column' => 'diastolic_pressure', 'min' => 30, 'max' => 200, 'decimals' => 0,
            'unit' => 'mmHg', 'label' => 'Diastolic',
        ],
        'heart_rate_resting' => [
            'column' => 'heart_rate_resting', 'min' => 25, 'max' => 220, 'decimals' => 0,
            'unit' => 'bpm', 'label' => 'Resting heart rate',
        ],
        'sleep_hours' => [
            'column' => 'sleep_hours', 'min' => 0, 'max' => 24, 'decimals' => 1,
            'unit' => 'hrs', 'label' => 'Sleep',
        ],
        'mood_score' => [
            'column' => 'mood_score', 'min' => 1, 'max' => 5, 'decimals' => 0,
            'unit' => '/5', 'label' => 'Mood',
        ],
        'energy_score' => [
            'column' => 'energy_score', 'min' => 1, 'max' => 10, 'decimals' => 0,
            'unit' => '/10', 'label' => 'Energy',
        ],
        'stress_score' => [
            'column' => 'stress_score', 'min' => 1, 'max' => 5, 'decimals' => 0,
            'unit' => '/5', 'label' => 'Stress',
        ],
    ];

    /**
     * Body-measurement columns. Same shape, separate table — measurement cadence
     * is monthly while weight is daily, and merging them yields a row that is
     * ~90 % NULL (plans/01-database.md).
     *
     * @var array<string,array{min:float,max:float,label:string}>
     */
    public const MEASUREMENTS = [
        'chest_cm'     => ['min' => 30, 'max' => 250, 'label' => 'Chest'],
        'waist_cm'     => ['min' => 30, 'max' => 250, 'label' => 'Waist'],
        'hips_cm'      => ['min' => 30, 'max' => 250, 'label' => 'Hips'],
        'arms_cm'      => ['min' => 10, 'max' => 100, 'label' => 'Arms'],
        'thighs_cm'    => ['min' => 20, 'max' => 150, 'label' => 'Thighs'],
        'shoulders_cm' => ['min' => 40, 'max' => 250, 'label' => 'Shoulders'],
        'neck_cm'      => ['min' => 20, 'max' => 100, 'label' => 'Neck'],
        'calves_cm'    => ['min' => 15, 'max' => 100, 'label' => 'Calves'],
    ];

    /**
     * The Health screen's cards: what to show, in what order, and how.
     *
     * `derived` marks BMI, which has no column and cannot be entered — the
     * prototype's detail modal offered an "Add Entry" box for it anyway, which
     * would have been a number the server recomputes away on the next weigh-in.
     *
     * @var array<int,array{key:string,label:string,field:?string,icon:string,tone:string,unit:string,decimals:int}>
     */
    private const CARDS = [
        ['key' => 'weight', 'label' => 'Weight', 'field' => 'weight_kg',
            'icon' => 'weight', 'tone' => 'var(--accent)', 'unit' => 'kg', 'decimals' => 1],
        ['key' => 'body_fat', 'label' => 'Body Fat', 'field' => 'body_fat_percentage',
            'icon' => 'user', 'tone' => 'var(--accent2)', 'unit' => '%', 'decimals' => 1],
        ['key' => 'bmi', 'label' => 'BMI', 'field' => null,
            'icon' => 'target', 'tone' => 'var(--info)', 'unit' => '', 'decimals' => 1],
        ['key' => 'sleep', 'label' => 'Sleep', 'field' => 'sleep_hours',
            'icon' => 'moon', 'tone' => 'var(--purple)', 'unit' => 'hrs', 'decimals' => 1],
        ['key' => 'heart_rate', 'label' => 'Resting Heart Rate', 'field' => 'heart_rate_resting',
            'icon' => 'heart', 'tone' => 'var(--danger)', 'unit' => 'bpm', 'decimals' => 0],
        ['key' => 'blood_pressure', 'label' => 'Blood Pressure', 'field' => 'systolic_pressure',
            'icon' => 'gauge', 'tone' => 'var(--accent2)', 'unit' => 'mmHg', 'decimals' => 0],
        ['key' => 'mood', 'label' => 'Mood', 'field' => 'mood_score',
            'icon' => 'smile', 'tone' => 'var(--warning)', 'unit' => '/5', 'decimals' => 0],
        ['key' => 'energy', 'label' => 'Energy', 'field' => 'energy_score',
            'icon' => 'zap', 'tone' => 'var(--accent)', 'unit' => '/10', 'decimals' => 0],
    ];

    /** Mood 1–5 as words, for the card that shows a face rather than a fraction. */
    private const MOOD_LABELS = [1 => 'Very low', 2 => 'Low', 3 => 'Okay', 4 => 'Good', 5 => 'Great'];

    /**
     * How far back a card looks for the reading it displays.
     *
     * A resting heart rate from March is not "your resting heart rate" in July,
     * and a card that quotes it without saying when reads as current. Beyond the
     * window the card goes empty and says so; the history is still in the modal.
     */
    private const STALE_AFTER_DAYS = 90;

    // ------------------------------------------------------------------ reads

    /**
     * `GET /health/summary` — the eight cards, BMI, and the latest measurements.
     *
     * One request for the whole screen, for the same reason the dashboard is one
     * call: the prototype's Health screen read two stores and then indexed
     * `d.weight[d.weight.length - 1]` on each of seven arrays, which throws on a
     * member who has logged nothing.
     *
     * @return array<string,mixed>
     */
    public function summary(int $fcUserId): array
    {
        $today   = UserClock::today($fcUserId);
        $profile = $this->profile($fcUserId);
        $latest  = $this->latestPerField($fcUserId);
        $height  = $this->height($profile);

        $cards = [];

        foreach (self::CARDS as $card) {
            $cards[] = 'bmi' === $card['key']
                ? $this->bmiCard($card, $latest, $height, $today)
                : $this->metricCard($card, $latest, $today);
        }

        return [
            'date'         => $today,
            'height_cm'    => null === $height ? null : round($height, 1),
            'cards'        => $cards,
            'measurements' => $this->latestMeasurements($fcUserId),
            // The member's own target, so the screen can show weight against it
            // without a second request. Null when they have not set one.
            'target_weight_kg' => null === $profile['target_weight_kg']
                ? null
                : round((float) $profile['target_weight_kg'], 1),
        ];
    }

    /**
     * `GET /health/stats?from=&to=&metrics=` — the history behind a card.
     *
     * Rows, not series: the detail modal lists days, and the charts in W2.3 read
     * from ProgressService, which buckets. Returning both shapes from one
     * endpoint would mean one of them is always the wrong one.
     *
     * @param string[] $metrics Restrict to these field keys; empty means all.
     * @return array<string,mixed>
     */
    public function stats(int $fcUserId, ?string $from = null, ?string $to = null, array $metrics = []): array
    {
        global $wpdb;

        $to   = $this->normaliseDate($to) ?? UserClock::today($fcUserId);
        $from = $this->normaliseDate($from) ?? UserClock::shift($to, -89);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, record_date, recorded_at, weight_kg, body_fat_percentage, muscle_mass_kg,
                    bmi, systolic_pressure, diastolic_pressure, heart_rate_resting, sleep_hours,
                    mood_score, energy_score, stress_score, notes, source
               FROM {$wpdb->prefix}fc_health_stats
              WHERE user_id = %d AND record_date BETWEEN %s AND %s
              ORDER BY record_date DESC",
            $fcUserId,
            $from,
            $to
        ), ARRAY_A) ?: [];

        $wanted = array_values(array_intersect($metrics, array_keys(self::FIELDS)));
        $items  = array_map(fn(array $row): array => $this->presentStat($row), $rows);

        if ([] !== $wanted) {
            // Drop days that say nothing about the metrics asked for, rather
            // than returning rows of nulls the client has to filter itself.
            $items = array_values(array_filter(
                $items,
                static function (array $item) use ($wanted): bool {
                    foreach ($wanted as $field) {
                        if (null !== $item[$field]) {
                            return true;
                        }
                    }

                    return false;
                }
            ));
        }

        return ['items' => $items, 'from' => $from, 'to' => $to];
    }

    /**
     * `GET /health/measurements` — the radar chart and the profile form.
     *
     * @return array<string,mixed>
     */
    public function measurements(int $fcUserId, ?string $from = null, ?string $to = null): array
    {
        global $wpdb;

        $to   = $this->normaliseDate($to) ?? UserClock::today($fcUserId);
        $from = $this->normaliseDate($from) ?? UserClock::shift($to, -364);

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $columns = implode(', ', array_keys(self::MEASUREMENTS));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column list is a constant.
        $sql = "SELECT id, record_date, {$columns}, notes
                  FROM {$wpdb->prefix}fc_body_measurements
                 WHERE user_id = %d AND record_date BETWEEN %s AND %s
                 ORDER BY record_date DESC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $rows = $wpdb->get_results($wpdb->prepare($sql, $fcUserId, $from, $to), ARRAY_A) ?: [];

        return [
            'items' => array_map(fn(array $row): array => $this->presentMeasurement($row), $rows),
            'from'  => $from,
            'to'    => $to,
        ];
    }

    // ----------------------------------------------------------------- writes

    /**
     * `POST /health/stats` — upsert the day's record.
     *
     * @param array<string,mixed> $payload Any subset of FIELDS, plus record_date and notes.
     * @return array<string,mixed> The stored day.
     */
    public function save(int $fcUserId, array $payload, ?int $editorAccountId = null, string $source = 'manual'): array
    {
        global $wpdb;

        $date   = $this->normaliseDate($payload['record_date'] ?? null) ?? UserClock::today($fcUserId);
        $values = $this->cleanFields($payload);
        $notes  = array_key_exists('notes', $payload) ? $this->trimOrNull($payload['notes']) : null;

        if ([] === $values && null === $notes) {
            throw new DomainException(
                'fc_health_empty',
                __('Nothing to record — send at least one measurement.', 'fitnessclub'),
                400
            );
        }

        $now      = gmdate('Y-m-d H:i:s');
        $existing = $this->rowForDate($fcUserId, $date);

        $wpdb->query('START TRANSACTION');

        try {
            $fields = $values;

            if (array_key_exists('notes', $payload)) {
                $fields['notes'] = $notes;
            }

            $fields['recorded_at'] = $now;
            $fields['updated_at']  = $now;
            $fields['source']      = $source;

            if (null !== $editorAccountId) {
                // Q10 provenance: a staff correction has to be distinguishable
                // from a self-reported reading, or the member's chart moves with
                // no visible cause.
                $fields['last_edited_by_account_id'] = $editorAccountId;
            }

            if (null === $existing) {
                $fields['user_id']     = $fcUserId;
                $fields['record_date'] = $date;
                $fields['created_at']  = $now;

                $wpdb->insert($wpdb->prefix . 'fc_health_stats', $fields);
                $statId = (int) $wpdb->insert_id;
            } else {
                $statId = (int) $existing['id'];
                $wpdb->update($wpdb->prefix . 'fc_health_stats', $fields, ['id' => $statId]);
            }

            // BMI is recomputed from whatever weight the row *now* holds, which
            // is not necessarily the weight in this payload: an entry that only
            // records sleep must still leave the day's BMI consistent with the
            // weight already there.
            $this->recomputeBmi($fcUserId, $statId);
            $this->syncProfile($fcUserId);

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        $this->announce($fcUserId, 'health.updated');

        return $this->stat($fcUserId, $statId);
    }

    /**
     * `PUT /health/stats/{id}` — edit a specific day.
     *
     * Distinct from save() only in that it targets a row rather than a date, and
     * so can move one: a member who logged today's weigh-in against yesterday
     * fixes it here rather than by deleting and re-entering.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function updateStat(int $fcUserId, int $statId, array $payload): array
    {
        global $wpdb;

        $existing = $this->statRow($fcUserId, $statId);
        $now      = gmdate('Y-m-d H:i:s');
        $fields   = $this->cleanFields($payload);

        if (array_key_exists('notes', $payload)) {
            $fields['notes'] = $this->trimOrNull($payload['notes']);
        }

        if (array_key_exists('record_date', $payload)) {
            $moved = $this->normaliseDate($payload['record_date']);

            if (null !== $moved && $moved !== $existing['record_date']) {
                // The unique key would reject the move with a database error the
                // member cannot act on; say what actually happened instead.
                if (null !== $this->rowForDate($fcUserId, $moved)) {
                    throw new DomainException(
                        'fc_health_date_taken',
                        __('There is already an entry for that date.', 'fitnessclub'),
                        409
                    );
                }

                $fields['record_date'] = $moved;
            }
        }

        if ([] === $fields) {
            throw new DomainException(
                'fc_health_empty',
                __('Nothing to change.', 'fitnessclub'),
                400
            );
        }

        $fields['updated_at'] = $now;

        $wpdb->query('START TRANSACTION');

        try {
            $wpdb->update($wpdb->prefix . 'fc_health_stats', $fields, ['id' => $statId]);

            $this->recomputeBmi($fcUserId, $statId);
            $this->syncProfile($fcUserId);

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        $this->announce($fcUserId, 'health.updated');

        return $this->stat($fcUserId, $statId);
    }

    /**
     * `DELETE /health/stats/{id}`.
     */
    public function deleteStat(int $fcUserId, int $statId): void
    {
        global $wpdb;

        $this->statRow($fcUserId, $statId);

        // Scoped by user_id as well as id, so a mismatched pair can never remove
        // somebody else's row.
        $wpdb->delete($wpdb->prefix . 'fc_health_stats', ['id' => $statId, 'user_id' => $fcUserId]);

        // The profile may have been quoting the row that just went.
        $this->syncProfile($fcUserId);
        $this->announce($fcUserId, 'health.deleted');
    }

    /**
     * `POST /health/measurements` — upsert the day's measurements.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function saveMeasurements(int $fcUserId, array $payload): array
    {
        global $wpdb;

        $date   = $this->normaliseDate($payload['record_date'] ?? null) ?? UserClock::today($fcUserId);
        $values = [];

        foreach (self::MEASUREMENTS as $key => $rules) {
            if (!array_key_exists($key, $payload) || null === $payload[$key]) {
                continue;
            }

            $values[$key] = round($this->clamp((float) $payload[$key], $rules['min'], $rules['max'], $key), 2);
        }

        if ([] === $values) {
            throw new DomainException(
                'fc_measurements_empty',
                __('Nothing to record — send at least one measurement.', 'fitnessclub'),
                400
            );
        }

        $now = gmdate('Y-m-d H:i:s');

        if (array_key_exists('notes', $payload)) {
            $values['notes'] = $this->trimOrNull($payload['notes']);
        }

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_body_measurements
              WHERE user_id = %d AND record_date = %s LIMIT 1",
            $fcUserId,
            $date
        ));

        if (null === $existing) {
            $wpdb->insert($wpdb->prefix . 'fc_body_measurements', $values + [
                'user_id'     => $fcUserId,
                'record_date' => $date,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $id = (int) $wpdb->insert_id;
        } else {
            $id = (int) $existing;
            $wpdb->update(
                $wpdb->prefix . 'fc_body_measurements',
                $values + ['updated_at' => $now],
                ['id' => $id]
            );
        }

        $this->announce($fcUserId, 'health.measurements');

        return $this->measurement($fcUserId, $id);
    }

    // -------------------------------------------------------------- internals

    /**
     * One stored day, scoped by owner.
     *
     * @return array<string,mixed>
     */
    public function stat(int $fcUserId, int $statId): array
    {
        return $this->presentStat($this->statRow($fcUserId, $statId));
    }

    /**
     * @return array<string,mixed>
     */
    private function measurement(int $fcUserId, int $id): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_body_measurements WHERE id = %d AND user_id = %d LIMIT 1",
            $id,
            $fcUserId
        ), ARRAY_A);

        if (!is_array($row)) {
            throw new DomainException(
                'fc_measurement_not_found',
                __('That entry does not exist.', 'fitnessclub'),
                404
            );
        }

        return $this->presentMeasurement($row);
    }

    /**
     * Validate and coerce the writable fields present in a payload.
     *
     * Out-of-range values are refused rather than clamped: a weight of 8 000 kg
     * is a typo, and silently storing 500 would put a number on the member's
     * chart that they never entered and cannot explain.
     *
     * @param array<string,mixed> $payload
     * @return array<string,int|float|null>
     */
    private function cleanFields(array $payload): array
    {
        $values = [];

        foreach (self::FIELDS as $key => $rules) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            // An explicit null clears the reading — which is how a mis-entered
            // heart rate is removed without deleting the whole day.
            if (null === $payload[$key]) {
                $values[$key] = null;

                continue;
            }

            $value = $this->clamp((float) $payload[$key], $rules['min'], $rules['max'], $key);

            $values[$key] = 0 === $rules['decimals']
                ? (int) round($value)
                : round($value, $rules['decimals']);
        }

        $this->assertBloodPressurePaired($payload, $values);

        return $values;
    }

    /**
     * Blood pressure is one reading with two numbers.
     *
     * The prototype took a single input and wrote `diastolic = systolic * 0.65`
     * — a fabricated number stored beside a real one, indistinguishable from a
     * measurement afterwards. Half a reading is refused here instead.
     *
     * @param array<string,mixed> $payload
     * @param array<string,int|float|null> $values
     */
    private function assertBloodPressurePaired(array $payload, array $values): void
    {
        $systolic  = array_key_exists('systolic_pressure', $payload) ? $values['systolic_pressure'] : null;
        $diastolic = array_key_exists('diastolic_pressure', $payload) ? $values['diastolic_pressure'] : null;

        if ((null === $systolic) === (null === $diastolic)) {
            if (null !== $systolic && $diastolic >= $systolic) {
                throw new DomainException(
                    'fc_blood_pressure_invalid',
                    __('Diastolic pressure must be lower than systolic.', 'fitnessclub'),
                    400
                );
            }

            return;
        }

        throw new DomainException(
            'fc_blood_pressure_incomplete',
            __('Blood pressure needs both the systolic and the diastolic reading.', 'fitnessclub'),
            400
        );
    }

    private function clamp(float $value, float $min, float $max, string $key): float
    {
        if ($value < $min || $value > $max) {
            throw new DomainException(
                'fc_health_out_of_range',
                sprintf(
                    /* translators: 1: metric label, 2: minimum, 3: maximum */
                    __('%1$s must be between %2$s and %3$s.', 'fitnessclub'),
                    self::FIELDS[$key]['label'] ?? (self::MEASUREMENTS[$key]['label'] ?? $key),
                    (string) $min,
                    (string) $max
                ),
                400
            );
        }

        return $value;
    }

    /**
     * Recompute one row's BMI from the member's height and the row's weight.
     *
     * NULL when either is missing — a BMI without a height is not a conservative
     * estimate, it is a made-up number.
     */
    private function recomputeBmi(int $fcUserId, int $statId): void
    {
        global $wpdb;

        $height = $this->height($this->profile($fcUserId));
        $weight = $wpdb->get_var($wpdb->prepare(
            "SELECT weight_kg FROM {$wpdb->prefix}fc_health_stats WHERE id = %d LIMIT 1",
            $statId
        ));

        $bmi = null;

        if (null !== $height && null !== $weight && $height > 0) {
            $metres = $height / 100;
            $bmi    = round(((float) $weight) / ($metres * $metres), 2);
        }

        $wpdb->update($wpdb->prefix . 'fc_health_stats', ['bmi' => $bmi], ['id' => $statId]);
    }

    /**
     * Re-derive the profile's cached weight and body fat from the newest reading
     * that has one.
     *
     * Recomputed, never assigned — see the class docblock. Two scalar
     * subqueries on an index the table already carries, on a path that runs once
     * per health write.
     */
    private function syncProfile(int $fcUserId): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_users u
                SET u.weight_kg = (
                        SELECT h.weight_kg FROM {$wpdb->prefix}fc_health_stats h
                         WHERE h.user_id = u.id AND h.weight_kg IS NOT NULL
                         ORDER BY h.record_date DESC, h.id DESC LIMIT 1
                    ),
                    u.body_fat_percentage = (
                        SELECT h.body_fat_percentage FROM {$wpdb->prefix}fc_health_stats h
                         WHERE h.user_id = u.id AND h.body_fat_percentage IS NOT NULL
                         ORDER BY h.record_date DESC, h.id DESC LIMIT 1
                    ),
                    u.updated_at = UTC_TIMESTAMP()
              WHERE u.id = %d",
            $fcUserId
        ));
    }

    /**
     * The most recent non-null reading of every field, with the one before it.
     *
     * One query per field would be ten; this is one pass over the member's rows
     * in date order, which the `(user_id, record_date)` index already serves.
     *
     * @return array<string,array{value:float,date:string,previous:?float,previous_date:?string}>
     */
    private function latestPerField(int $fcUserId): array
    {
        global $wpdb;

        $columns = implode(', ', array_column(self::FIELDS, 'column'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column list is a constant.
        $sql = "SELECT record_date, {$columns}
                  FROM {$wpdb->prefix}fc_health_stats
                 WHERE user_id = %d
                 ORDER BY record_date DESC, id DESC
                 LIMIT 400";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $rows = $wpdb->get_results($wpdb->prepare($sql, $fcUserId), ARRAY_A) ?: [];

        $latest = [];

        foreach ($rows as $row) {
            foreach (self::FIELDS as $key => $rules) {
                if (null === $row[$rules['column']]) {
                    continue;
                }

                $value = (float) $row[$rules['column']];

                if (!isset($latest[$key])) {
                    $latest[$key] = [
                        'value'         => $value,
                        'date'          => (string) $row['record_date'],
                        'previous'      => null,
                        'previous_date' => null,
                    ];

                    continue;
                }

                // The second sighting is the comparison point for the trend, and
                // only the second — everything older is history, not a trend.
                if (null === $latest[$key]['previous']) {
                    $latest[$key]['previous']      = $value;
                    $latest[$key]['previous_date'] = (string) $row['record_date'];
                }
            }
        }

        return $latest;
    }

    /**
     * @param array{key:string,label:string,field:?string,icon:string,tone:string,unit:string,decimals:int} $card
     * @param array<string,array<string,mixed>> $latest
     * @return array<string,mixed>
     */
    private function metricCard(array $card, array $latest, string $today): array
    {
        $field  = (string) $card['field'];
        $entry  = $latest[$field] ?? null;
        $stale  = null !== $entry && $entry['date'] < UserClock::shift($today, -self::STALE_AFTER_DAYS);
        $value  = null === $entry || $stale ? null : $this->round((float) $entry['value'], $card['decimals']);

        $presented = [
            'key'         => $card['key'],
            'field'       => $field,
            'label'       => $card['label'],
            'icon'        => $card['icon'],
            'tone'        => $card['tone'],
            'unit'        => $card['unit'],
            'decimals'    => $card['decimals'],
            'value'       => $value,
            'display'     => null === $value ? null : $this->formatNumber($value, $card['decimals']),
            'recorded_on' => null === $entry || $stale ? null : $entry['date'],
            'editable'    => true,
            'detail'      => null,
            'trend'       => null,
        ];

        if (null === $entry || $stale) {
            return $presented;
        }

        // Blood pressure is one card carrying two numbers; the systolic entry
        // owns the card and reads the diastolic beside it.
        if ('blood_pressure' === $card['key']) {
            $diastolic = $latest['diastolic_pressure'] ?? null;

            $presented['display'] = null === $diastolic
                ? $presented['display']
                : sprintf('%d/%d', (int) $entry['value'], (int) $diastolic['value']);
            $presented['secondary_value'] = null === $diastolic ? null : (int) $diastolic['value'];
        }

        if ('mood' === $card['key']) {
            $presented['display'] = self::MOOD_LABELS[(int) $entry['value']] ?? $presented['display'];
            $presented['detail']  = sprintf('%d/5', (int) $entry['value']);
        }

        $presented['trend']  = $this->trend($entry, $card['decimals']);
        $presented['detail'] ??= $this->recordedLabel($entry['date'], $today);

        return $presented;
    }

    /**
     * The BMI card, computed from the profile height and the latest weight.
     *
     * Not read from `fc_health_stats.bmi`: the stored column is the BMI *of that
     * day*, and if the member's height changed since, the card would quote an
     * arithmetic that no longer holds. The column stays for history and charts.
     *
     * @param array{key:string,label:string,field:?string,icon:string,tone:string,unit:string,decimals:int} $card
     * @param array<string,array<string,mixed>> $latest
     * @return array<string,mixed>
     */
    private function bmiCard(array $card, array $latest, ?float $height, string $today): array
    {
        $weight = $latest['weight_kg'] ?? null;
        $bmi    = null;

        if (null !== $weight && null !== $height && $height > 0) {
            $metres = $height / 100;
            $bmi    = round(((float) $weight['value']) / ($metres * $metres), 1);
        }

        return [
            'key'         => $card['key'],
            'field'       => null,
            'label'       => $card['label'],
            'icon'        => $card['icon'],
            'tone'        => $card['tone'],
            'unit'        => '',
            'decimals'    => 1,
            'value'       => $bmi,
            'display'     => null === $bmi ? null : $this->formatNumber($bmi, 1),
            'recorded_on' => null === $bmi || null === $weight ? null : $weight['date'],
            // BMI has no input. The prototype offered one anyway, which would
            // have been overwritten by the next weigh-in.
            'editable'    => false,
            'detail'      => $this->bmiBand($bmi, $height),
            'trend'       => null,
        ];
    }

    /**
     * The WHO band, or why there is no BMI to band.
     *
     * Stated as a band and nothing else: BMI does not distinguish muscle from
     * fat, and an app that tells a lifter they are "overweight" on this number
     * alone is wrong in a way the member can feel.
     */
    private function bmiBand(?float $bmi, ?float $height): ?string
    {
        if (null === $height) {
            return __('Add your height to see BMI', 'fitnessclub');
        }

        if (null === $bmi) {
            return __('Log a weight to see BMI', 'fitnessclub');
        }

        if ($bmi < 18.5) {
            return __('Underweight range', 'fitnessclub');
        }
        if ($bmi < 25) {
            return __('Normal range', 'fitnessclub');
        }
        if ($bmi < 30) {
            return __('Overweight range', 'fitnessclub');
        }

        return __('Obese range', 'fitnessclub');
    }

    /**
     * Movement since the previous reading.
     *
     * `direction` only — never "good" or "bad". Whether falling weight is
     * progress depends on what the member is training for, and the server does
     * not know that. The UI draws a neutral arrow.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>|null
     */
    private function trend(array $entry, int $decimals): ?array
    {
        if (null === $entry['previous']) {
            return null;
        }

        $delta = (float) $entry['value'] - (float) $entry['previous'];

        return [
            'direction'     => abs($delta) < 0.005 ? 'flat' : ($delta > 0 ? 'up' : 'down'),
            'delta'         => $this->round($delta, $decimals),
            'previous'      => $this->round((float) $entry['previous'], $decimals),
            'previous_date' => $entry['previous_date'],
        ];
    }

    private function recordedLabel(string $date, string $today): string
    {
        if ($date === $today) {
            return __('Today', 'fitnessclub');
        }

        if ($date === UserClock::shift($today, -1)) {
            return __('Yesterday', 'fitnessclub');
        }

        /* translators: %s: a date */
        return sprintf(__('Last: %s', 'fitnessclub'), $date);
    }

    /**
     * The latest value of each measurement, **per field rather than per row**.
     *
     * Taking the newest row instead would blank out every measurement the member
     * did not repeat: someone who measures chest and waist monthly but calves
     * twice a year would see calves vanish the moment they recorded anything
     * else. Each field carries the date it was taken, so a value from March is
     * labelled as one instead of appearing to be current.
     *
     * @return array<string,mixed>|null
     */
    private function latestMeasurements(int $fcUserId): ?array
    {
        global $wpdb;

        $columns = implode(', ', array_keys(self::MEASUREMENTS));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column list is a constant.
        $sql = "SELECT record_date, {$columns}
                  FROM {$wpdb->prefix}fc_body_measurements
                 WHERE user_id = %d
                 ORDER BY record_date DESC, id DESC
                 LIMIT 60";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $rows = $wpdb->get_results($wpdb->prepare($sql, $fcUserId), ARRAY_A) ?: [];

        if ([] === $rows) {
            return null;
        }

        $fields = [];

        foreach ($rows as $row) {
            foreach (array_keys(self::MEASUREMENTS) as $key) {
                if (null === $row[$key] || isset($fields[$key])) {
                    continue;
                }

                $fields[$key] = [
                    'value' => round((float) $row[$key], 1),
                    'date'  => (string) $row['record_date'],
                ];
            }
        }

        if ([] === $fields) {
            return null;
        }

        return [
            'last_recorded_on' => (string) $rows[0]['record_date'],
            'fields'           => $fields,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function presentStat(array $row): array
    {
        $presented = [
            'id'          => (int) $row['id'],
            'date'        => (string) $row['record_date'],
            'recorded_at' => $this->iso($row['recorded_at'] ?? null),
            'bmi'         => null === $row['bmi'] ? null : round((float) $row['bmi'], 1),
            'notes'       => $row['notes'],
            // 'admin' means a trainer or administrator entered this on the
            // member's behalf (Q10), which the screen labels rather than leaving
            // the member to wonder where a reading came from.
            'source'      => $row['source'] ?? 'manual',
        ];

        foreach (self::FIELDS as $key => $rules) {
            $value = $row[$rules['column']] ?? null;

            $presented[$key] = null === $value
                ? null
                : $this->round((float) $value, $rules['decimals']);
        }

        return $presented;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function presentMeasurement(array $row): array
    {
        $presented = [
            'id'    => (int) $row['id'],
            'date'  => (string) $row['record_date'],
            'notes' => $row['notes'] ?? null,
        ];

        foreach (array_keys(self::MEASUREMENTS) as $key) {
            $value = $row[$key] ?? null;

            $presented[$key] = null === $value ? null : round((float) $value, 1);
        }

        return $presented;
    }

    /**
     * @return array<string,mixed>
     */
    private function profile(int $fcUserId): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT height_cm, weight_kg, target_weight_kg, body_fat_percentage
               FROM {$wpdb->prefix}fc_users WHERE id = %d LIMIT 1",
            $fcUserId
        ), ARRAY_A);

        return is_array($row) ? $row : [
            'height_cm'           => null,
            'weight_kg'           => null,
            'target_weight_kg'    => null,
            'body_fat_percentage' => null,
        ];
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function height(array $profile): ?float
    {
        $height = $profile['height_cm'] ?? null;

        return null === $height || (float) $height <= 0 ? null : (float) $height;
    }

    /**
     * Load a day, scoped by owner. An id belonging to somebody else is a 404,
     * never a 403 — a 403 confirms the row exists.
     *
     * @return array<string,mixed>
     */
    private function statRow(int $fcUserId, int $statId): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_health_stats WHERE id = %d AND user_id = %d LIMIT 1",
            $statId,
            $fcUserId
        ), ARRAY_A);

        if (!is_array($row)) {
            throw new DomainException(
                'fc_health_stat_not_found',
                __('That entry does not exist.', 'fitnessclub'),
                404
            );
        }

        return $row;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function rowForDate(int $fcUserId, string $date): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_health_stats
              WHERE user_id = %d AND record_date = %s LIMIT 1",
            $fcUserId,
            $date
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    private function round(float $value, int $decimals): float|int
    {
        return 0 === $decimals ? (int) round($value) : round($value, $decimals);
    }

    private function formatNumber(float|int $value, int $decimals): string
    {
        return 0 === $decimals
            ? (string) (int) $value
            : rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');
    }

    private function normaliseDate($value): ?string
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', trim($value));

        return $date && $date->format('Y-m-d') === trim($value) ? $date->format('Y-m-d') : null;
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
     * A weight change moves the dashboard's goal-progress figure, so say so —
     * the cached aggregate drops itself. See Support\DashboardCache.
     */
    private function announce(int $fcUserId, string $reason): void
    {
        do_action('fitnessclub/user_data_changed', $fcUserId, $reason);
    }
}
