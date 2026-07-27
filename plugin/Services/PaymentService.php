<?php

namespace FitnessClub\Services;

use FitnessClub\Payments\GatewayRegistry;
use FitnessClub\Payments\WebhookEvent;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Payment history, the webhook, and dunning (plans/02-api-contract.md, W3.2).
 *
 * ## The webhook is the only public write endpoint in the product
 *
 * So it is the one that has to be paranoid, and the order of operations is the
 * paranoia:
 *
 *   1. **the adapter verifies the signature before anything parses the body** —
 *      an implementation that reads JSON first is reading attacker-controlled
 *      input;
 *   2. **dedupe on the gateway's own event id** — gateways retry on any non-2xx
 *      and occasionally deliver twice on a 2xx, so without this a retried
 *      `payment_succeeded` extends a subscription twice;
 *   3. **answer 200 even for a duplicate or an unknown type**, so the gateway
 *      stops retrying something we will never act on. Only a failed signature
 *      earns a non-2xx, because that is the one an administrator should see in
 *      the gateway's dashboard.
 *
 * Amounts are never taken from the request body in preference to what the
 * gateway's own API reports — the adapter is responsible for that, and this
 * service records what the adapter returns.
 */
final class PaymentService
{
    /** How long a processed event id is remembered, in seconds. */
    private const DEDUPE_TTL = 7 * DAY_IN_SECONDS;

