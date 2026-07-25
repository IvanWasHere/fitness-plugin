<?php

namespace FitnessClub\Services;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * What a user's plans allow (plans/03-backend.md#merging-across-multiple-subscriptions).
 *
 * A user may be coached by several trainers at once (Q3) and holds one
 * subscription per trainer, so entitlements are a *merge*, not a lookup:
 *
 *   | Booleans          | Union — any active plan granting it wins            |
 *   | Numeric caps      | Max across active plans (a ceiling, not an allowance |
 *   |                   | that accumulates); `null` means unlimited and wins   |
 *   | max_messages_per_week | Per trainer, resolved from that trainer's sub   |
 *   | Nothing active    | Free-tier defaults from config/fitnessclub.php      |
 *
 * Failing to the free tier rather than to "deny everything" is deliberate: a
 * failed renewal must not lock a user out of their own workout history.
 *
 * Caps grandfather on downgrade (Q16) — this class *reports* a limit and never
 * enforces it retroactively. Holding two trainers on a one-trainer plan is a
 * valid state; only new requests are blocked.
 *
 * W1.3 needs the read path for the boot payload. W1.4 adds the consumption side
 * (`messageQuotaFor()` accounting against `fc_messages`, feature gates on write
 * endpoints); the merge rules here are the ones those build on.
 */
final class EntitlementService
{
    /** Subscription statuses that grant their plan's features. */
    private const GRANTING = ['active', 'trialing'];

    /** Link statuses that consume a trainer slot — a pending request counts (Q14). */
    private const SLOT_CONSUMING = ['active', 'pending'];

    /**
     * The merged entitlement set for one fc_users.id, in the `/auth/me` shape.
     *
     * @return array<string,mixed>
     */
    public function forUser(int $fcUserId): array
    {
        $baseline = (array) FitnessClub()->config('fitnessclub.free_tier', []);

        $entitlements = $baseline;
        $quotaByTrainer = [];
        $hasActive      = false;

        foreach ($this->activePlanFeatures($fcUserId) as $row) {
            $hasActive = true;
            $features  = $row['features'];

            foreach ($features as $key => $value) {
                $entitlements[$key] = $this->mergeValue($entitlements[$key] ?? null, $value);
            }

            if (null !== $row['trainer_id']) {
                $quota = $features['max_messages_per_week'] ?? $row['max_messages_per_week'];
                $quotaByTrainer[(string) $row['trainer_id']] = null === $quota ? null : (int) $quota;
            }
        }

        $entitlements['has_active_subscription']   = $hasActive;
        $entitlements['trainers_used']             = $this->trainersUsed($fcUserId);
        $entitlements['message_quota_by_trainer']  = (object) $quotaByTrainer;

        return $entitlements;
    }

    /**
     * Trainer slots in use: active coaching links plus outstanding requests. A
     * pending request has to count, or a user could queue ten requests against a
     * two-trainer plan and accept them all (Q14).
     */
    public function trainersUsed(int $fcUserId): int
    {
        global $wpdb;

        if ($fcUserId <= 0) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count(self::SLOT_CONSUMING), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated %s list.
        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}fc_user_trainers
                 WHERE user_id = %d AND status IN ({$placeholders})";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on the next line.
        return (int) $wpdb->get_var($wpdb->prepare($sql, array_merge([$fcUserId], self::SLOT_CONSUMING)));
    }

    /**
     * Every granting subscription's plan, with its decoded `features` JSON.
     *
     * @return array<int,array{trainer_id:?int,max_messages_per_week:?int,features:array<string,mixed>}>
     */
    private function activePlanFeatures(int $fcUserId): array
    {
        global $wpdb;

        if ($fcUserId <= 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count(self::GRANTING), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated %s list.
        $sql = "SELECT s.trainer_id, p.max_messages_per_week, p.features
                  FROM {$wpdb->prefix}fc_subscriptions s
                  JOIN {$wpdb->prefix}fc_plans p ON p.id = s.plan_id
                 WHERE s.user_id = %d
                   AND s.status IN ({$placeholders})
                   AND (s.end_date IS NULL OR s.end_date >= %s)";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on the next line.
        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, array_merge([$fcUserId], self::GRANTING, [gmdate('Y-m-d')])),
            ARRAY_A
        );

        return array_map(static function (array $row): array {
            $features = json_decode((string) $row['features'], true);

            return [
                'trainer_id'            => null === $row['trainer_id'] ? null : (int) $row['trainer_id'],
                'max_messages_per_week' => null === $row['max_messages_per_week']
                    ? null
                    : (int) $row['max_messages_per_week'],
                'features'              => is_array($features) ? $features : [],
            ];
        }, $rows ?: []);
    }

    /**
     * One leaf of the merge: booleans union, numbers take the max, and `null`
     * (unlimited) beats any number.
     */
    private function mergeValue(mixed $current, mixed $incoming): mixed
    {
        if (is_bool($current) || is_bool($incoming)) {
            return (bool) $current || (bool) $incoming;
        }

        // A plan that declares the key at all can lift an unset baseline.
        if (null === $current) {
            return $incoming;
        }

        if (null === $incoming) {
            // Explicit "unlimited" from an active plan wins over any ceiling.
            return null;
        }

        if (is_numeric($current) && is_numeric($incoming)) {
            return max((int) $current, (int) $incoming);
        }

        return $incoming;
    }
}
