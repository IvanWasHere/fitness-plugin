<?php

namespace FitnessClub\Providers;

use FitnessClub\Auth\Auth;
use FitnessClub\Support\DashboardCache;
use FitnessClub\Support\RateLimitException;
use FitnessClub\Support\RateLimiter;
use FitnessClub\Support\ResponseCompression;
use FitnessClub\WPBones\Support\ServiceProvider;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Cross-cutting behaviour for `/wp-json/fitnessclub/v1/*`
 * (plans/02-api-contract.md#cross-cutting).
 *
 *   - The blanket rate limit: 100 req/min authenticated, 10 req/min public.
 *     Per-endpoint buckets (login, password reset, messaging) are tighter and
 *     live in their own controllers; this is the backstop.
 *   - `Retry-After` on every 429, whichever bucket produced it.
 *   - The cache-invalidation subscription for cached aggregates, so a service
 *     announcing a change does not need to know who caches what.
 *
 * The blanket limit only engages on installs with a persistent object cache —
 * see RateLimiter for why running it on a `wp_options`-backed transient store
 * would cost more than the abuse it prevents.
 */
class ApiServiceProvider extends ServiceProvider
{
    private const NAMESPACE_PREFIX = 'fitnessclub/v1';

    public function register()
    {
        add_filter('rest_pre_dispatch', [$this, 'throttle'], 10, 3);
        add_filter('rest_post_dispatch', [$this, 'addRetryAfter'], 10, 3);

        // gzip, on `rest_pre_serve_request` so the buffer opens immediately
        // before the body is written and closes with the request. Scoped to our
        // namespace: a plugin that compresses globally double-compresses on the
        // hosts that already had it configured (W4.2).
        add_filter('rest_pre_serve_request', [$this, 'compressResponse'], 10, 4);

        // Cached responses invalidate themselves off `fitnessclub/user_data_changed`
        // rather than every writer knowing what to clear — see DashboardCache.
        DashboardCache::listen();
    }

    /**
     * Open a gzip buffer for our own routes, if nothing else is compressing.
     *
     * Returns `$served` untouched — this is a filter used as a hook, because it
     * is the last point at which the response body has not yet been written but
     * the route is known.
     *
     * @param bool             $served  Whether the request has already been served.
     * @param mixed            $result  The response.
     * @param WP_REST_Request  $request The request.
     * @param mixed            $server  WP_REST_Server.
     * @return bool
     */
    public function compressResponse($served, $result, $request, $server)
    {
        unset($result, $server);

        if (!$served && $request instanceof WP_REST_Request && $this->isOurRoute($request)) {
            ResponseCompression::maybeStart();
        }

        return $served;
    }

    /**
     * @param mixed            $result  Short-circuit value; non-null means handled.
     * @param mixed            $server  WP_REST_Server.
     * @param WP_REST_Request  $request The request.
     * @return mixed
     */
    public function throttle($result, $server, $request)
    {
        if (null !== $result || !$this->isOurRoute($request)) {
            return $result;
        }

        if (!RateLimiter::shouldThrottleEveryRequest()) {
            return $result;
        }

        $limits = (array) $this->plugin->config('fitnessclub.limits', []);

        // The plugin's account, not WordPress' user: AuthProvider has already
        // set the WordPress current user to 0 on this namespace, so
        // get_current_user_id() would put every caller in the public bucket.
        $accountId = Auth::accountId();
        $isAuthed  = $accountId > 0;

        try {
            RateLimiter::hit(
                'api',
                $isAuthed ? 'a' . $accountId : RateLimiter::ipHash(),
                (int) ($isAuthed ? $limits['api_per_minute_auth'] : $limits['api_per_minute_public']),
                MINUTE_IN_SECONDS
            );
        } catch (RateLimitException $e) {
            return $e->toWpError();
        }

        return $result;
    }

    /**
     * A 429 without `Retry-After` tells a client to back off but not for how
     * long, so every client invents its own answer. The value rides in the error
     * payload; this lifts it into the header the contract promises.
     *
     * @param mixed           $response WP_REST_Response (usually).
     * @param mixed           $server   WP_REST_Server.
     * @param WP_REST_Request $request  The request.
     * @return mixed
     */
    public function addRetryAfter($response, $server, $request)
    {
        if (!$response instanceof WP_REST_Response || 429 !== $response->get_status()) {
            return $response;
        }

        $data  = $response->get_data();
        $retry = $data['data']['retry_after'] ?? null;

        if (is_numeric($retry)) {
            $response->header('Retry-After', (string) (int) $retry);
        }

        return $response;
    }

    private function isOurRoute(WP_REST_Request $request): bool
    {
        return str_starts_with(ltrim((string) $request->get_route(), '/'), self::NAMESPACE_PREFIX);
    }
}
