<?php

namespace FitnessClub\Payments;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * What the caller asks a gateway to charge for (W3.2).
 *
 * **The amount is resolved by the server, never sent by the client.** The
 * checkout endpoint accepts a `plan_id` and a cycle and looks the price up;
 * `plans/02-api-contract.md` calls a client-supplied price "the single most
 * commonly exploited endpoint in subscription plugins", and this object is where
 * that rule is made structural — there is no constructor path that takes a
 * price without a plan to have derived it from.
 *
 * Money travels as **minor units** (D8): 1999, not 19.99. Floats are stored as
 * `DECIMAL` in the database and converted at this boundary, because a gateway
 * that receives 19.990000000000002 rejects it and a gateway that receives
 * 1999 cannot round.
 */
final class SubscriptionRequest
{
    /**
     * @param string $cycle One of weekly|monthly|quarterly|yearly.
     * @param array<string,mixed> $metadata Echoed back by the gateway on webhooks.
     */
    public function __construct(
        public readonly int $fcUserId,
        public readonly int $planId,
        public readonly string $planName,
        public readonly string $cycle,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?int $trainerId = null,
        public readonly ?string $customerEmail = null,
        public readonly ?string $returnUrl = null,
        public readonly array $metadata = []
    ) {
    }

    /** The decimal the database stores, from the minor units the gateway wants. */
    public function amountDecimal(): float
    {
        return round($this->amountMinor / 100, 2);
    }
}
