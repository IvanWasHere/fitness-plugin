<?php

namespace FitnessClub\Payments;

use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Payments an administrator records by hand (D6, W3.2).
 *
 * Needed regardless of which card processor is wired up: the admin prototype's
 * Billing table is manual CRUD, and real gyms take bank transfers, cash and
 * comped memberships. It is also the gateway an install runs on before anyone
 * has configured a processor, which is why it is the default in
 * `config/options.php`.
 *
 * It settles **synchronously** — there is nowhere to redirect a member to, and
 * nothing arrives later by webhook. That makes it the adapter that exercises the
 * `SETTLED` branch of `GatewayResult` end to end, so the branch is not dead code
 * waiting for a processor to be configured.
 */
final class ManualGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    /** Always. It needs no keys, which is the point of it being the default. */
    public function isConfigured(): bool
    {
        return true;
    }

    public function createSubscription(SubscriptionRequest $request): GatewayResult
    {
        // A local, unique-per-attempt reference. `transaction_id` is what the
        // webhook path dedupes on, and a manual payment has no external id to
        // borrow — so one is minted here rather than left null, which would make
        // two comped memberships indistinguishable in the payments table.
        $reference = sprintf(
            'manual_%d_%d_%s',
            $request->fcUserId,
            $request->planId,
            wp_generate_password(10, false)
        );

        return GatewayResult::settled($reference, $reference, null, [
            'gateway'   => 'manual',
            'recorded'  => gmdate('c'),
            'amount'    => $request->amountMinor,
            'currency'  => $request->currency,
            'cycle'     => $request->cycle,
        ]);
    }

    public function cancelSubscription(string $externalId, bool $atPeriodEnd = true): GatewayResult
    {
        // Nothing to tell anybody: the "gateway" is a row in this database. The
        // caller still updates the subscription, so returning settled keeps the
        // cancel path identical across adapters.
        return GatewayResult::settled($externalId, $externalId, null, [
            'gateway'       => 'manual',
            'cancelled'     => gmdate('c'),
            'at_period_end' => $atPeriodEnd,
        ]);
    }

    /**
     * There is no such thing as a manual webhook.
     *
     * Answered as IGNORED rather than INVALID: a POST to
     * `/billing/webhook/manual` is a misconfiguration, not an attack, and a 200
     * stops whatever is sending it from retrying forever.
     */
    public function handleWebhook(WP_REST_Request $request): WebhookEvent
    {
        unset($request);

        return WebhookEvent::ignored();
    }
}
