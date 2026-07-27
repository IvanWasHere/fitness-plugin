<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Payments\GatewayResult;
use FitnessClub\Payments\PaymentGateway;
use FitnessClub\Payments\SubscriptionRequest;
use FitnessClub\Payments\WebhookEvent;
use WP_REST_Request;

/**
 * A processor that exists only in this test suite (W3.2).
 *
 * The whole argument for the adapter seam is that everything above it can be
 * exercised without a real processor. This is that claim being cashed: it makes
 * the webhook path — verify, dedupe, apply, renew — testable with no network, no
 * API keys and no Stripe account.
 *
 * Its "signature" is the literal string `good`. That is not a shortcut around
 * the security property being tested: the property is *that the adapter verifies
 * before anything parses the body, and that the caller answers 401 when it
 * fails*, and a trivial predicate exercises both branches exactly as a real HMAC
 * would.
 */
final class FakeGateway implements PaymentGateway
{
    public function __construct(private readonly string $externalSubscriptionId)
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createSubscription(SubscriptionRequest $request): GatewayResult
    {
        return GatewayResult::settled(
            'txn_' . wp_generate_password(8, false),
            $this->externalSubscriptionId,
            'cus_fake',
            ['gateway' => 'fake', 'amount' => $request->amountMinor]
        );
    }

    public function cancelSubscription(string $externalId, bool $atPeriodEnd = true): GatewayResult
    {
        return GatewayResult::settled($externalId, $externalId, null, ['gateway' => 'fake']);
    }

    public function handleWebhook(WP_REST_Request $request): WebhookEvent
    {
        $body = (array) $request->get_json_params();

        // Verification first, before anything reads the payload as meaningful.
        if ('good' !== ($body['signature'] ?? null)) {
            return WebhookEvent::invalid('Bad signature.');
        }

        $eventId = (string) ($body['event_id'] ?? '');
        $type    = (string) ($body['type'] ?? 'payment_succeeded');

        if ('payment_succeeded' !== $type) {
            return WebhookEvent::ignored($eventId);
        }

        return new WebhookEvent(
            WebhookEvent::PAYMENT_SUCCEEDED,
            $eventId,
            $this->externalSubscriptionId,
            'txn_' . $eventId,
            1200,
            'USD',
            null,
            ['gateway' => 'fake']
        );
    }
}
