<?php

namespace FitnessClub\Services;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The activity feed and the audit trail (plans/03-backend.md).
 *
 * Two kinds of row in one table, told apart by `is_audit`:
 *
 *   - **Feed** rows are what the user reads on their dashboard — "you completed
 *     Upper Body Power". Pruned after 12 months by cron.
 *   - **Audit** rows record that *someone else* changed a user's data — an admin
 *     editing health entries (Q10), a trainer reassigning a plan. They carry the
 *     actor and the before/after in `meta`, and they are **exempt from pruning**;
 *     an audit trail with a retention window is not an audit trail.
 *
 * `account_id` is the subject (whose feed it belongs to), `actor_account_id` is
 * who did it. They differ exactly when someone acted on another person's data,
 * which is the question an audit trail exists to answer.
 */
final class ActivityService
{
    /**
     * Record a feed entry for something the user did themselves.
     *
     * @param string               $type    Dotted event name, e.g. "workout.completed".
     * @param array<string,mixed>  $meta    Anything the feed renderer may want later.
     */
    public function record(
        int $accountId,
        string $type,
        string $title,
        string $detail = '',
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $meta = []
    ): int {
        return $this->insert($accountId, $accountId, $type, $title, $detail, $subjectType, $subjectId, $meta, false);
    }

    /**
     * Record that one account changed another's data. Never pruned.
     *
     * @param array<string,mixed> $meta Include `before`/`after` — the point of the row.
     */
    public function audit(
        int $subjectAccountId,
        int $actorAccountId,
        string $type,
        string $title,
        string $detail = '',
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $meta = []
    ): int {
        return $this->insert(
            $subjectAccountId,
            $actorAccountId,
            $type,
            $title,
            $detail,
            $subjectType,
            $subjectId,
            $meta,
            true
        );
    }

    /**
     * The "Recent Activity" list for one user, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function feed(int $accountId, int $limit = 10): array
    {
        global $wpdb;

        if ($accountId <= 0) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT type, title, detail, subject_type, subject_id, meta, created_at
               FROM {$wpdb->prefix}fc_activity_log
              WHERE account_id = %d AND is_audit = 0
              ORDER BY created_at DESC, id DESC
              LIMIT %d",
            $accountId,
            max(1, min(100, $limit))
        ), ARRAY_A);

        return array_map(static function (array $row): array {
            $meta = json_decode((string) $row['meta'], true);

            return [
                'type'         => $row['type'],
                'title'        => $row['title'],
                'detail'       => $row['detail'],
                'subject_type' => $row['subject_type'],
                'subject_id'   => null === $row['subject_id'] ? null : (int) $row['subject_id'],
                'meta'         => is_array($meta) ? $meta : [],
                'occurred_at'  => gmdate('c', strtotime((string) $row['created_at'] . ' UTC')),
            ];
        }, $rows ?: []);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function insert(
        int $accountId,
        int $actorAccountId,
        string $type,
        string $title,
        string $detail,
        ?string $subjectType,
        ?int $subjectId,
        array $meta,
        bool $isAudit
    ): int {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'fc_activity_log', [
            'account_id'       => $accountId,
            'actor_account_id' => $actorAccountId,
            'type'             => $type,
            'subject_type'     => $subjectType,
            'subject_id'       => $subjectId,
            'title'            => mb_substr($title, 0, 255),
            'detail'           => mb_substr($detail, 0, 255),
            'meta'             => $meta === [] ? null : wp_json_encode($meta),
            'is_audit'         => $isAudit ? 1 : 0,
            'created_at'       => gmdate('Y-m-d H:i:s'),
        ]);

        return (int) $wpdb->insert_id;
    }
}
