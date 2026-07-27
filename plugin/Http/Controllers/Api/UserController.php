<?php

namespace FitnessClub\Http\Controllers\Api;

use FitnessClub\Services\DashboardService;
use FitnessClub\Support\DashboardCache;
use FitnessClub\WPBones\Routing\API\RestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `/user/*` — the signed-in member's own record.
 *
 * Only the dashboard aggregate lives here for now; profile, preferences and the
 * trainer list join it in Phase 2 and Phase 3.
 */
final class UserController extends MemberController
{
    private DashboardService $dashboard;

    public function __construct($request, $vendor)
    {
        parent::__construct($request, $vendor);

        $this->dashboard = new DashboardService();
    }

    /**
     * `GET /user/dashboard` — the whole screen in one response.
     *
     * Served from the per-member cache unless `refresh=1`, which the client
     * sends after a write it wants reflected immediately rather than in up to a
     * minute. The cache is also dropped by the `fitnessclub/user_data_changed`
     * action; `refresh` is the belt to that braces, and it costs one query set.
     */
    public function dashboard(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request): WP_REST_Response {
            $refresh = (bool) $request->get_param('refresh');
            $cached  = $refresh ? null : DashboardCache::get($fcUserId);

            if (null !== $cached) {
                $response = $this->response($cached);
                $response->header('X-FC-Cache', 'hit');

                return $response;
            }

            $payload = $this->dashboard->forUser($accountId, $fcUserId);
            DashboardCache::put($fcUserId, $payload);

            $response = $this->response($payload);
            $response->header('X-FC-Cache', 'miss');

            return $response;
        });
    }

    /**
     * Reading your own dashboard needs nothing but a member session — the
     * aggregate resolves everything from the caller's own id, so there is no
     * object to own or not own.
     */
    public static function canRead(): bool|WP_Error
    {
        return self::requireCapability('fc_access_app');
    }
}
