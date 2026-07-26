<?php

namespace FitnessClub\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * "What day is it for this member?"
 *
 * Every number on the dashboard that says *today*, *this week* or *streak* is
 * answered against the member's own calendar, not the server's: `log_date` is
 * stamped in the user's timezone when a session starts, so a 23:30 workout
 * counts for the day the user experienced. Computing the window in UTC instead
 * silently mis-buckets every member west of Greenwich who trains at night.
 *
 * This was a private method in StreakService and again in WorkoutSessionService;
 * ProgressService needed a third copy, which is one too many for a rule this
 * easy to get subtly different in one place.
 */
final class UserClock
{
    /**
     * Today, `Y-m-d`, in the member's timezone — falling back to the site's when
     * the profile has none, and to WordPress' own zone when it has a broken one.
     */
    public static function today(int $fcUserId): string
    {
        return (new \DateTimeImmutable('now', self::zone($fcUserId)))->format('Y-m-d');
    }

    /**
     * The member's timezone, never throwing on a bad stored value.
     */
    public static function zone(int $fcUserId): \DateTimeZone
    {
        global $wpdb;

        $timezone = $fcUserId > 0
            ? $wpdb->get_var($wpdb->prepare(
                "SELECT timezone FROM {$wpdb->prefix}fc_users WHERE id = %d LIMIT 1",
                $fcUserId
            ))
            : null;

        try {
            return new \DateTimeZone((string) ($timezone ?: wp_timezone_string()));
        } catch (\Exception) {
            return wp_timezone();
        }
    }

    /**
     * `$day` shifted by `$days` (negative for the past), as `Y-m-d`.
     *
     * Date arithmetic on the date string rather than on a timestamp: adding
     * 86 400 seconds is wrong twice a year in any zone with daylight saving,
     * and a streak that skips a day every spring is a bug report nobody can
     * reproduce in July.
     */
    public static function shift(string $day, int $days): string
    {
        return (new \DateTimeImmutable($day . ' 00:00:00', new \DateTimeZone('UTC')))
            ->modify(sprintf('%+d days', $days))
            ->format('Y-m-d');
    }
}
