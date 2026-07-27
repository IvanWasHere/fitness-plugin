<?php

namespace FitnessClub\Payments;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * A gateway notification, normalised (W3.2).
 *
 * Adapters translate their own vocabulary into these few types, so
 * `PaymentService` never learns what a `invoice.payment_succeeded` is. Anything
 * an adapter does not recognise becomes `IGNORED` — a webhook endpoint that
 * throws on an unknown event type is one that breaks every time the gateway adds
 * one, and gateways add them constantly.
 *
 * `externalEventId` is the **idempotency key**. Gateways retry on any non-2xx
 * and sometimes deliver twice on a 2xx; without a stable id per event, a retried
 * `payment_succeeded` extends a subscription twice.
 */
final class WebhookEvent
{
    public const PAYMENT_SUCCEEDED     = 'payment_succeeded';
    public const PAYMENT_FAILED        = 'payment_failed';
    public const SUBSCRIPTION_CANCELLED = 'subscription_cancelled';
    public const SUBSCRIPTION_RENEWED  = 'subscription_renewed';
    public const IGNORED               = 'ignored';
    public const INVALID               = 'invalid';

    /**
     * @param array<string,mixed> $raw
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $externalEventId = null,
        public readonly ?string $externalSubscriptionId = null,
        public readonly ?string $transactionId = null,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?string $failureReason = null,
        public readonly array $raw = []
    ) {
    }

    /**
     * Signature verification failed, or the body was unreadable.
     *
     * Distinct from IGNORED: an invalid event is answered **401**, so the gateway
     * surfaces it in its own dashboard, whereas an ignored one is answered 200
     * so the gateway stops retrying something we will never act on.
     */
    public static function invalid(string $reason): self
    {
        return new self(self::INVALID, null, null, null, null, null, $reason);
    }

    public static function ignored(?string $externalEventId = null): self
    {
        return new self(self::IGNORED, $externalEventId);
    }

    public function isActionable(): bool
    {
        return !in_array($this->type, [self::IGNORED, self::INVALID], true);
    }
}