    /**
     * `GET /billing/payments` — the member's own history.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
     */
    public function history(int $fcUserId, int $page = 1, int $perPage = 20): array
    {
        global $wpdb;

        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fc_payments WHERE user_id = %d",
            $fcUserId
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, pl.plan_name
               FROM {$wpdb->prefix}fc_payments p
               LEFT JOIN {$wpdb->prefix}fc_subscriptions s ON s.id = p.subscription_id
               LEFT JOIN {$wpdb->prefix}fc_plans pl ON pl.id = s.plan_id
              WHERE p.user_id = %d
              ORDER BY p.payment_date DESC, p.id DESC
              LIMIT %d OFFSET %d",
            $fcUserId,
            $perPage,
            $offset
        ), ARRAY_A) ?: [];

        return [
            'items'    => array_map(fn(array $row): array => $this->present($row), $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * `POST /billing/webhook/{gateway}` — public, signature-verified, idempotent.
     *
     * @return array{status:int,body:array<string,mixed>}
     */
    public function handleWebhook(string $gatewayName, WP_REST_Request $request): array
    {
        $gateway = GatewayRegistry::get($gatewayName);
        $event   = $gateway->handleWebhook($request);

        if (WebhookEvent::INVALID === $event->type) {
            // The only non-2xx. A bad signature is either a misconfiguration or
            // an attack, and both want to be visible in the gateway's dashboard.
            return [
                'status' => 401,
                'body'   => ['code' => 'fc_webhook_invalid', 'message' => $event->failureReason],
            ];
        }

        if (!$event->isActionable()) {
            return ['status' => 200, 'body' => ['ok' => true, 'ignored' => true]];
        }

        if ($this->alreadyProcessed($gatewayName, $event->externalEventId)) {
            // 200, not 409: the gateway is doing exactly what it should by
            // retrying, and an error would make it keep going.
            return ['status' => 200, 'body' => ['ok' => true, 'duplicate' => true]];
        }

        $this->apply($event);
        $this->remember($gatewayName, $event->externalEventId);

        return ['status' => 200, 'body' => ['ok' => true, 'type' => $event->type]];
    }

    /**
     * The dunning sweep, run daily (plans/03-backend.md#scheduled-jobs).
     *
     * Escalating rather than immediate: a card that failed once usually works on
     * the second try, and suspending a paying member over a temporary decline is
     * both bad for them and expensive to undo. Past-due members keep their
     * features throughout — `EntitlementService` fails open to the free tier
     * rather than locking them out mid-grace-period.
     *
     * @return array{notified:int,suspended:int}
     */
    public function runDunning(): array
    {
        global $wpdb;

        $graceDays = (int) FitnessClub()->config('fitnessclub.limits.dunning_grace_days', 14);
        $cutoff    = gmdate('Y-m-d', strtotime("-{$graceDays} days"));

        $notified   = 0;
        $suspended  = 0;

        $pastDue = $wpdb->get_results(
            "SELECT s.id, s.user_id, s.end_date, u.account_id
               FROM {$wpdb->prefix}fc_subscriptions s
               JOIN {$wpdb->prefix}fc_users u ON u.id = s.user_id
              WHERE s.status = 'past_due'",
            ARRAY_A
        ) ?: [];

        $notifications = new NotificationService();

        foreach ($pastDue as $row) {
            if ((string) $row['end_date'] < $cutoff) {
                $wpdb->update(
                    $wpdb->prefix . 'fc_subscriptions',
                    ['status' => 'expired', 'updated_at' => gmdate('Y-m-d H:i:s')],
                    ['id' => (int) $row['id']]
                );

                EntitlementService::flush((int) $row['user_id']);
                $suspended++;

                continue;
            }

            $notifications->notify(
                (int) $row['account_id'],
                'subscription',
                __('Your payment did not go through', 'fitnessclub'),
                __('Update your payment details to keep your plan.', 'fitnessclub'),
                '/subscription'
            );

            $notified++;
        }

        return ['notified' => $notified, 'suspended' => $suspended];
    }

    // -------------------------------------------------------------- internals

    private function apply(WebhookEvent $event): void
    {
        global $wpdb;

        $subscriptionId = $this->subscriptionFor($event->externalSubscriptionId);

        if (null === $subscriptionId) {
            return;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT user_id, subscription_type FROM {$wpdb->prefix}fc_subscriptions WHERE id = %d",
            $subscriptionId
        ), ARRAY_A);

        $fcUserId = (int) ($row['user_id'] ?? 0);
        $now      = gmdate('Y-m-d H:i:s');

        switch ($event->type) {
            case WebhookEvent::PAYMENT_SUCCEEDED:
            case WebhookEvent::SUBSCRIPTION_RENEWED:
                $this->writePayment($fcUserId, $subscriptionId, $event, 'completed');
                // Renewal and webhook run the same code, deliberately.
                (new SubscriptionService())->renew($subscriptionId);
                break;

            case WebhookEvent::PAYMENT_FAILED:
                $this->writePayment($fcUserId, $subscriptionId, $event, 'failed');

                $wpdb->update(
                    $wpdb->prefix . 'fc_subscriptions',
                    ['status' => 'past_due', 'updated_at' => $now],
                    ['id' => $subscriptionId]
                );

                EntitlementService::flush($fcUserId);
                break;

            case WebhookEvent::SUBSCRIPTION_CANCELLED:
                $wpdb->update(
                    $wpdb->prefix . 'fc_subscriptions',
                    ['status' => 'cancelled', 'updated_at' => $now],
                    ['id' => $subscriptionId]
                );

                EntitlementService::flush($fcUserId);
                break;
        }
    }

    private function writePayment(int $fcUserId, int $subscriptionId, WebhookEvent $event, string $status): void
    {
        global $wpdb;

        if ($fcUserId <= 0) {
            return;
        }

        $now = gmdate('Y-m-d H:i:s');

        $wpdb->insert($wpdb->prefix . 'fc_payments', [
            'user_id'         => $fcUserId,
            'subscription_id' => $subscriptionId,
            'amount'          => null === $event->amountMinor ? 0 : round($event->amountMinor / 100, 2),
            'currency'        => $event->currency ?? FitnessClub()->options->get('billing.currency', 'USD'),
            'payment_type'    => 'subscription',
            'gateway'         => (string) ($event->raw['gateway'] ?? 'unknown'),
            'transaction_id'  => $event->transactionId,
            'status'          => $status,
            'failure_reason'  => $event->failureReason,
            'gateway_payload' => wp_json_encode($event->raw),
            'payment_date'    => $now,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    private function subscriptionFor(?string $externalId): ?int
    {
        global $wpdb;

        if (null === $externalId || '' === $externalId) {
            return null;
        }

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fc_subscriptions WHERE gateway_subscription_id = %s LIMIT 1",
            $externalId
        ));

        return null === $id ? null : (int) $id;
    }

    /**
     * Idempotency, kept in a transient rather than a table.
     *
     * A processed-events table would need its own migration and its own pruning
     * job for data nobody ever reads; a transient expires itself. Seven days is
     * comfortably longer than any gateway's retry schedule.
     */
    private function alreadyProcessed(string $gatewayName, ?string $eventId): bool
    {
        if (null === $eventId || '' === $eventId) {
            // Nothing to dedupe on. Better to risk applying twice than to drop
            // a real payment — and adapters are expected to supply one.
            return false;
        }

        return (bool) get_transient($this->dedupeKey($gatewayName, $eventId));
    }

    private function remember(string $gatewayName, ?string $eventId): void
    {
        if (null === $eventId || '' === $eventId) {
            return;
        }

        set_transient($this->dedupeKey($gatewayName, $eventId), 1, self::DEDUPE_TTL);
    }

    private function dedupeKey(string $gatewayName, string $eventId): string
    {
        // Hashed: transient keys are capped at 172 characters and a gateway's
        // event id has no length contract.
        return 'fc_wh_' . md5($gatewayName . ':' . $eventId);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'plan_name'      => $row['plan_name'] ?? null,
            'amount'         => round((float) $row['amount'], 2),
            'currency'       => $row['currency'],
            'status'         => $row['status'],
            'gateway'        => $row['gateway'],
            'payment_type'   => $row['payment_type'],
            'transaction_id' => $row['transaction_id'],
            'failure_reason' => $row['failure_reason'],
            'payment_date'   => empty($row['payment_date'])
                ? null
                : gmdate('c', (int) strtotime((string) $row['payment_date'] . ' UTC')),
        ];
    }
}
