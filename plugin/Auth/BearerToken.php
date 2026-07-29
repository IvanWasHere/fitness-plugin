<?php

namespace FitnessClub\Auth;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Pull an `Authorization: Bearer <token>` value off the request (W4.2).
 *
 * ## Apache eats this header, and that is the whole reason this class exists
 *
 * `Authorization` does not reliably reach PHP. Under mod_php and CGI, Apache
 * strips it unless `CGIPassAuth` is on or a rewrite rule copies it, and what
 * arrives instead is `REDIRECT_HTTP_AUTHORIZATION` — or nothing at all. A reader
 * that checks only `HTTP_AUTHORIZATION` works perfectly in development and fails
 * on a customer's shared host with "the token is wrong", which is the least
 * informative possible symptom.
 *
 * So every place the header is known to surface is checked, in order of how
 * trustworthy it is.
 */
final class BearerToken
{
    /**
     * Server keys that carry the header, best first.
     *
     * `getallheaders()` is tried before any of them, because on the setups where
     * Apache rewrites the header it is the only source that has the original.
     */
    private const SERVER_KEYS = [
        'HTTP_AUTHORIZATION',
        'REDIRECT_HTTP_AUTHORIZATION',
        // Some FastCGI configurations expose it un-prefixed.
        'Authorization',
    ];

    /**
     * The token, or null when the request carries no usable bearer credential.
     */
    public static function fromRequest(): ?string
    {
        $header = self::header();

        if (null === $header) {
            return null;
        }

        // Case-insensitive scheme: RFC 7235 says the scheme token is
        // case-insensitive, and clients do send "bearer".
        if (!preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private static function header(): ?string
    {
        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (0 === strcasecmp((string) $name, 'Authorization') && '' !== (string) $value) {
                    return (string) $value;
                }
            }
        }

        foreach (self::SERVER_KEYS as $key) {
            if (isset($_SERVER[$key]) && '' !== $_SERVER[$key]) {
                // Not sanitize_text_field, and the sniff is silenced knowingly:
                // this is a credential compared byte-for-byte, and a sanitiser
                // that strips a character turns a valid token into an invalid
                // one — a bug that would present as "authentication randomly
                // fails for some users". It is never echoed, never reaches SQL,
                // and the `Bearer <token>` regex above is what constrains its
                // shape before anything uses it.
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                return trim((string) wp_unslash($_SERVER[$key]));
            }
        }

        return null;
    }
}
