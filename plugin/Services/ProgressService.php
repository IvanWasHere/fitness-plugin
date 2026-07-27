<?php

namespace FitnessClub\Services;

use FitnessClub\Support\UserClock;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Time-bucketed views of what a member actually did (plans/03-backend.md).
 *
 * W1.6 needs two of them — the dashboard's seven-day chart and its 30-day
 * rollup. The range resolver they share is the same one W2.3's
 * `/progress?range=week|month|quarter|year` will use, which is why it exists as
 * a named concept here rather than as two hard-coded date subtractions.
 *
 * ## Windows are rolling, not calendar
 *
 * "This week" is the last seven days ending today, not Monday-to-Sunday. A
 * calendar week means the chart a member opens on Monday morning is empty, and
 * the one they open on Sunday night flatters them; a rolling window always shows
 * the same amount of history and always ends on the bar for today.
 *
 * ## Every bucket is present, including the empty ones
 *
 * `GROUP BY log_date` returns only days with sessions. The series is built by
 * walking the window and reading that map, so a rest day is a zero rather than a
 * missing bar — a chart that silently drops empty days draws a line through the
 * gap and turns three workouts in a fortnight into a plausible-looking habit.
 */
final class ProgressService
{
    /** Rolling windows, in days, for the ranges the contract names. */
    private const RANGE_DAYS = [
        'week'    => 7,
        'month'   => 30,
        'quarter' => 90,
        'year'    => 365,
    ];

    /**
     * How each range is bucketed — **the thing that makes the filter real**.
     *
     * The prototype's week/month/3M/year toggle set state and re-rendered
     * identical data (gap §2, line 993): four buttons that did nothing. The fix
     * is not to fetch four times as many points for "year" — a year of daily
     * readings is 365 values a 300-pixel chart cannot draw, and drawing them
     * anyway produces noise that hides the trend the member came to see.
     *
     * So the granularity changes with the span: days close up, weeks at a
     * quarter, months across a year. Every range lands between 7 and 30 buckets,
     * which is what a chart of this size can actually render.
     */
    private const RANGE_BUCKETS = [
        'week'    => 'day',
        'month'   => 'day',
        'quarter' => 'week',
        'year'    => 'month',
    ];

    /** How many lifts the strength chart draws before it becomes unreadable. */
    private const STRENGTH_LINES = 3;

    /**
     * `GET /progress?range=` — every series the Progress screen charts (W2.3).
     *
     * One request for four charts and three totals. The alternative the
     * prototype took was four independent reads plus a client-side filter that
     * did not filter; this is one round trip that answers with data already
     * shaped for the range asked for.
     *
     * @param string $range week|month|quarter|year
     * @return array<string,mixed>
     */
    public function range(int $fcUserId, string $range = 'month', ?string $today = null): array
    {
        $range   = $this->normaliseRange($range);
        $today   = $today ?? UserClock::today($fcUserId);
        $days    = self::RANGE_DAYS[$range];
        $from    = UserClock::shift($today, -($days - 1));
        $buckets = $this->buckets($range, $from, $today);

        $readings = $this->healthReadings($fcUserId, $from, $today);

        return [
            'range'   => $range,
            'from'    => $from,
            'to'      => $today,
            // The client needs to know how the server bucketed, or a tooltip
            // saying "12 Jun" on a chart of monthly averages is a lie.
            'bucket'  => self::RANGE_BUCKETS[$range],
            'labels'  => array_column($buckets, 'label'),
            'dates'   => array_column($buckets, 'start'),
            'weight'      => $this->average($readings['weight_kg'] ?? [], $buckets, 1),
            'body_fat'    => $this->average($readings['body_fat_percentage'] ?? [], $buckets, 1),
            'strength'    => $this->strength($fcUserId, $from, $today, $buckets),
            'consistency' => $this->consistency($fcUserId, $from, $today, $buckets),
            'measurements' => $this->measurementSeries($fcUserId, $from, $today),
            'totals'      => $this->totals($fcUserId, $from, $today),
        ];
    }

