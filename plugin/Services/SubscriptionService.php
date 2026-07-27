<?php

namespace FitnessClub\Services;

use FitnessClub\Payments\GatewayRegistry;
use FitnessClub\Payments\GatewayResult;
use FitnessClub\Payments\SubscriptionRequest;
use FitnessClub\Support\DomainException;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The subscription state machine (plans/03-backend.md, W3.2).
 *
 * ## A member may hold several at once — one per trainer (Q3)
 *
 * This is the rule that shapes everything else. "The subscription" does not
 * exist; there is a *set*, and the platform tier is the one with
 * `trainer_id IS NULL`. So:
 *
 *   - starting a plan with trainer B does not touch the plan with trainer A;
 *   - starting a second plan with trainer A **replaces** the first, because two
 *     concurrent subscriptions to the same coach is double billing, not
 *     generosity;
 *   - entitlements are the union across the set, which is
 *     `EntitlementService`'s job rather than this one's.
 *
 * ## Cancelling means "at period end", always
 *
 * The member has paid for a period; ending access the moment they click cancel
 * takes something they already bought. `cancel_at_period_end` is set, the status
 * stays `active`, and the expiry cron flips it when `end_date` passes. `resume()`
 * is simply clearing that flag, which is why it can only work while the period
 * is still running.
 *
 * ## Nothing here enforces a cap retroactively (Q16)
 *
 * Downgrading to a plan with fewer trainer slots does not sever a relationship.
 * A limit constrains future actions and never destroys existing state — see the
 * grandfathering note in `EntitlementService`.
 */
final class SubscriptionService
{
    /** Statuses that mean the member currently has the plan's features. */
    public const GRANTING = ['active', 'trialing'];

    /** Cycle → the `end_date` interval it buys. */
    private const CYCLES = [
        'weekly'    => '+7 days',
        'monthly'   => '+1 month',
        'quarterly' => '+3 months',
        'yearly'    => '+1 year',
    ];

    /** Cycle → the plan column holding its price. */
    private const PRICE_COLUMNS = [
        'weekly'    => 'price_weekly',
        'monthly'   => 'price_monthly',
        'quarterly' => 'price_quarterly',
        'yearly'    => 'price_yearly',
    ];

