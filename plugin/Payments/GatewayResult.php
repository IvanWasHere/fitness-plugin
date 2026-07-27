<?php

namespace FitnessClub\Payments;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * What a gateway says happened (W3.2).
 *
 * Three shapes, and the caller has to tell them apart:
 *
 *   - **redirect** — the member must go somewhere to pay (hosted checkout). The
 *     subscription is not active yet; the webhook will say when it is.
 *   - **settled** — the subscription is active now (manual entry, or a gateway
 *     that charges synchronously).
 *   - **failed** — with a reason worth showing.
 *
 * A boolean `success` would collapse the first two, and the difference between
 * them is exactly whether the caller may mark the subscription active. Making it
 * a status the caller must branch on is the point.
 */
final class GatewayResult
{
    public const REDIRECT = 'redirect';
    public const SETTLED  = 'settled';
    public const FAILED   = 'failed';

    /**
     * @param array<string,mixed> $raw The gateway's own payload, stored for support.
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $externalSubscriptionId = null,
        public readonly ?string $externalCustomerId = null,
        public readonly ?string $transactionId = null,
        public readonly ?string $failureReason = null,
        public readonly array $raw = []
    ) {
    }

    /**
     * @param array<string,mixed> $raw
     */
    public static function redirect(string $url, ?string $externalSubscriptionId = null, array $raw = []): self
    {
        return new self(self::REDIRECT, $url, $externalSubscriptionId, null, null, null, $raw);
    }

    /**
     * @param array<string,mixed> $raw
     */
    public static function settled(
        string $transactionId,
        ?string $externalSubscriptionId = null,
        ?string $externalCustomerId = null,
        array $raw = []
    ): self {
        return new self(
            self::SETTLED,
            null,
            $externalSubscriptionId,
            $externalCustomerId,
            $transactionId,
            null,
            $raw
        );
    }

    /**
     * @param array<string,mixed> $raw
     */
    public static function failed(string $reason, array $raw = []): self
    {
        return new self(self::FAILED, null, null, null, null, $reason, $raw);
    }

    public function isSettled(): bool
    {
        return self::SETTLED === $this->status;
    }

    public function isRedirect(): bool
    {
        return self::REDIRECT === $this->status;
    }

    public function isFailed(): bool
    {
        return self::FAILED === $this->status;
    }
}
