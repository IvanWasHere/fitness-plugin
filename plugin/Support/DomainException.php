<?php

namespace FitnessClub\Support;

use RuntimeException;
use WP_Error;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * A refused domain operation, carrying the exact REST answer with it.
 *
 * Services throw this instead of returning `WP_Error`, so a state machine can
 * bail out of the middle of a transaction without every caller having to unwrap
 * a union return type. Controllers catch it once and hand `toWpError()` back.
 *
 * Codes are the stable, namespaced `fc_*` strings from the API contract — the
 * client branches on those, never on the message, which is translated.
 */
final class DomainException extends RuntimeException
{
    /**
     * @param array<string,mixed> $data Extra payload for `data`, e.g. the id of
     *                                  the session that blocks a new one.
     */
    public function __construct(
        // Not `$code`: Exception already owns that property, as an int.
        private readonly string $errorCode,
        string $message,
        private readonly int $status = 400,
        private readonly array $data = []
    ) {
        parent::__construct($message);
    }

    public function code(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function toWpError(): WP_Error
    {
        return new WP_Error($this->errorCode, $this->getMessage(), ['status' => $this->status] + $this->data);
    }
}