    /**
     * `GET /billing/subscription` — everything the member holds.
     *
     * A list, not a record: see the class note. The screen renders the platform
     * tier and each coaching plan side by side.
     *
     * @return array<string,mixed>
     */
    public function forUser(int $fcUserId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, p.plan_name, p.slug AS plan_slug, p.description, p.features,
                    p.owner_type, t.display_name AS trainer_name
               FROM {$wpdb->prefix}fc_subscriptions s
               JOIN {$wpdb->prefix}fc_plans p ON p.id = s.plan_id
               LEFT JOIN {$wpdb->prefix}fc_trainers t ON t.id = s.trainer_id
              WHERE s.user_id = %d
              ORDER BY FIELD(s.status, 'active', 'trialing', 'past_due', 'cancelled', 'expired'),
                       s.end_date DESC, s.id DESC",
            $fcUserId
        ), ARRAY_A) ?: [];

        $items = array_map(fn(array $row): array => $this->present($row), $rows);

        return [
            'items'        => $items,
            'entitlements' => (new EntitlementService())->forUser($fcUserId),
            'has_active'   => (bool) array_filter(
                $items,
                static fn(array $row): bool => in_array($row['status'], self::GRANTING, true)
            ),
        ];
    }

    /**
     * `GET /billing/plans` — what the member could buy.
     *
     * @return array<int,array<string,mixed>>
     */
    public function availablePlans(?int $trainerId = null): array
    {
        global $wpdb;

        $rows = null === $trainerId
            ? $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}fc_plans
                  WHERE is_active = 1 AND owner_type = 'platform'
                  ORDER BY sort_order ASC, id ASC",
                ARRAY_A
            )
            : $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fc_plans
                  WHERE is_active = 1 AND (owner_type = 'platform' OR trainer_id = %d)
                  ORDER BY owner_type ASC, sort_order ASC, id ASC",
                $trainerId
            ), ARRAY_A);

        return array_map(function (array $row): array {
            $features = json_decode((string) $row['features'], true);

            return [
                'id'          => (int) $row['id'],
                'plan_name'   => $row['plan_name'],
                'slug'        => $row['slug'],
                'description' => $row['description'],
                'owner_type'  => $row['owner_type'],
                'trainer_id'  => null === $row['trainer_id'] ? null : (int) $row['trainer_id'],
                'currency'    => $row['currency'],
                'prices'      => $this->prices($row),
                'features'    => is_array($features) ? $features : [],
                'max_trainers' => (int) $row['max_trainers'],
                'max_messages_per_week' => (int) $row['max_messages_per_week'],
            ];
        }, $rows ?: []);
    }

    /**
     * `POST /billing/checkout` — start (or replace) a subscription.
     *
     * The price is looked up from the plan and the cycle. **Nothing about the
     * amount comes from the caller** — see `SubscriptionRequest`.
     *
     * @return array<string,mixed>
     */
    public function checkout(int $fcUserId, int $planId, string $cycle, ?string $returnUrl = null): array
    {
        global $wpdb;

        $plan  = $this->planRow($planId);
        $cycle = $this->normaliseCycle($cycle);

        $column = self::PRICE_COLUMNS[$cycle];
        $price  = $plan[$column];

        if (null === $price) {
            throw new DomainException(
                'fc_cycle_unavailable',
                /* translators: %s: billing cycle */
                sprintf(__('This plan is not sold %s.', 'fitnessclub'), $cycle),
                400
            );
        }

        $trainerId = null === $plan['trainer_id'] ? null : (int) $plan['trainer_id'];
        $gateway   = GatewayRegistry::active();

        $request = new SubscriptionRequest(
            fcUserId:      $fcUserId,
            planId:        $planId,
            planName:      (string) $plan['plan_name'],
            cycle:         $cycle,
            // Minor units at the boundary (D8). The column is DECIMAL(10,2);
            // rounding before the cast avoids 1998 for a stored 19.99.
            amountMinor:   (int) round(((float) $price) * 100),
            currency:      (string) ($plan['currency'] ?: FitnessClub()->options->get('billing.currency', 'USD')),
            trainerId:     $trainerId,
            customerEmail: $this->emailFor($fcUserId),
            returnUrl:     $returnUrl,
            metadata:      ['fc_user_id' => $fcUserId, 'plan_id' => $planId]
        );

        $result = $gateway->createSubscription($request);

        if ($result->isFailed()) {
            throw new DomainException(
                'fc_checkout_failed',
                $result->failureReason ?? __('That payment could not be started.', 'fitnessclub'),
                402
            );
        }

        // A redirect means nothing is owed yet and nothing is active yet — the
        // webhook decides. Writing a subscription row here would give the member
        // features they have not paid for if they abandon the checkout page.
        if ($result->isRedirect()) {
            return [
                'status'       => GatewayResult::REDIRECT,
                'redirect_url' => $result->redirectUrl,
            ];
        }

        $subscriptionId = $this->activate($fcUserId, $plan, $cycle, $trainerId, $gateway->name(), $result);

        $this->recordPayment($fcUserId, $subscriptionId, $request, $gateway->name(), $result);

        return [
            'status'       => GatewayResult::SETTLED,
            'subscription' => $this->one($fcUserId, $subscriptionId),
        ];
    }

    /**
     * `POST /billing/subscription/cancel`.
     *
     * @return array<string,mixed>
     */
    public function cancel(int $fcUserId, int $subscriptionId, bool $atPeriodEnd = true): array
    {
        global $wpdb;

        $row = $this->ownedRow($fcUserId, $subscriptionId);

        if (!in_array($row['status'], self::GRANTING, true)) {
            throw new DomainException(
                'fc_not_cancellable',
                __('That subscription is not active.', 'fitnessclub'),
                409
            );
        }

        if (!empty($row['gateway_subscription_id'])) {
            GatewayRegistry::get($row['gateway'] ?: null)
                ->cancelSubscription((string) $row['gateway_subscription_id'], $atPeriodEnd);
        }

        $fields = ['updated_at' => gmdate('Y-m-d H:i:s')];

        if ($atPeriodEnd) {
            // Status stays active: the member keeps what they paid for, and the
            // expiry cron flips it when end_date passes.
            $fields['cancel_at_period_end'] = 1;
            $fields['auto_renew']           = 0;
        } else {
            $fields['status']   = 'cancelled';
            $fields['end_date'] = gmdate('Y-m-d');
        }

        $wpdb->update($wpdb->prefix . 'fc_subscriptions', $fields, ['id' => $subscriptionId]);

        $this->settle($fcUserId);

        return $this->one($fcUserId, $subscriptionId);
    }

    /**
     * `POST /billing/subscription/resume` — undo a pending cancellation.
     *
     * Only meaningful while the period is still running; once it has expired
     * there is nothing to resume and the member starts a new subscription.
     *
     * @return array<string,mixed>
     */
    public function resume(int $fcUserId, int $subscriptionId): array
    {
        global $wpdb;

        $row = $this->ownedRow($fcUserId, $subscriptionId);

        if (!$row['cancel_at_period_end']) {
            throw new DomainException(
                'fc_not_cancelled',
                __('That subscription is not scheduled to end.', 'fitnessclub'),
                409
            );
        }

        if (!in_array($row['status'], self::GRANTING, true)) {
            throw new DomainException(
                'fc_already_ended',
                __('That subscription has already ended. Start a new one instead.', 'fitnessclub'),
                409
            );
        }

        $wpdb->update(
            $wpdb->prefix . 'fc_subscriptions',
            ['cancel_at_period_end' => 0, 'auto_renew' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $subscriptionId]
        );

        $this->settle($fcUserId);

        return $this->one($fcUserId, $subscriptionId);
    }

    /**
     * `POST /billing/subscription/change` — move to another plan.
     *
     * Implemented as cancel-and-start rather than an in-place plan swap, because
     * the two subscriptions may have different trainers and the *set* is what
     * matters. Downgrades take effect immediately in what is *reported*; nothing
     * existing is severed (Q16).
     *
     * @return array<string,mixed>
     */
    public function change(int $fcUserId, int $subscriptionId, int $newPlanId, string $cycle): array
    {
        $existing = $this->ownedRow($fcUserId, $subscriptionId);

        if ((int) $existing['plan_id'] === $newPlanId) {
            throw new DomainException(
                'fc_same_plan',
                __('That is already the current plan.', 'fitnessclub'),
                409
            );
        }

        // End the old one now rather than at period end: the member is moving,
        // not leaving, and leaving both active would double-count entitlements
        // for the same trainer.
        $this->cancel($fcUserId, $subscriptionId, false);

        return $this->checkout($fcUserId, $newPlanId, $cycle);
    }

    /**
     * The expiry sweep, run daily.
     *
     * Two transitions, and they are deliberately separate:
     *
     *   - a subscription whose period ended and which was **not** set to renew
     *     becomes `expired`;
     *   - one that *was* set to renew but has not been paid becomes `past_due`,
     *     which the dunning job then chases. Expiring it immediately would cut
     *     off a member whose card simply needs re-trying.
     *
     * @return array{expired:int,past_due:int}
     */
    public function runExpiry(): array
    {
        global $wpdb;

        $today = gmdate('Y-m-d');

        $expired = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_subscriptions
                SET status = 'expired', updated_at = UTC_TIMESTAMP()
              WHERE status IN ('active', 'trialing')
                AND end_date IS NOT NULL AND end_date < %s
                AND (auto_renew = 0 OR cancel_at_period_end = 1)",
            $today
        ));

        $pastDue = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_subscriptions
                SET status = 'past_due', updated_at = UTC_TIMESTAMP()
              WHERE status IN ('active', 'trialing')
                AND end_date IS NOT NULL AND end_date < %s
                AND auto_renew = 1 AND cancel_at_period_end = 0",
            $today
        ));

        if ($expired > 0 || $pastDue > 0) {
            // Every affected member's entitlements just changed.
            EntitlementService::flush();
        }

        return ['expired' => $expired, 'past_due' => $pastDue];
    }

    /**
     * Extend a subscription that has been paid for — the renewal path.
     *
     * Called by the webhook and by the dunning retry. Both must run identical
     * code (plans/03-backend.md), which is why neither has its own copy.
     */
    public function renew(int $subscriptionId, ?string $cycle = null): void
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_subscriptions WHERE id = %d",
            $subscriptionId
        ), ARRAY_A);

        if (!is_array($row)) {
            return;
        }

        $cycle = $this->normaliseCycle($cycle ?? (string) $row['subscription_type']);

        // Extend from the later of today and the current end date, so a renewal
        // that lands early does not shorten the period the member paid for.
        $from = max(gmdate('Y-m-d'), (string) ($row['end_date'] ?? gmdate('Y-m-d')));

        $wpdb->update($wpdb->prefix . 'fc_subscriptions', [
            'status'     => 'active',
            'end_date'   => gmdate('Y-m-d', (int) strtotime($from . ' ' . self::CYCLES[$cycle])),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $subscriptionId]);

        EntitlementService::flush((int) $row['user_id']);
    }

    // -------------------------------------------------------------- internals

    /**
     * Write the active subscription, replacing any the member already holds with
     * the same trainer.
     *
     * @param array<string,mixed> $plan
     */
    private function activate(
        int $fcUserId,
        array $plan,
        string $cycle,
        ?int $trainerId,
        string $gatewayName,
        GatewayResult $result
    ): int {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        // One subscription per trainer: a second concurrent plan with the same
        // coach is double billing. The platform tier (trainer_id NULL) follows
        // the same rule against itself.
        $superseded = null === $trainerId
            ? $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fc_subscriptions
                  WHERE user_id = %d AND trainer_id IS NULL AND status IN ('active', 'trialing')",
                $fcUserId
            ))
            : $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fc_subscriptions
                  WHERE user_id = %d AND trainer_id = %d AND status IN ('active', 'trialing')",
                $fcUserId,
                $trainerId
            ));

        foreach ($superseded ?: [] as $oldId) {
            $wpdb->update(
                $wpdb->prefix . 'fc_subscriptions',
                ['status' => 'cancelled', 'end_date' => gmdate('Y-m-d'), 'updated_at' => $now],
                ['id' => (int) $oldId]
            );
        }

        $wpdb->insert($wpdb->prefix . 'fc_subscriptions', [
            'user_id'           => $fcUserId,
            'plan_id'           => (int) $plan['id'],
            'trainer_id'        => $trainerId,
            'start_date'        => gmdate('Y-m-d'),
            'end_date'          => gmdate('Y-m-d', (int) strtotime(self::CYCLES[$cycle])),
            'subscription_type' => $cycle,
            'status'            => 'active',
            'auto_renew'        => 1,
            'cancel_at_period_end' => 0,
            'price_paid'        => $plan[self::PRICE_COLUMNS[$cycle]],
            'currency'          => $plan['currency'],
            'gateway'           => $gatewayName,
            'gateway_customer_id'     => $result->externalCustomerId,
            'gateway_subscription_id' => $result->externalSubscriptionId,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        $id = (int) $wpdb->insert_id;

        $this->settle($fcUserId);

        return $id;
    }

    private function recordPayment(
        int $fcUserId,
        int $subscriptionId,
        SubscriptionRequest $request,
        string $gatewayName,
        GatewayResult $result
    ): void {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_payments', [
            'user_id'         => $fcUserId,
            'subscription_id' => $subscriptionId,
            'amount'          => $request->amountDecimal(),
            'currency'        => $request->currency,
            'payment_type'    => 'subscription',
            'gateway'         => $gatewayName,
            'transaction_id'  => $result->transactionId,
            'status'          => 'completed',
            'gateway_payload' => wp_json_encode($result->raw),
            'payment_date'    => $now,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    /**
     * Everything that must happen after a subscription changes.
     *
     * One place, because forgetting the flush is a silent bug: the member keeps
     * the old entitlements for the rest of the request and the screen shows a
     * plan they no longer hold.
     */
    private function settle(int $fcUserId): void
    {
        EntitlementService::flush($fcUserId);

        do_action('fitnessclub/user_data_changed', $fcUserId, 'subscription');
    }

    /**
     * @return array<string,mixed>
     */
    private function one(int $fcUserId, int $subscriptionId): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, p.plan_name, p.slug AS plan_slug, p.description, p.features,
                    p.owner_type, t.display_name AS trainer_name
               FROM {$wpdb->prefix}fc_subscriptions s
               JOIN {$wpdb->prefix}fc_plans p ON p.id = s.plan_id
               LEFT JOIN {$wpdb->prefix}fc_trainers t ON t.id = s.trainer_id
              WHERE s.id = %d AND s.user_id = %d",
            $subscriptionId,
            $fcUserId
        ), ARRAY_A);

        if (!is_array($row)) {
            throw new DomainException(
                'fc_subscription_not_found',
                __('That subscription does not exist.', 'fitnessclub'),
                404
            );
        }

        return $this->present($row);
    }

    /**
     * @return array<string,mixed>
     */
    private function ownedRow(int $fcUserId, int $subscriptionId): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_subscriptions WHERE id = %d AND user_id = %d",
            $subscriptionId,
            $fcUserId
        ), ARRAY_A);

        if (!is_array($row)) {
            // Somebody else's subscription is a 404, never a 403.
            throw new DomainException(
                'fc_subscription_not_found',
                __('That subscription does not exist.', 'fitnessclub'),
                404
            );
        }

        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    private function planRow(int $planId): array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fc_plans WHERE id = %d AND is_active = 1",
            $planId
        ), ARRAY_A);

        if (!is_array($row)) {
            throw new DomainException(
                'fc_plan_not_found',
                __('That plan is not available.', 'fitnessclub'),
                404
            );
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,float|null>
     */
    private function prices(array $row): array
    {
        $prices = [];

        foreach (self::PRICE_COLUMNS as $cycle => $column) {
            $prices[$cycle] = null === $row[$column] ? null : round((float) $row[$column], 2);
        }

        return $prices;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        $features = json_decode((string) ($row['features'] ?? ''), true);

        return [
            'id'           => (int) $row['id'],
            'plan_id'      => (int) $row['plan_id'],
            'plan_name'    => $row['plan_name'] ?? null,
            'plan_slug'    => $row['plan_slug'] ?? null,
            'description'  => $row['description'] ?? null,
            'owner_type'   => $row['owner_type'] ?? 'platform',
            'trainer_id'   => null === $row['trainer_id'] ? null : (int) $row['trainer_id'],
            'trainer_name' => $row['trainer_name'] ?? null,
            'status'       => $row['status'],
            'cycle'        => $row['subscription_type'],
            'start_date'   => $row['start_date'],
            'end_date'     => $row['end_date'],
            'auto_renew'   => (bool) $row['auto_renew'],
            // The flag the member's "your plan ends on…" notice reads.
            'cancel_at_period_end' => (bool) $row['cancel_at_period_end'],
            'price_paid'   => null === $row['price_paid'] ? null : round((float) $row['price_paid'], 2),
            'currency'     => $row['currency'],
            'gateway'      => $row['gateway'],
            'features'     => is_array($features) ? $features : [],
        ];
    }

    private function normaliseCycle(string $cycle): string
    {
        $cycle = strtolower(trim($cycle));

        if (!isset(self::CYCLES[$cycle])) {
            throw new DomainException(
                'fc_invalid_cycle',
                __('Pick a billing cycle.', 'fitnessclub'),
                400
            );
        }

        return $cycle;
    }

    private function emailFor(int $fcUserId): ?string
    {
        global $wpdb;

        $email = $wpdb->get_var($wpdb->prepare(
            "SELECT a.email FROM {$wpdb->prefix}fc_users u
               JOIN {$wpdb->prefix}fc_accounts a ON a.id = u.account_id
              WHERE u.id = %d",
            $fcUserId
        ));

        return null === $email || '' === $email ? null : (string) $email;
    }
}
