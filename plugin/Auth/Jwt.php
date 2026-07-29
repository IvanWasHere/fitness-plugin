<?php

namespace FitnessClub\Auth;

use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;
use FitnessClub\Support\DomainException;
use Throwable;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Access tokens for external clients — mobile apps and third-party front ends
 * (D4, W4.2).
 *
 * ## Only for callers that are not a browser
 *
 * The SPAs use the session cookie and never see one of these. A browser that
 * already holds a cookie gains nothing from a bearer token and loses the
 * protection of `HttpOnly` — a token readable by JavaScript is a token any XSS
 * can exfiltrate, and unlike a cookie it cannot be revoked by clearing one. JWT
 * exists here for callers that genuinely have nowhere to put a cookie.
 *
 * ## Access tokens are short and refresh tokens are opaque
 *
 * A JWT cannot be revoked — that is the entire trade. So the JWT half is
 * deliberately the *short* half: fifteen minutes, stateless, verified with no
 * database read. Longevity lives in the refresh token, which is **not** a JWT at
 * all but an opaque split token in `fc_sessions`, so it can be revoked, and is —
 * on sign-out, on password change, and by "sign out everywhere".
 *
 * Issuing a 24-hour access token with no revocation, as `plan.md` §14.1 proposed,
 * is a 24-hour window after a theft during which nothing can be done.
 *
 * ## The algorithm is pinned, and that is the whole vulnerability
 *
 * `decode()` is given exactly one algorithm. The canonical JWT attack is to hand
 * the verifier a token whose header says `alg: none`, or `HS256` where the server
 * expects `RS256` so the public key gets used as an HMAC secret. A verifier that
 * reads the algorithm out of the token it is checking is trusting the attacker to
 * say how to check the attacker's token.
 *
 * ## The secret is a constant, not an option
 *
 * `FITNESSCLUB_JWT_SECRET` in `wp-config.php`. An options row lives in a database
 * that gets dumped, shared with a contractor and restored into staging; a
 * compromised database read should not also mint valid tokens. There is no
 * auto-generated fallback: without the constant the feature is **off**, because
 * the alternative is a plugin that silently starts signing with a secret sitting
 * in the table next to the data it protects.
 */
final class Jwt
{
    /** The one permitted algorithm. Never read from the token being verified. */
    public const ALGORITHM = 'HS256';

    public const ISSUER = 'fitnessclub';

    public const AUDIENCE = 'fitnessclub-api';

    /** Type claim, so a refresh token can never be replayed as an access token. */
    public const TYPE_ACCESS = 'access';

    /** Deliberately short: a JWT cannot be revoked, so it must expire instead. */
    public const ACCESS_TTL_SECONDS = 900;

    /**
     * Is the API enabled *and* usable?
     *
     * Both halves matter. The setting is the administrator's intent; the constant
     * is whether it can be honoured. A site that switches the feature on without
     * defining the secret gets a clear refusal rather than tokens signed with
     * something guessable.
     */
    public static function isAvailable(): bool
    {
        return self::isEnabled() && null !== self::secret();
    }

    public static function isEnabled(): bool
    {
        return (bool) FitnessClub()->options->get('features.jwt_api_enabled', false);
    }

    public static function hasSecret(): bool
    {
        return null !== self::secret();
    }

    /**
     * Mint an access token for an account.
     *
     * `jti` is included so a future denylist has something to key on, and because
     * two tokens minted in the same second would otherwise be byte-identical —
     * which makes them indistinguishable in a log.
     *
     * @param int $sessionId The refresh token's row, so a token can be traced
     *                       back to the grant it came from.
     */
    public static function issueAccessToken(int $accountId, int $sessionId): string
    {
        $secret = self::secret();

        if (null === $secret) {
            throw new DomainException(
                'fc_jwt_unavailable',
                __('The API is not configured for token authentication.', 'fitnessclub'),
                503
            );
        }

        $now = time();

        return FirebaseJwt::encode(
            [
                'iss' => self::ISSUER,
                'aud' => self::AUDIENCE,
                'sub' => (string) $accountId,
                'sid' => $sessionId,
                'typ' => self::TYPE_ACCESS,
                'iat' => $now,
                'nbf' => $now,
                'exp' => $now + self::ACCESS_TTL_SECONDS,
                'jti' => bin2hex(random_bytes(16)),
            ],
            $secret,
            self::ALGORITHM
        );
    }

    /**
     * Verify a token and return its claims, or null.
     *
     * **Never throws.** This runs inside identity resolution, on every request
     * carrying an `Authorization` header including malformed and hostile ones. A
     * bad token is an anonymous caller, not a 500 — and an exception escaping
     * here would turn "someone sent us garbage" into an outage.
     *
     * @return array{account_id:int,session_id:int}|null
     */
    public static function verifyAccessToken(string $token): ?array
    {
        $secret = self::secret();

        if (null === $secret || '' === $token || !self::isEnabled()) {
            return null;
        }

        try {
            // One algorithm. See the class docblock — this argument is the
            // difference between a verifier and a formality.
            $claims = (array) FirebaseJwt::decode($token, new Key($secret, self::ALGORITHM));
        } catch (Throwable $e) {
            // Covers expiry, bad signature, malformed input and a `nbf` in the
            // future. The client cannot act differently on any of them: the
            // answer is always "get a new token".
            unset($e);

            return null;
        }

        // The library checks the signature, `exp` and `nbf`. It does *not* check
        // that the token was meant for us, or that it is the right kind of token,
        // so those are checked here — a refresh token replayed as an access token
        // would otherwise sail through.
        if (
            (self::ISSUER !== ($claims['iss'] ?? null))
            || (self::AUDIENCE !== ($claims['aud'] ?? null))
            || (self::TYPE_ACCESS !== ($claims['typ'] ?? null))
        ) {
            return null;
        }

        $accountId = (int) ($claims['sub'] ?? 0);

        if ($accountId <= 0) {
            return null;
        }

        return [
            'account_id' => $accountId,
            'session_id' => (int) ($claims['sid'] ?? 0),
        ];
    }

    /**
     * The signing secret, or null when the site has not configured one.
     *
     * A short secret is treated as absent rather than used. HS256 with a secret
     * shorter than the hash it produces is weaker than it looks, and "the admin
     * typed `changeme`" should not be a working configuration.
     */
    private static function secret(): ?string
    {
        if (!defined('FITNESSCLUB_JWT_SECRET')) {
            return null;
        }

        $secret = (string) constant('FITNESSCLUB_JWT_SECRET');

        return strlen($secret) >= 32 ? $secret : null;
    }
}
