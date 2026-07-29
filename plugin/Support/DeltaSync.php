<?php

namespace FitnessClub\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `?modified_since=` — fetch only what changed (W4.2).
 *
 * A phone on a train should not re-download six months of workout history to
 * discover that nothing changed. A list endpoint that accepts `modified_since`
 * returns only rows whose `updated_at` is newer, which turns a periodic sync from
 * "the whole table" into "usually nothing".
 *
 * ## The server supplies the next watermark, and this is the whole design
 *
 * Every response carries `X-FC-Sync-Timestamp`, and the client is expected to
 * send **that value back** next time rather than its own idea of "now".
 *
 * Using the client's clock is the classic delta-sync bug and it is silent: the
 * phone's clock may be minutes off, and even a perfect clock loses rows written
 * *during* the request it just made. Both produce the same symptom — a record
 * that exists on the server and never arrives, permanently, because every
 * subsequent sync asks for changes after a moment that already passed. Handing
 * the client a server-generated watermark removes the clock from the problem
 * entirely.
 *
 * The watermark is taken **before** the query runs, not after, so anything
 * written while the query was executing is picked up next time rather than
 * skipped. Re-sending a handful of rows is free; missing one is a bug nobody can
 * reproduce.
 *
 * ## Deletions are not covered, and callers should know that
 *
 * A delta feed built on `updated_at` can say what changed but not what *went
 * away* — a deleted row simply stops being returned, which is indistinguishable
 * from "unchanged" to a client that only ever asks for the difference. Handling
 * that needs tombstones, which is a schema change and its own package. Stated
 * here and in the API document rather than left for somebody to discover when
 * their local copy keeps a meal the member deleted.
 */
final class DeltaSync
{
    /** The response header carrying the watermark for the next request. */
    public const HEADER = 'X-FC-Sync-Timestamp';

    /** The query parameter clients send. */
    public const PARAM = 'modified_since';

    /**
     * The route arg every syncable list endpoint mixes in.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function arg(): array
    {
        return [
            self::PARAM => [
                'type'        => 'string',
                'format'      => 'date-time',
                'description' => 'ISO 8601 timestamp. Returns only records changed since then. '
                    . 'Send back the X-FC-Sync-Timestamp header from your previous response rather '
                    . 'than a locally generated time.',
            ],
        ];
    }

    /**
     * Normalise a client-supplied timestamp to a UTC SQL datetime, or null.
     *
     * Anything unparseable is **ignored rather than refused**. The alternative —
     * a 400 — turns a client bug into a total sync failure, when falling back to
     * a full fetch is correct, self-healing and merely slower. A future timestamp
     * is likewise clamped to now: honouring it would return nothing, forever.
     */
    public static function since(?string $value): ?string
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        try {
            $parsed = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            unset($e);

            return null;
        }

        $timestamp = min($parsed->getTimestamp(), time());

        return gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * The watermark to hand back. Call this **before** running the query.
     */
    public static function watermark(): string
    {
        return gmdate('c');
    }

    /**
     * Attach the watermark to a response.
     *
     * Set on every list response, not only on delta ones — a client's *first*
     * sync is a full fetch, and that is precisely when it needs a starting
     * watermark. Withholding it there would force the client to invent one,
     * which is the failure this class exists to prevent.
     */
    public static function stamp(WP_REST_Response $response, string $watermark): WP_REST_Response
    {
        $response->header(self::HEADER, $watermark);

        return $response;
    }
}
