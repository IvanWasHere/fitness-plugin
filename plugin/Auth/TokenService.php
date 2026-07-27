<?php

namespace FitnessClub\Auth;

use FitnessClub\Support\RateLimiter;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Single-use, expiring links: password reset, invitation, email verification.
 *
 * Same split-token scheme as SessionStore — the mailed value is
 * `{selector}.{verifier}`, the row is found by selector, and the verifier is
 * compared against a stored SHA-256.
 */
final class TokenService
{
    public const PURPOSE_RESET  = 'password_reset';
    public const PURPOSE_INVITE = 'invite';
    public const PURPOSE_VERIFY = 'email_verify';

    /**
     * Issue a token, invalidating any outstanding one for the same purpose.
     *
     * The invalidation matters: without it, a member who clicks "forgot my
     * password" three times has three live links in their inbox, and the two
     * they did not use stay valid for an hour.
     *
     * @return string The value to mail — `{selector}.{verifier}`.
     */
    public function issue(int $accountId, string $purpose, int $ttlSeconds): string
    {
        global $wpdb;

        $this->invalidateAll($accountId, $purpose);

        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));

        $wpdb->insert($wpdb->prefix . 'fc_account_tokens', [
            'account_id'        => $accountId,
            'purpose'           => $purpose,
            'selector'          => $selector,
            'token_hash'        => hash('sha256', $verifier),
            'requested_ip_hash' => RateLimiter::ipHash(),
            'expires_at'        => gmdate('Y-m-d H:i:s', time() + max(60, $ttlSeconds)),
            'created_at'        => gmdate('Y-m-d H:i:s'),
        ]);

        return $selector . '.' . $verifier;
    }

    /**
     * Redeem a token, returning the account id it belongs to, or null.
     *
     * **Single use is enforced by the UPDATE, not by a read followed by a
     * write.** The obvious version — SELECT the row, check `used_at`, then mark
     * it — loses to two concurrent redemptions of the same link, and that race
     * is not hypothetical: a mail client that prefetches links plus the human
     * clicking one is exactly two simultaneous requests. Claiming the row with
     * a conditional UPDATE and checking `rows_affected` makes exactly one of
     * them win.
     */
    public function redeem(string $token, string $purpose): ?int
    {
        global $wpdb;

        if (!str_contains($token, '.')) {
            return null;
        }

        [$selector, $verifier] = explode('.', $token, 2);

        if (32 !== strlen($selector) || '' === $verifier) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, account_id, token_hash
               FROM {$wpdb->prefix}fc_account_tokens
              WHERE selector = %s AND purpose = %s
              LIMIT 1",
            $selector,
            $purpose
        ), ARRAY_A);

        if (!$row || !hash_equals((string) $row['token_hash'], hash('sha256', $verifier))) {
            return null;
        }

        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_account_tokens
                SET used_at = UTC_TIMESTAMP()
              WHERE id = %d AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()",
            (int) $row['id']
        ));

        return 1 === $claimed ? (int) $row['account_id'] : null;
    }

    public function invalidateAll(int $accountId, string $purpose): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fc_account_tokens
                SET used_at = UTC_TIMESTAMP()
              WHERE account_id = %d AND purpose = %s AND used_at IS NULL",
            $accountId,
            $purpose
        ));
    }

    public function gc(): int
    {
        global $wpdb;

        return (int) $wpdb->query(
            "DELETE FROM {$wpdb->prefix}fc_account_tokens
              WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
        );
    }
}
