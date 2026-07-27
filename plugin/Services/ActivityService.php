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

        return array_map(static fn(array $row): array => self::presentRow($row), $rows ?: []);
    }

    /**
     * `GET /activity` — the paged feed behind the dashboard's last-ten (W2.4).
     *
     * **Audit rows are excluded here as they are in `feed()`.** They record what
     * someone else did to the member's data and carry before/after values; they
     * belong in the admin's audit view, not in the member's "here is what you
     * did" list. The member does see staff edits — but as the `source: 'admin'`
     * label on the affected record, where it means something, rather than as a
     * diff in a feed.
     *
     * @param string[] $types Restrict to these event types; empty means all.
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function paged(int $accountId, int $page = 1, int $perPage = 20, array $types = []): array
    {
        global $wpdb;

        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        if ($accountId <= 0) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }

        $where  = 'account_id = %d AND is_audit = 0';
        $params = [$accountId];

        if ([] !== $types) {
            $where .= ' AND type IN (' . implode(',', array_fill(0, count($types), '%s')) . ')';
            $params = array_merge($params, $types);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is placeholders only.
        $countSql = "SELECT COUNT(*) FROM {$wpdb->prefix}fc_activity_log WHERE {$where}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $total = (int) $wpdb->get_var($wpdb->prepare($countSql, ...$params));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is placeholders only.
        $sql = "SELECT type, title, detail, subject_type, subject_id, meta, created_at
                  FROM {$wpdb->prefix}fc_activity_log
                 WHERE {$where}
                 ORDER BY created_at DESC, id DESC
                 LIMIT %d OFFSET %d";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, ...array_merge($params, [$perPage, $offset])),
            ARRAY_A
        );

        return [
            'items'    => array_map(static fn(array $row): array => self::presentRow($row), $rows ?: []),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * The distinct event types in a member's feed, for the filter control.
     *
     * Derived from what is actually there rather than from the full event
     * vocabulary: offering "nutrition.logged" to somebody who has never logged a
     * meal is a filter that can only return nothing.
     *
     * @return string[]
     */
    public function types(int $accountId): array
    {
        global $wpdb;

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT type FROM {$wpdb->prefix}fc_activity_log
              WHERE account_id = %d AND is_audit = 0
              ORDER BY type ASC",
            $accountId
        ));

        return array_values(array_map('strval', $rows ?: []));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function presentRow(array $row): array
    {
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
