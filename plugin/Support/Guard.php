<?php

namespace FitnessClub\Support;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Resource-level authorisation.
 *
 * Capability checks answer "may this ROLE do this KIND of thing"; the Guard answers
 * "may this USER touch this SPECIFIC row". Both are required — a trainer with
 * `fc_manage_clients` can still only reach clients actually assigned to them.
 * Skipping the resource check is the most common authorisation hole in plugins of
 * this shape (plans/03-backend.md).
 *
 * Two trainer guards, NOT interchangeable, because a client may have several
 * trainers (Q3):
 *
 *   trainerCoachesClient()    — any active assignment. READS of shared client data:
 *                               progress, sessions, health, nutrition, and every
 *                               trainer's assignments (Q13 opened these to all coaches).
 *   trainerAssignedResource() — only the trainer who created the row. WRITES to
 *                               plans, workout assignments, food plans and notes.
 *
 * Every method takes a WordPress user id (the identity anchor) and resolves the
 * internal fc_users.id / fc_trainers.id itself. Joins use raw $wpdb (the wpBones
 * builder has no join, D5); table names use the sniff-approved {$wpdb->prefix} form
 * and are never built from request data; values are placeholder-prepared.
 */
final class Guard
{
    /**
     * Does this trainer actively coach this client? True for any assignment in
     * `active` status — the shared-read rule.
     *
     * @param int $trainerWpUserId WordPress user id of the trainer.
     * @param int $clientUserId    fc_users.id of the client.
     */
    public static function trainerCoachesClient(int $trainerWpUserId, int $clientUserId): bool
    {
        global $wpdb;

        if ($trainerWpUserId <= 0 || $clientUserId <= 0) {
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT ut.id
               FROM {$wpdb->prefix}fc_user_trainers ut
               JOIN {$wpdb->prefix}fc_trainers t ON t.id = ut.trainer_id
              WHERE t.wp_user_id = %d AND ut.user_id = %d AND ut.status = 'active'
              LIMIT 1",
            $trainerWpUserId,
            $clientUserId
        ));

        return null !== $found;
    }

    /**
     * Did this trainer create/assign this specific resource? The stricter write
     * guard. Only a fixed allow-list of tables is accepted; an unknown resource is
     * a programming error and returns false rather than building an arbitrary query.
     *
     * @param string $resource One of the keys in self::ownableByTrainer().
     */
    public static function trainerAssignedResource(int $trainerWpUserId, string $resource, int $resourceId): bool
    {
        global $wpdb;

        if ($trainerWpUserId <= 0 || $resourceId <= 0) {
            return false;
        }

        $map = self::ownableByTrainer();
        if (!isset($map[$resource])) {
            return false;
        }
        [$table, $ownerColumn] = $map[$resource];

        $trainerId = self::trainerId($trainerWpUserId);
        if (null === $trainerId) {
            return false;
        }

        // $table/$ownerColumn come only from the hard-coded map, never the caller.
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_{$table} WHERE id = %d AND {$ownerColumn} = %d LIMIT 1",
            $resourceId,
            $trainerId
        ));

        return null !== $found;
    }

    /**
     * Does this WordPress user own this workout session?
     */
    public static function ownsSession(int $wpUserId, int $sessionId): bool
    {
        return self::userOwns($wpUserId, 'workout_sessions', 'user_id', $sessionId);
    }

    /**
     * Is this WordPress user a party to this message thread? True for the client on
     * the thread OR the trainer on it — both sides read and post.
     */
    public static function participatesInThread(int $wpUserId, int $threadId): bool
    {
        global $wpdb;

        if ($wpUserId <= 0 || $threadId <= 0) {
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT th.id
               FROM {$wpdb->prefix}fc_message_threads th
               JOIN {$wpdb->prefix}fc_users u    ON u.id = th.user_id
               JOIN {$wpdb->prefix}fc_trainers t ON t.id = th.trainer_id
              WHERE th.id = %d AND (u.wp_user_id = %d OR t.wp_user_id = %d)
              LIMIT 1",
            $threadId,
            $wpUserId,
            $wpUserId
        ));

        return null !== $found;
    }

    /**
     * Generic "does this WordPress user own this row" check, by resource key.
     *
     * @param string $resource One of the keys in self::ownableByUser().
     */
    public static function userOwnsResource(int $wpUserId, string $resource, int $resourceId): bool
    {
        $map = self::ownableByUser();
        if (!isset($map[$resource])) {
            return false;
        }
        [$table, $column] = $map[$resource];

        return self::userOwns($wpUserId, $table, $column, $resourceId);
    }

    /**
     * Resolve fc_users.id for a WordPress user, or null.
     */
    public static function userId(int $wpUserId): ?int
    {
        global $wpdb;

        if ($wpUserId <= 0) {
            return null;
        }

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_users WHERE wp_user_id = %d LIMIT 1",
            $wpUserId
        ));

        return null === $id ? null : (int) $id;
    }

    /**
     * Resolve fc_trainers.id for a WordPress user, or null.
     */
    public static function trainerId(int $wpUserId): ?int
    {
        global $wpdb;

        if ($wpUserId <= 0) {
            return null;
        }

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_trainers WHERE wp_user_id = %d LIMIT 1",
            $wpUserId
        ));

        return null === $id ? null : (int) $id;
    }

    /**
     * Shared implementation: a row in fc_$table with id $resourceId whose $column
     * equals the caller's fc_users.id.
     *
     * Private and trusted: $table/$column are supplied only by this class's own
     * public methods, from hard-coded literals or the ownableByUser() map, never
     * from request data — which is what makes interpolating them safe.
     */
    private static function userOwns(int $wpUserId, string $table, string $column, int $resourceId): bool
    {
        global $wpdb;

        if ($wpUserId <= 0 || $resourceId <= 0) {
            return false;
        }

        $userId = self::userId($wpUserId);
        if (null === $userId) {
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_{$table} WHERE id = %d AND {$column} = %d LIMIT 1",
            $resourceId,
            $userId
        ));

        return null !== $found;
    }

    /**
     * Tables whose rows belong to one trainer, with the ownership column. The write
     * guard's allow-list — nothing outside it can be checked.
     *
     * @return array<string,array{0:string,1:string}>
     */
    private static function ownableByTrainer(): array
    {
        return [
            'workout'   => ['workouts', 'trainer_id'],
            'plan'      => ['plans', 'trainer_id'],
            'food_plan' => ['food_plans', 'trainer_id'],
            'note'      => ['client_notes', 'trainer_id'],
        ];
    }

    /**
     * Tables whose rows belong to one user (by fc_users.id).
     *
     * @return array<string,array{0:string,1:string}>
     */
    private static function ownableByUser(): array
    {
        return [
            'session'          => ['workout_sessions', 'user_id'],
            'health_stat'      => ['health_stats', 'user_id'],
            'body_measurement' => ['body_measurements', 'user_id'],
            'nutrition_log'    => ['nutrition_logs', 'user_id'],
            'nutrition_day'    => ['nutrition_days', 'user_id'],
            'user_workout'     => ['user_workouts', 'user_id'],
            'subscription'     => ['subscriptions', 'user_id'],
        ];
    }
}
