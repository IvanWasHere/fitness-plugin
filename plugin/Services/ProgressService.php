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
