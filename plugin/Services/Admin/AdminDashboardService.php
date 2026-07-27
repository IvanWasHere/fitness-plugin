<?php

namespace FitnessClub\Services\Admin;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `GET /admin/dashboard` — the platform's numbers in one request (W2.5).
 *
 * The prototype fired **ten unawaited `db.count()` calls**, each triggering
 * `m.redraw()`, so mounting the dashboard rendered ten times and the cards
 * populated in a random order (05, "fixes carried from the prototype"). One
 * query set, one response.
 *
 * Counts are cheap; the revenue figures are the only aggregate that touches a
 * large table, and they are scoped to a window rather than all time.
 */
final class AdminDashboardService
{
    /**
     * @return array<string,mixed>
     */
    public function build(): array
    {
        global $wpdb;

        $today     = gmdate('Y-m-d');
        $monthAgo  = gmdate('Y-m-d', strtotime($today . ' -29 days'));
        $weekAgo   = gmdate('Y-m-d', strtotime($today . ' -6 days'));

        return [
            'generated_at' => gmdate('c'),
            'counts'       => [
                'members'  => $this->count('fc_users'),
                'trainers' => $this->count('fc_trainers'),
                'workouts' => $this->count('fc_workouts'),
                'foods'    => $this->count('fc_foods'),
                'active_subscriptions' => (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}fc_subscriptions WHERE status = 'active'"
                ),
                'open_tickets' => (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}fc_tickets
                      WHERE status IN ('open', 'in_progress', 'waiting_user')"
                ),
                'sessions_this_week' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}fc_workout_sessions
                      WHERE status = 'completed' AND log_date BETWEEN %s AND %s",
                    $weekAgo,
                    $today
                )),
                'meals_this_week' => (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}fc_nutrition_logs
                      WHERE log_date BETWEEN %s AND %s",
                    $weekAgo,
                    $today
                )),
            ],
            'revenue' => $this->revenue($monthAgo, $today),
            // A signup list rather than a count: "12 new members" tells an
            // administrator nothing they can act on, whereas the names do.
            'recent_signups' => $this->recentSignups(),
            'failed_payments' => $this->failedPayments(),
            'inactive_members' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fc_users u
                   WHERE NOT EXISTS (
                       SELECT 1 FROM {$wpdb->prefix}fc_workout_sessions s
                        WHERE s.user_id = u.id AND s.log_date >= %s
                   )",
                $monthAgo
            )),
        ];
    }

    /**
     * Revenue over the window, plus a simple MRR from active subscriptions.
     *
     * MRR normalises yearly plans to a monthly figure — quoting a year's price
     * as this month's recurring revenue is the classic way a dashboard flatters
     * itself.
     *
     * @return array<string,mixed>
     */
    private function revenue(string $from, string $to): array
    {
        global $wpdb;

        $completed = $wpdb->get_row($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS payments, currency
               FROM {$wpdb->prefix}fc_payments
              WHERE status = 'completed' AND DATE(created_at) BETWEEN %s AND %s",
            $from,
            $to
        ), ARRAY_A) ?: [];

        $mrr = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(
                        CASE s.subscription_type
                            WHEN 'yearly' THEN p.price_yearly / 12
                            ELSE p.price_monthly
                        END
                    ), 0)
               FROM {$wpdb->prefix}fc_subscriptions s
               JOIN {$wpdb->prefix}fc_plans p ON p.id = s.plan_id
              WHERE s.status = 'active'"
        );

        return [
            'window_total' => round((float) ($completed['total'] ?? 0), 2),
            'window_payments' => (int) ($completed['payments'] ?? 0),
            'mrr'      => round($mrr, 2),
            'currency' => $completed['currency'] ?? (string) FitnessClub()->options->get('billing.currency', 'USD'),
            'from'     => $from,
            'to'       => $to,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function recentSignups(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT u.id, u.display_name, u.created_at, a.email, a.status
               FROM {$wpdb->prefix}fc_users u
               JOIN {$wpdb->prefix}fc_accounts a ON a.id = u.account_id
              ORDER BY u.created_at DESC, u.id DESC
              LIMIT 8",
            ARRAY_A
        ) ?: [];

        return array_map(static fn(array $row): array => [
            'id'           => (int) $row['id'],
            'display_name' => $row['display_name'],
            'email'        => $row['email'],
            'status'       => $row['status'],
            'created_at'   => gmdate('c', strtotime((string) $row['created_at'] . ' UTC')),
        ], $rows);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function failedPayments(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT p.id, p.amount, p.currency, p.created_at, u.display_name
               FROM {$wpdb->prefix}fc_payments p
               LEFT JOIN {$wpdb->prefix}fc_users u ON u.id = p.user_id
              WHERE p.status = 'failed'
              ORDER BY p.created_at DESC
              LIMIT 8",
            ARRAY_A
        ) ?: [];

        return array_map(static fn(array $row): array => [
            'id'           => (int) $row['id'],
            'display_name' => $row['display_name'],
            'amount'       => round((float) $row['amount'], 2),
            'currency'     => $row['currency'],
            'created_at'   => gmdate('c', strtotime((string) $row['created_at'] . ' UTC')),
        ], $rows);
    }

    private function count(string $table): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a literal from the caller.
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}{$table}");
    }
}
