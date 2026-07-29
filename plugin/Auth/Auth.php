<?php

namespace FitnessClub\Auth;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * "Who is calling?" — the one answer the rest of the plugin asks for.
 *
 * ## Resolved lazily, not on a hook
 *
 * Both consumers are already downstream of `init`: REST permission callbacks
 * run inside `rest_api_loaded()`, and `template_redirect` fires inside `wp()`.
 * So there is nothing to bootstrap early, and resolving on first ask means the
 * query runs at most once per request and only on requests that need it.
 *
 * Hooking it would also have to fight the framework: wpBones instantiates
 * service providers on `init` priority 10, so an `add_action('init', …, 1)`
 * written inside a provider is dead code that never fires.
 *
 * ## The bridge that must never be built
 *
 * It is tempting to filter `determine_current_user` with an account id so that
 * `current_user_can()` keeps working. **Never do this.** `fc_accounts.id = 7`
 * and `wp_users.ID = 7` are unrelated rows; returning one for the other hands
 * the caller whatever capabilities that WordPress user happens to have, up to
 * and including an administrator's. The two id spaces stay separate, and the
 * surfaces this plugin owns call `wp_set_current_user(0)` so that any call site
 * missed during the conversion fails closed instead of silently honouring a
 * wp-admin session.
 */
final class Auth
{
    private static ?Account $account = null;

    /** @var array<string,mixed>|null */
    private static ?array $session = null;

    private static bool $resolved = false;

    private static ?AccountRepository $accounts = null;

    private static ?SessionStore $sessions = null;

    /**
     * The signed-in account, or null.
     */
    public static function account(): ?Account
    {
        if (self::$resolved) {
            return self::$account;
        }

        self::$resolved = true;

        // Cookie first. A browser that has one gains nothing from a bearer
        // token, and checking the cookie first means the SPA path never pays for
        // the API path's existence.
        $session = self::sessions()->resolve(SessionCookie::read());

        if (null === $session) {
            $session = self::bearerSession();
        }

        if (null === $session) {
            return null;
        }

        $account = self::accounts()->find((int) $session['account_id']);

        // A suspended or deleted account keeps its cookie until it expires;
        // refusing here is what makes "switch this person off" take effect now
        // rather than in twelve hours.
        if (null === $account || !$account->isActive()) {
            return null;
        }

        self::$session = $session;
        self::$account = $account;

        // Only a real session row slides its idle window. A bearer caller's
        // grant is touched when it refreshes, not on every API call.
        if (SessionStore::KIND_COOKIE === ($session['kind'] ?? SessionStore::KIND_COOKIE)) {
            self::sessions()->touch($session);
        }

        return self::$account;
    }

    /**
     * Resolve an `Authorization: Bearer` access token (D4, W4.2).
     *
     * Two checks, and the second is the one that matters. The JWT is verified
     * cryptographically — signature, expiry, issuer, audience, type — and then
     * **the grant behind it is checked against the database**. A JWT cannot be
     * revoked, so without that second step signing out would leave the token
     * working for up to fifteen more minutes, and a password reset prompted by
     * "somebody else is in my account" would not remove them.
     *
     * The shape returned matches a session row so that everything downstream —
     * `session()`, the CSRF gate, the controllers — needs no idea which door the
     * caller came through.
     *
     * @return array<string,mixed>|null
     */
    private static function bearerSession(): ?array
    {
        $token = BearerToken::fromRequest();

        if (null === $token) {
            return null;
        }

        $claims = Jwt::verifyAccessToken($token);

        if (null === $claims) {
            return null;
        }

        if (!self::sessions()->grantIsLive($claims['session_id'])) {
            return null;
        }

        return [
            'id'         => $claims['session_id'],
            'account_id' => $claims['account_id'],
            'kind'       => SessionStore::KIND_REFRESH,
            // No CSRF binding: see Csrf, which exempts bearer callers because a
            // token that is not sent ambiently cannot be forged cross-site.
            'csrf_hash'  => '',
            'remember'   => 1,
        ];
    }

    /** Did this request authenticate with a bearer token rather than a cookie? */
    public static function isBearer(): bool
    {
        $session = self::session();

        return null !== $session
            && SessionStore::KIND_REFRESH === ($session['kind'] ?? SessionStore::KIND_COOKIE);
    }

    public static function accountId(): int
    {
        return self::account()?->id ?? 0;
    }

    public static function check(): bool
    {
        return null !== self::account();
    }

    /**
     * The current session row, or null. Used by the CSRF gate, which needs the
     * bound hash.
     *
     * @return array<string,mixed>|null
     */
    public static function session(): ?array
    {
        self::account();

        return self::$session;
    }

    /**
     * Start a session for an account and write the cookies.
     *
     * @return string The CSRF token, for the boot payload.
     */
    public static function login(Account $account, bool $remember = false): string
    {
        $issued = self::sessions()->issue($account->id, $remember);

        SessionCookie::write($issued['cookie'], $issued['expires']);
        SessionCookie::writeCsrf($issued['csrf'], $issued['expires']);

        self::accounts()->recordSuccessfulLogin($account->id);

        self::$account  = $account;
        self::$session  = self::sessions()->resolve($issued['cookie']);
        self::$resolved = true;

        return $issued['csrf'];
    }

    /**
     * End the current session and clear the cookies.
     */
    public static function logout(): void
    {
        $session = self::session();

        if (null !== $session) {
            self::sessions()->revoke((int) $session['id']);
        }

        SessionCookie::clear();
        self::forget();
    }

    /**
     * Drop the memoized identity — for tests, and for the CLI, which may act as
     * several accounts in one process.
     */
    public static function forget(): void
    {
        self::$account  = null;
        self::$session  = null;
        self::$resolved = false;
    }

    public static function accounts(): AccountRepository
    {
        return self::$accounts ??= new AccountRepository();
    }

    public static function sessions(): SessionStore
    {
        return self::$sessions ??= new SessionStore();
    }
}
