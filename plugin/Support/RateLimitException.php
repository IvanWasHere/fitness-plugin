<?php

namespace FitnessClub\Support;

use RuntimeException;
use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Thrown by RateLimiter::hit() when a bucket is exhausted.
 *
 * Carries everything the 429 response needs — the contract requires `limit` and
 * `resets_at` in `data`, plus a `Retry-After` header (plans/02-api-contract.md).
 */
final class RateLimitException extends RuntimeException
{
    public function __construct(
        private readonly string $bucket,
        private readonly int $limit,
        private readonly int $resetsAt
    ) {
        parent::__construct(
            sprintf(
                /* translators: %s: rate-limit bucket name, e.g. "login". */
                __('Too many requests (%s). Please wait and try again.', 'fitnessclub'),
                $bucket
            )
        );
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * Seconds until the window rolls over. Never negative, never zero — a
     * `Retry-After: 0` invites an immediate retry that fails again.
     */
    public function retryAfter(): int
    {
        return max(1, $this->resetsAt - time());
    }

    public function toWpError(): WP_Error
    {
        return new WP_Error('fc_rate_limited', $this->getMessage(), [
            'status'      => 429,
            'limit'       => $this->limit,
            'resets_at'   => gmdate('c', $this->resetsAt),
            'retry_after' => $this->retryAfter(),
        ]);
    }
}
