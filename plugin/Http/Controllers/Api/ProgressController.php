<?php

namespace FitnessClub\Http\Controllers\Api;

use FitnessClub\Services\ProgressService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `/progress/*` (plans/02-api-contract.md#health--progress, W2.3).
 *
 * Read-only, and gated on `fc_view_own_stats` rather than plain app access —
 * the capability the member role already carries for exactly this. The
 * `can_view_stats` entitlement is checked on top: it is true on every tier
 * today, but progress analytics is the kind of thing a plan gets sold on, and
 * the seam belongs here rather than in a later refactor of every route.
 *
 * Everything is scoped to the signed-in member; no route takes a user id.
 */
final class ProgressController extends MemberController
{
    private ProgressService $progress;

    public function __construct($request, $vendor)
    {
        parent::__construct($request, $vendor);

        $this->progress = new ProgressService();
    }

    /**
     * GET /progress?range=week|month|quarter|year
     */
    public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request) {
            $refusal = $this->requireFeature($fcUserId, 'can_view_stats');
            if (null !== $refusal) {
                return $refusal;
            }

            return $this->progress->range($fcUserId, (string) ($request->get_param('range') ?? 'month'));
        });
    }

    /**
     * GET /progress/exercises/{name}
     *
     * The name is URL-encoded rather than an id: personal records are keyed by
     * exercise *name* so they follow a movement across workout templates
     * (see the fc_personal_records migration), and this chart has to key the
     * same way or the two disagree about what "Bench Press" means.
     */
    public function exercise(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request) {
            $refusal = $this->requireFeature($fcUserId, 'can_view_stats');
            if (null !== $refusal) {
                return $refusal;
            }

            $name = trim((string) urldecode((string) $request->get_param('name')));

            if ('' === $name) {
                return $this->responseError(
                    'fc_exercise_name_required',
                    __('Which exercise?', 'fitnessclub'),
                    400
                );
            }

            return $this->progress->exerciseProgression(
                $fcUserId,
                $name,
                (string) ($request->get_param('range') ?? 'quarter')
            );
        });
    }

    /**
     * GET /progress/records
     */
    public function records(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);

        return $this->asMember(function (int $accountId, int $fcUserId) {
            $refusal = $this->requireFeature($fcUserId, 'can_view_stats');
            if (null !== $refusal) {
                return $refusal;
            }

            return ['items' => $this->progress->records($fcUserId)];
        });
    }

    /**
     * GET /progress/consistency?year=
     */
    public function consistency(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request) {
            $refusal = $this->requireFeature($fcUserId, 'can_view_stats');
            if (null !== $refusal) {
                return $refusal;
            }

            $year = $request->get_param('year');

            return $this->progress->consistencyCalendar(
                $fcUserId,
                null === $year ? null : (int) $year
            );
        });
    }

    public static function canRead(): bool|WP_Error
    {
        return self::requireCapability('fc_view_own_stats');
    }
}
