<?php

namespace FitnessClub\Services;

use FitnessClub\Support\UserClock;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Consecutive-day workout streaks.
 *
 * The celebration screen needs the streak the moment a session completes, and
 * the dashboard needs the same number, so it lives in one place rather than
 * being recomputed differently in two.
 *
 * ## What counts
 *
 * One or more **completed** sessions on a calendar day counts as one day. Two
 * workouts in a day do not make a two-day streak, and an abandoned session does
 * not extend one — the streak measures showing up, not row count.
 *
 * ## Which calendar
 *
 * The user's own. `log_date` is stamped in the user's timezone when the session
 * starts (see WorkoutSessionService), so a 23:30 workout counts for that day
 * rather than tomorrow, which is what the user experienced. Doing this in UTC
 * silently breaks streaks for everyone west of Greenwich who trains at night.
 *
 * A streak is live if the most recent day is today **or yesterday** — the day is
 * not over yet, so today's absence does not end it until tomorrow arrives.
 */
final class StreakService
{
    /** Days scanned back before we stop caring — a 400-day streak reads as 400. */
    private const HORIZON_DAYS = 400;

    /**
     * The user's current streak in days, 0 when it has lapsed.
     */
    public function current(int $fcUserId, ?string $today = null): int
    {
        $days = $this->completedDays($fcUserId);
        if ([] === $days) {
            return 0;
        }

        $today     = $today ?? UserClock::today($fcUserId);
        $yesterday = UserClock::shift($today, -1);

        $cursor = $days[0];
        if ($cursor !== $today && $cursor !== $yesterday) {
            return 0; // Lapsed: the last workout was two or more days ago.
        }

        $streak = 1;
        foreach (array_slice($days, 1) as $day) {
            if ($day !== UserClock::shift($cursor, -1)) {
                break;
            }
            $streak++;
            $cursor = $day;
        }

        return $streak;
    }

    /**
     * Did completing a session on `$day` extend the streak, rather than being a
     * second workout on a day that already counted?
     */
    public function extendedBy(int $fcUserId, string $day, int $excludeSessionId = 0): bool
    {
        global $wpdb;

        $alreadyCounted = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_workout_sessions
              WHERE user_id = %d AND status = 'completed' AND log_date = %s AND id <> %d",
            $fcUserId,
            $day,
            $excludeSessionId
        ));

        return 0 === $alreadyCounted;
    }

    /**
     * Distinct completed-workout days, newest first.
     *
     * @return string[] Y-m-d strings.
     */
    private function completedDays(int $fcUserId): array
    {
        global $wpdb;

        if ($fcUserId <= 0) {
            return [];
        }

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT log_date
               FROM {$wpdb->prefix}fc_workout_sessions
              WHERE user_id = %d AND status = 'completed' AND log_date IS NOT NULL
              ORDER BY log_date DESC
              LIMIT %d",
            $fcUserId,
            self::HORIZON_DAYS
        ));

        return array_map('strval', $rows ?: []);
    }
}