    /**
     * `GET /progress/exercises/{name}` — one lift's history, ungrouped.
     *
     * Session-level rather than bucketed: this is the detail view behind a line
     * on the strength chart, and averaging it away is the opposite of what the
     * member opened it for.
     *
     * @return array<string,mixed>
     */
    public function exerciseProgression(int $fcUserId, string $exerciseName, string $range = 'quarter', ?string $today = null): array
    {
        global $wpdb;

        $range = $this->normaliseRange($range);
        $today = $today ?? UserClock::today($fcUserId);
        $from  = UserClock::shift($today, -(self::RANGE_DAYS[$range] - 1));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.log_date,
                    MAX(sl.weight_kg) AS top_weight,
                    SUM(COALESCE(sl.reps, 0) * COALESCE(sl.weight_kg, 0)) AS volume,
                    MAX(sl.reps) AS top_reps,
                    COUNT(*) AS sets
               FROM {$wpdb->prefix}fc_set_logs sl
               JOIN {$wpdb->prefix}fc_exercise_logs el ON el.id = sl.exercise_log_id
               JOIN {$wpdb->prefix}fc_exercises e ON e.id = el.exercise_id
               JOIN {$wpdb->prefix}fc_workout_sessions s ON s.id = el.session_id
              WHERE s.user_id = %d AND s.status = 'completed'
                AND s.log_date BETWEEN %s AND %s
                AND e.exercise_name = %s
              GROUP BY s.log_date
              ORDER BY s.log_date ASC",
            $fcUserId,
            $from,
            $today,
            $exerciseName
        ), ARRAY_A) ?: [];

        return [
            'exercise_name' => $exerciseName,
            'range'         => $range,
            'from'          => $from,
            'to'            => $today,
            'points'        => array_map(static fn(array $row): array => [
                'date'       => (string) $row['log_date'],
                'top_weight' => null === $row['top_weight'] ? null : round((float) $row['top_weight'], 1),
                'top_reps'   => null === $row['top_reps'] ? null : (int) $row['top_reps'],
                'volume_kg'  => round((float) $row['volume'], 1),
                'sets'       => (int) $row['sets'],
            ], $rows),
        ];
    }

    /**
     * `GET /progress/records` — personal records, newest first.
     *
     * Read from `fc_personal_records`, which the session service maintains: the
     * prototype's equivalent was three hardcoded strings (gap §2, line 826).
     *
     * @return array<int,array<string,mixed>>
     */
    public function records(int $fcUserId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT exercise_name, record_type, value, unit, achieved_at
               FROM {$wpdb->prefix}fc_personal_records
              WHERE user_id = %d
              ORDER BY achieved_at DESC, id DESC",
            $fcUserId
        ), ARRAY_A) ?: [];

        return array_map(fn(array $row): array => [
            'exercise_name' => $row['exercise_name'],
            'record_type'   => $row['record_type'],
            'value'         => round((float) $row['value'], 2),
            'unit'          => $row['unit'],
            'achieved_at'   => $this->iso($row['achieved_at']),
        ], $rows);
    }

    /**
     * `GET /progress/consistency?year=` — a day-by-day map for the heatmap.
     *
     * Every day of the year is present, including the zeros: a heatmap built
     * only from days that have sessions has no squares to leave empty, which is
     * the entire point of a heatmap.
     *
     * @return array<string,mixed>
     */
    public function consistencyCalendar(int $fcUserId, ?int $year = null, ?string $today = null): array
    {
        global $wpdb;

        $today = $today ?? UserClock::today($fcUserId);
        $year  = $year ?? (int) substr($today, 0, 4);
        $start = sprintf('%04d-01-01', $year);
        $end   = sprintf('%04d-12-31', $year);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT log_date, COUNT(*) AS workouts
               FROM {$wpdb->prefix}fc_workout_sessions
              WHERE user_id = %d AND status = 'completed'
                AND log_date BETWEEN %s AND %s
              GROUP BY log_date",
            $fcUserId,
            $start,
            $end
        ), ARRAY_A) ?: [];

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['log_date']] = (int) $row['workouts'];
        }

        $days  = [];
        $total = 0;
        $date  = $start;

        while ($date <= $end) {
            $count       = $counts[$date] ?? 0;
            $days[$date] = $count;
            $total      += $count;
            $date        = UserClock::shift($date, 1);
        }

        return [
            'year'        => $year,
            'days'        => $days,
            'total'       => $total,
            'active_days' => count(array_filter($days)),
            'best_day'    => $days ? max($days) : 0,
        ];
    }

    // ------------------------------------------------------- W2.3 internals

    /**
     * The buckets a range is drawn in: contiguous, oldest first, none skipped.
     *
     * @return array<int,array{start:string,end:string,label:string}>
     */
    private function buckets(string $range, string $from, string $to): array
    {
        $granularity = self::RANGE_BUCKETS[$range];
        $buckets     = [];

        if ('day' === $granularity) {
            for ($date = $from; $date <= $to; $date = UserClock::shift($date, 1)) {
                $buckets[] = [
                    'start' => $date,
                    'end'   => $date,
                    // Weekday initials over a week, day-and-month over a month:
                    // seven "Mon Tue Wed" reads well, thirty does not.
                    'label' => 'week' === $range
                        ? $this->formatDate($date, 'D')
                        : $this->formatDate($date, 'j M'),
                ];
            }

            return $buckets;
        }

        if ('week' === $granularity) {
            // Anchored to the window's end, not to Monday: the last bucket must
            // be the one containing today, or "this week" is a partial bar
            // whose height depends on what day it happens to be.
            for ($end = $to; $end >= $from; $end = UserClock::shift($end, -7)) {
                $start = max($from, UserClock::shift($end, -6));

                array_unshift($buckets, [
                    'start' => $start,
                    'end'   => $end,
                    'label' => $this->formatDate($start, 'j M'),
                ]);
            }

            return $buckets;
        }

        // Calendar months, which *are* the natural unit here — unlike weeks,
        // nobody reads a year as "the last 30 days, twelve times".
        $cursor = substr($from, 0, 7);
        $last   = substr($to, 0, 7);

        while ($cursor <= $last) {
            $monthStart = $cursor . '-01';
            $monthEnd   = date('Y-m-t', (int) strtotime($monthStart . ' 00:00:00 UTC'));

            $buckets[] = [
                'start' => max($from, $monthStart),
                'end'   => min($to, $monthEnd),
                'label' => $this->formatDate($monthStart, 'M'),
            ];

            $cursor = substr(
                date('Y-m-d', (int) strtotime($monthStart . ' 00:00:00 UTC +1 month')),
                0,
                7
            );
        }

        return $buckets;
    }

    /**
     * Map every date in the window to the bucket it belongs to, once, so
     * assigning a row is an array lookup rather than a scan over buckets.
     *
     * @param array<int,array{start:string,end:string,label:string}> $buckets
     * @return array<string,int>
     */
    private function bucketIndex(array $buckets): array
    {
        $index = [];

        foreach ($buckets as $position => $bucket) {
            for ($date = $bucket['start']; $date <= $bucket['end']; $date = UserClock::shift($date, 1)) {
                $index[$date] = $position;
            }
        }

        return $index;
    }

    /**
     * Bucket-average a series of readings.
     *
     * Averaged rather than last-in-bucket: weight swings a kilo with hydration
     * and yesterday's salt, so a year chart plotting one arbitrary day per month
     * shows noise where the member is looking for a trend. Buckets with no
     * reading are **null, not zero** — a month you did not weigh yourself is a
     * gap in the line, and a zero would draw it plunging to the axis.
     *
     * @param array<string,array<int,float>> $byDate
     * @param array<int,array{start:string,end:string,label:string}> $buckets
     * @return array<int,float|null>
     */
    private function average(array $byDate, array $buckets, int $decimals): array
    {
        $index = $this->bucketIndex($buckets);
        $sums  = [];

        foreach ($byDate as $date => $values) {
            $position = $index[$date] ?? null;

            if (null === $position) {
                continue;
            }

            foreach ($values as $value) {
                $sums[$position][] = $value;
            }
        }

        $series = [];

        foreach (array_keys($buckets) as $position) {
            $series[] = isset($sums[$position])
                ? round(array_sum($sums[$position]) / count($sums[$position]), $decimals)
                : null;
        }

        return $series;
    }

    /**
     * Weight and body-fat readings in the window, keyed by date.
     *
     * @return array<string,array<string,array<int,float>>>
     */
    private function healthReadings(int $fcUserId, string $from, string $to): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT record_date, weight_kg, body_fat_percentage
               FROM {$wpdb->prefix}fc_health_stats
              WHERE user_id = %d AND record_date BETWEEN %s AND %s
              ORDER BY record_date ASC",
            $fcUserId,
            $from,
            $to
        ), ARRAY_A) ?: [];

        $readings = ['weight_kg' => [], 'body_fat_percentage' => []];

        foreach ($rows as $row) {
            foreach (['weight_kg', 'body_fat_percentage'] as $column) {
                if (null !== $row[$column]) {
                    $readings[$column][(string) $row['record_date']][] = (float) $row[$column];
                }
            }
        }

        return $readings;
    }

    /**
     * Top-weight progression for the member's most-trained lifts.
     *
     * Three lines, chosen by how often the lift was actually performed in the
     * window rather than from a fixed list — the prototype hardcoded Bench,
     * Squat and Deadlift, which is the wrong chart for anyone whose programme is
     * not that programme.
     *
     * The value is the **heaviest set in the bucket**, not the average: strength
     * progression is about the top end, and averaging in the warm-up sets makes
     * a new personal best look like a bad week.
     *
     * @param array<int,array{start:string,end:string,label:string}> $buckets
     * @return array<int,array<string,mixed>>
     */
    private function strength(int $fcUserId, string $from, string $to, array $buckets): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT e.exercise_name, s.log_date, MAX(sl.weight_kg) AS top_weight, COUNT(*) AS sets
               FROM {$wpdb->prefix}fc_set_logs sl
               JOIN {$wpdb->prefix}fc_exercise_logs el ON el.id = sl.exercise_log_id
               JOIN {$wpdb->prefix}fc_exercises e ON e.id = el.exercise_id
               JOIN {$wpdb->prefix}fc_workout_sessions s ON s.id = el.session_id
              WHERE s.user_id = %d AND s.status = 'completed'
                AND s.log_date BETWEEN %s AND %s
                AND sl.weight_kg IS NOT NULL AND sl.weight_kg > 0
              GROUP BY e.exercise_name, s.log_date
              ORDER BY s.log_date ASC",
            $fcUserId,
            $from,
            $to
        ), ARRAY_A) ?: [];

        if ([] === $rows) {
            return [];
        }

        $index    = $this->bucketIndex($buckets);
        $byLift   = [];
        $setCount = [];

        foreach ($rows as $row) {
            $name     = (string) $row['exercise_name'];
            $position = $index[(string) $row['log_date']] ?? null;

            if (null === $position) {
                continue;
            }

            $weight = (float) $row['top_weight'];
            $setCount[$name] = ($setCount[$name] ?? 0) + (int) $row['sets'];

            $byLift[$name][$position] = max($byLift[$name][$position] ?? 0, $weight);
        }

        arsort($setCount);
        $chosen = array_slice(array_keys($setCount), 0, self::STRENGTH_LINES);

        $series = [];

        foreach ($chosen as $name) {
            $points = [];

            foreach (array_keys($buckets) as $position) {
                // Null, not zero: a bucket in which the lift was not trained is
                // a gap in the line, and a zero would draw it collapsing.
                $points[] = isset($byLift[$name][$position])
                    ? round($byLift[$name][$position], 1)
                    : null;
            }

            $series[] = [
                'exercise_name' => $name,
                'points'        => $points,
                'sets'          => $setCount[$name],
            ];
        }

        return $series;
    }

    /**
     * Completed workouts per bucket.
     *
     * Zero here, unlike the reading series: a week with no workouts is a real
     * zero the member should see, not missing data.
     *
     * @param array<int,array{start:string,end:string,label:string}> $buckets
     * @return array<int,int>
     */
    private function consistency(int $fcUserId, string $from, string $to, array $buckets): array
    {
        $totals = $this->dailyTotals($fcUserId, $from, $to);
        $index  = $this->bucketIndex($buckets);
        $counts = array_fill(0, count($buckets), 0);

        foreach ($totals as $date => $row) {
            $position = $index[$date] ?? null;

            if (null !== $position) {
                $counts[$position] += (int) $row['workouts'];
            }
        }

        return $counts;
    }

    /**
     * Measurement sessions in the window, oldest first — the radar chart's data.
     *
     * @return array<int,array<string,mixed>>
     */
    private function measurementSeries(int $fcUserId, string $from, string $to): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT record_date, chest_cm, waist_cm, hips_cm, arms_cm,
                    thighs_cm, shoulders_cm, neck_cm, calves_cm
               FROM {$wpdb->prefix}fc_body_measurements
              WHERE user_id = %d AND record_date BETWEEN %s AND %s
              ORDER BY record_date ASC",
            $fcUserId,
            $from,
            $to
        ), ARRAY_A) ?: [];

        return array_map(static function (array $row): array {
            $point = ['date' => (string) $row['record_date']];

            foreach ($row as $column => $value) {
                if ('record_date' !== $column) {
                    $point[$column] = null === $value ? null : round((float) $value, 1);
                }
            }

            return $point;
        }, $rows);
    }

    /**
     * The three figures under the charts.
     *
     * `personal_records` counts PRs **set inside the window**, not the member's
     * lifetime total: on a screen whose every other number is scoped to the
     * range, a lifetime count that never moves reads as a broken filter.
     *
     * @return array<string,int>
     */
    private function totals(int $fcUserId, string $from, string $to): array
    {
        global $wpdb;

        $sessions = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS workouts, COALESCE(SUM(calories_burned), 0) AS calories,
                    COALESCE(SUM(duration_seconds), 0) AS seconds
               FROM {$wpdb->prefix}fc_workout_sessions
              WHERE user_id = %d AND status = 'completed' AND log_date BETWEEN %s AND %s",
            $fcUserId,
            $from,
            $to
        ), ARRAY_A) ?: [];

        $records = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_personal_records
              WHERE user_id = %d AND DATE(achieved_at) BETWEEN %s AND %s",
            $fcUserId,
            $from,
            $to
        ));

        return [
            'workouts_completed' => (int) ($sessions['workouts'] ?? 0),
            'calories_burned'    => (int) ($sessions['calories'] ?? 0),
            'total_minutes'      => (int) round(((int) ($sessions['seconds'] ?? 0)) / 60),
            'personal_records'   => $records,
        ];
    }

    private function normaliseRange(?string $range): string
    {
        $range = is_string($range) ? strtolower(trim($range)) : '';

        return isset(self::RANGE_DAYS[$range]) ? $range : 'month';
    }

    /**
     * Localised date formatting, so labels read "Jun" in English and "juin" in
     * French without the client owning a second translation table.
     */
    private function formatDate(string $date, string $format): string
    {
        $timestamp = strtotime($date . ' 00:00:00 UTC');

        return $timestamp ? wp_date($format, $timestamp, new \DateTimeZone('UTC')) : $date;
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
     * Daily totals across a rolling window, oldest bucket first.
     *
     * @return array{
     *     labels: string[], dates: string[], calories: int[],
     *     workouts: int[], minutes: int[]
     * }
     */
    public function dailySeries(int $fcUserId, int $days = 7, ?string $today = null): array
    {
        $days  = max(1, min(366, $days));
        $today = $today ?? UserClock::today($fcUserId);
        $start = UserClock::shift($today, -($days - 1));

        $totals = $this->dailyTotals($fcUserId, $start, $today);

        $series = ['labels' => [], 'dates' => [], 'calories' => [], 'workouts' => [], 'minutes' => []];

        for ($offset = 0; $offset < $days; $offset++) {
            $date = UserClock::shift($start, $offset);
            $row  = $totals[$date] ?? ['workouts' => 0, 'calories' => 0, 'seconds' => 0];

            $series['labels'][]   = $this->weekdayLabel($date);
            $series['dates'][]    = $date;
            $series['calories'][] = (int) $row['calories'];
            $series['workouts'][] = (int) $row['workouts'];
            $series['minutes'][]  = (int) round($row['seconds'] / 60);
        }

        return $series;
    }

    /**
     * The dashboard's seven-day chart.
     *
     * @return array{labels: string[], dates: string[], calories: int[], workouts: int[], minutes: int[]}
     */
    public function weeklySeries(int $fcUserId, ?string $today = null): array
    {
        return $this->dailySeries($fcUserId, self::RANGE_DAYS['week'], $today);
    }

    /**
     * The 30-day rollup under the chart.
     *
     * `consistency_percentage` is **adherence to the plan**: how much of what was
     * scheduled in the window actually got done. It is `null` when nothing was
     * scheduled — a member with no programme has nothing to be consistent with,
     * and inventing a denominator (days in the month, say) would report 13 % to
     * someone who trained every session they were given. The client hides the
     * card on null rather than rendering a zero.
     *
     * @return array{
     *     workouts_completed:int, calories_burned:int, total_minutes:int,
     *     active_days:int, avg_hours_per_week:float, consistency_percentage:float|null,
     *     window_days:int
     * }
     */
    public function monthlyStats(int $fcUserId, ?string $today = null): array
    {
        global $wpdb;

        $days  = self::RANGE_DAYS['month'];
        $today = $today ?? UserClock::today($fcUserId);
        $start = UserClock::shift($today, -($days - 1));

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS workouts,
                    COUNT(DISTINCT log_date) AS active_days,
                    COALESCE(SUM(calories_burned), 0) AS calories,
                    COALESCE(SUM(duration_seconds), 0) AS seconds
               FROM {$wpdb->prefix}fc_workout_sessions
              WHERE user_id = %d AND status = 'completed'
                AND log_date BETWEEN %s AND %s",
            $fcUserId,
            $start,
            $today
        ), ARRAY_A) ?: [];

        $completed = (int) ($row['workouts'] ?? 0);
        $seconds   = (int) ($row['seconds'] ?? 0);

        return [
            'workouts_completed' => $completed,
            'calories_burned'    => (int) ($row['calories'] ?? 0),
            'total_minutes'      => (int) round($seconds / 60),
            'active_days'        => (int) ($row['active_days'] ?? 0),
            'avg_hours_per_week' => round(($seconds / HOUR_IN_SECONDS) / ($days / 7), 1),
            'consistency_percentage' => $this->adherence($fcUserId, $start, $today, $completed),
            'window_days'        => $days,
        ];
    }

    /**
     * Completed sessions in the window against workouts scheduled in it.
     *
     * Capped at 100: doing a scheduled workout twice is enthusiasm, not 200 %
     * adherence, and an uncapped number breaks every progress bar it feeds.
     */
    private function adherence(int $fcUserId, string $start, string $end, int $completed): ?float
    {
        global $wpdb;

        $scheduled = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_user_workouts
              WHERE user_id = %d AND scheduled_for BETWEEN %s AND %s",
            $fcUserId,
            $start,
            $end
        ));

        if ($scheduled <= 0) {
            return null;
        }

        return round(min(100, ($completed / $scheduled) * 100), 1);
    }

    /**
     * Completed-session totals per day, keyed `Y-m-d`.
     *
     * @return array<string,array{workouts:int,calories:int,seconds:int}>
     */
    private function dailyTotals(int $fcUserId, string $start, string $end): array
    {
        global $wpdb;

        if ($fcUserId <= 0) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT log_date,
                    COUNT(*) AS workouts,
                    COALESCE(SUM(calories_burned), 0) AS calories,
                    COALESCE(SUM(duration_seconds), 0) AS seconds
               FROM {$wpdb->prefix}fc_workout_sessions
              WHERE user_id = %d AND status = 'completed'
                AND log_date BETWEEN %s AND %s
              GROUP BY log_date",
            $fcUserId,
            $start,
            $end
        ), ARRAY_A);

        $totals = [];
        foreach ($rows ?: [] as $row) {
            $totals[(string) $row['log_date']] = [
                'workouts' => (int) $row['workouts'],
                'calories' => (int) $row['calories'],
                'seconds'  => (int) $row['seconds'],
            ];
        }

        return $totals;
    }

    /**
     * Localised three-letter weekday, so the chart reads "Mon" in English and
     * "Lun" in French without the client owning a second translation table.
     */
    private function weekdayLabel(string $date): string
    {
        $timestamp = strtotime($date . ' 00:00:00 UTC');

        return $timestamp ? wp_date('D', $timestamp, new \DateTimeZone('UTC')) : $date;
    }
}
