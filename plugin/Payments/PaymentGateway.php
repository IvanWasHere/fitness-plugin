<?php

namespace FitnessClub\Payments;

use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * The payment adapter seam (plans/00-architecture.md#d6, W3.2).
 *
 * D6's point is that the *seam* is the deliverable, not any one gateway: PayPal
 * later becomes another adapter rather than a rewrite. Everything above this
 * interface — `SubscriptionService`, the webhook route, the admin screens —
 * talks only to these five methods and to the value objects beside them.
 *
 * **No card data ever reaches this server.** An adapter returns a URL to hand
 * the browser (Stripe Checkout / Payment Element) or, for `ManualGateway`,
 * nothing at all. Nothing here accepts a PAN, a CVC or an expiry, which is what
 * keeps PCI scope at SAQ-A.
 *
 * **Standard gateway accounts, not marketplace ones** (Q2): the platform
 * collects all revenue and trainers are paid outside the system. There is no
 * connected-account id in any of these signatures, and that absence is the
 * decision — it is what keeps onboarding, KYC and transfers out of the product.
 */
interface PaymentGateway
{
    /** Stable machine name, matching `billing.gateway` in the options model. */
    public function name(): string;

    /**
     * Does this adapter have what it needs to run?
     *
     * Answered from configuration, never from a live call: the admin screens ask
     * this to explain *why* checkout is unavailable, and doing that with a
     * network round trip would make an unconfigured install slow as well as
     * broken.
     */
    public function isConfigured(): bool;

    /**
     * Begin a subscription.
     *
     * Returns where to send the member — for a hosted checkout, a redirect URL;
     * for a gateway that settles immediately (manual), a result carrying the
     * external ids and no URL at all.
     */
    public function createSubscription(SubscriptionRequest $request): GatewayResult;

    /**
     * Stop a subscription at the gateway.
     *
     * `$atPeriodEnd` is the difference between "you keep what you paid for" and
     * "your access stops now". The product only ever offers the former; the
     * parameter exists because an administrator issuing a refund needs the
     * latter.
     */
    public function cancelSubscription(string $externalId, bool $atPeriodEnd = true): GatewayResult;

    /**
     * Turn a webhook request into a domain event, or reject it.
     *
     * **Signature verification belongs here**, before the body is parsed — the
     * adapter is the only thing that knows how its gateway signs. An
     * implementation that parses first and verifies second is verifying
     * attacker-controlled JSON.
     */
    public function handleWebhook(WP_REST_Request $request): WebhookEvent;
}
