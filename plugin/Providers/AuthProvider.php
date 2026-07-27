<?php

namespace FitnessClub\Providers;

use FitnessClub\Auth\Auth;
use FitnessClub\Auth\Csrf;
use FitnessClub\Auth\SessionStore;
use FitnessClub\Auth\TokenService;
use FitnessClub\WPBones\Support\ServiceProvider;
use WP_Error;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Cross-cutting behaviour for the plugin's own identity.
 *
 * Replaces the old AuthServiceProvider, whose entire job was working around
 * WordPress' nonce lifecycle — `bridgeLoggedInCookie()` existed because
 * `wp_create_nonce()` reads the session token out of `$_COOKIE` and
 * `wp_set_auth_cookie()` never populates it. The plugin's CSRF token is minted
 * in memory in the same request as the session row, so there is no round trip to
 * bridge and none of that code has a reason to exist.
 *
 * Three responsibilities:
 *
 *   - **The CSRF gate**, once, over the whole namespace.
 *   - **Failing closed**: `wp_set_current_user(0)` on our REST namespace, so any
 *     call site that still asks WordPress "who is this?" gets "nobody" rather
 *     than silently honouring a wp-admin session.
 *   - **Garbage collection** of expired sessions and tokens.
 */
class AuthProvider extends ServiceProvider
{
    public const GC_HOOK = 'fitnessclub_gc_auth';

    private const NAMESPACE_PREFIX = 'fitnessclub/v1';

    public function register()
    {
        // Priority 5: before ApiServiceProvider's throttle (10), so a forged
        // request is refused before it can spend somebody else's rate limit.
        add_filter('rest_pre_dispatch', [$this, 'guard'], 5, 3);

        add_action(self::GC_HOOK, [$this, 'collectGarbage']);

        if (!wp_next_scheduled(self::GC_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::GC_HOOK);
        }
    }

    /**
     * Detach WordPress' identity from our routes, then check CSRF.
     *
     * @param mixed           $result  Short-circuit value; non-null means handled.
     * @param mixed           $server  WP_REST_Server.
     * @param WP_REST_Request $request The request.
     * @return mixed
     */
    public function guard($result, $server, $request)
    {
        if (null !== $result || !$this->isOurRoute($request)) {
            return $result;
        }

        // Whoever WordPress thinks is calling, they are not calling *this*. An
        // administrator with a wp-admin cookie and no plugin account must be
        // anonymous here, and any capability check missed during the conversion
        // has to fail rather than pass.
        wp_set_current_user(0);

        if (Csrf::isSafeMethod((string) $request->get_method())) {
            return $result;
        }

        $session = Auth::session();
        $sent    = Csrf::fromRequest($request);

        if (null === $sent) {
            return new WP_Error(
                'fc_csrf_missing',
                __('This request is missing its security token.', 'fitnessclub'),
                ['status' => 403]
            );
        }

        if (!Csrf::verify($sent, $session['csrf_hash'] ?? null)) {
            return new WP_Error(
                'fc_csrf_mismatch',
                __('This request could not be verified. Reload and try again.', 'fitnessclub'),
                ['status' => 403]
            );
        }

        return $result;
    }

    /**
     * Delete sessions and tokens a week past expiry. The week of slack keeps
     * "was I signed out?" and "was that link already used?" answerable.
     */
    public function collectGarbage(): void
    {
        $sessions = (new SessionStore())->gc();
        $tokens   = (new TokenService())->gc();

        if ($sessions > 0 || $tokens > 0) {
            $this->plugin->log()->info(
                "Auth GC removed {$sessions} session(s) and {$tokens} token(s)."
            );
        }
    }

    /**
     * Clear the schedule on deactivation.
     */
    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::GC_HOOK);

        while (false !== $timestamp) {
            wp_unschedule_event($timestamp, self::GC_HOOK);
            $timestamp = wp_next_scheduled(self::GC_HOOK);
        }
    }

    private function isOurRoute(WP_REST_Request $request): bool
    {
        return str_starts_with(ltrim((string) $request->get_route(), '/'), self::NAMESPACE_PREFIX);
    }
}
