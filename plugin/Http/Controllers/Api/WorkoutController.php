<?php

namespace FitnessClub\Http\Controllers\Api;

use FitnessClub\Services\WorkoutService;
use FitnessClub\Support\DeltaSync;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `/workouts/*` and `/exercises/{id}` — the read side of the workout domain
 * (plans/02-api-contract.md#workouts-workouts-sessions).
 *
 * Every route is scoped to the caller's assignments inside WorkoutService, so
 * there is no id-in-the-path check to forget here.
 */
final class WorkoutController extends MemberController
{
    private WorkoutService $workouts;

    public function __construct($request, $vendor)
    {
        parent::__construct($request, $vendor);
        $this->workouts = new WorkoutService();
    }

    /**
     * GET /workouts
     */
    public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request): WP_REST_Response {
            return $this->collection($this->workouts->listForUser($fcUserId, [
                'status'     => $request->get_param('status'),
                'difficulty' => $request->get_param('difficulty'),
                'type'       => $request->get_param('type'),
                'q'          => $request->get_param('q'),
                'page'       => $request->get_param('page'),
                'per_page'   => $request->get_param('per_page'),
                DeltaSync::PARAM => $request->get_param(DeltaSync::PARAM),
            ]));
        });
    }

    /**
     * GET /workouts/{id}
     */
    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request): array {
            $workout = $this->workouts->detailForUser($fcUserId, (int) $request->get_param('id'));

            // Video is a paid feature; the workout is still readable without it,
            // so strip the URLs rather than refusing the whole resource.
            if (!$this->entitlements()->can($fcUserId, 'has_video_workouts')) {
                $workout['video_url'] = null;
                $workout['exercises'] = array_map(static function (array $exercise): array {
                    $exercise['video_url'] = null;

                    return $exercise;
                }, $workout['exercises']);
                $workout['video_locked'] = true;
            }

            return $workout;
        });
    }

    /**
     * GET /exercises/{id}
     */
    public function exercise(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request): array {
            $exercise = $this->workouts->exerciseForUser($fcUserId, (int) $request->get_param('id'));

            if (!$this->entitlements()->can($fcUserId, 'has_video_workouts')) {
                $exercise['video_url']    = null;
                $exercise['video_locked'] = true;
            }

            return $exercise;
        });
    }

    /**
     * Permission callback for the whole group.
     */
    public static function canRead(): bool|WP_Error
    {
        return self::requireCapability('fc_access_app');
    }
}
