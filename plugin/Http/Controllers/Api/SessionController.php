<?php

namespace FitnessClub\Http\Controllers\Api;

use FitnessClub\Services\WorkoutSessionService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `/sessions/*` — the workout player's entire conversation with the server
 * (plans/02-api-contract.md#the-session-state-machine).
 *
 * The session is an explicit resource with an id, not an implied "current
 * workout" the server has to infer. `plan.md` §6.3's identifier-free
 * `POST /workouts/pause` breaks the moment a user has a phone and a tablet.
 *
 * All the state logic lives in WorkoutSessionService; this layer reads
 * parameters, gates on entitlements, and lets the service's DomainExceptions
 * become the contract's error envelope.
 */
final class SessionController extends MemberController
{
    private WorkoutSessionService $sessions;

    public function __construct($request, $vendor)
    {
        parent::__construct($request, $vendor);
        $this->sessions = new WorkoutSessionService();
    }

    /**
     * POST /sessions — start a workout.
     */
    public function start(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $wpUserId, int $fcUserId) use ($request) {
            $blocked = $this->requireFeature($fcUserId, 'can_log_workouts');
            if (null !== $blocked) {
                return $blocked;
            }

            $session = $this->sessions->start($fcUserId, (int) $request->get_param('workout_id'));

            return $this->response($session, 201);
        });
    }

    /**
     * GET /sessions/active — the open session, or null on boot.
     */
    public function active(): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $wpUserId, int $fcUserId): WP_REST_Response {
            // 200 with null, not 404: "no session running" is a normal answer to
            // this question and should not read as an error in the client.
            return $this->response($this->sessions->active($fcUserId));
        });
    }

    /**
     * GET /sessions/{id}
     */
    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $wpUserId, int $fcUserId): array => $this->sessions->rehydrate(
            $fcUserId,
            (int) $request->get_param('id')
        ));
    }

    /**
     * GET /sessions — history.
     */
    public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $wpUserId, int $fcUserId): WP_REST_Response => $this->collection(
            $this->sessions->history($fcUserId, [
                'page'     => $request->get_param('page'),
                'per_page' => $request->get_param('per_page'),
            ])
        ));
    }

    /**
     * PATCH /sessions/{id} — `{action: "pause"|"resume"}`.
     */
    public function transition(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $wpUserId, int $fcUserId) use ($request): array {
            $sessionId = (int) $request->get_param('id');

            return 'pause' === $request->get_param('action')
                ? $this->sessions->pause($fcUserId, $sessionId)
                : $this->sessions->resume($fcUserId, $sessionId);
        });
    }

    /**
     * PATCH /sessions/{id}/cursor — skip / previous / jump.
     */
    public function cursor(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $wpUserId, int $fcUserId): array => $this->sessions->moveCursor(
            $fcUserId,
            (int) $request->get_param('id'),
            (int) $request->get_param('exercise_index'),
            (int) $request->get_param('set_index')
        ));
    }

    /**
     * POST /sessions/{id}/sets — log one completed set.
     */
    public function logSet(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $wpUserId, int $fcUserId): array => $this->sessions->logSet(
            $fcUserId,
            (int) $request->get_param('id'),
            (int) $request->get_param('exercise_id'),
            (int) $request->get_param('set_index'),
            [
                'reps'               => $request->get_param('reps'),
                'weight_kg'          => $request->get_param('weight_kg'),
                'duration_seconds'   => $request->get_param('duration_seconds'),
                'rest_taken_seconds' => $request->get_param('rest_taken_seconds'),
                'rpe'                => $request->get_param('rpe'),
            ]
        ));
    }

    /**
     * POST /sessions/{id}/complete — finish, and return the celebration payload.
     */
    public function complete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $wpUserId, int $fcUserId): array => $this->sessions->complete(
            $wpUserId,
            $fcUserId,
            (int) $request->get_param('id')
        ));
    }

    /**
     * POST /sessions/{id}/abandon — stop early, keep what was done.
     */
    public function abandon(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $wpUserId, int $fcUserId): array => $this->sessions->abandon(
            $fcUserId,
            (int) $request->get_param('id')
        ));
    }

    /**
     * PATCH /sessions/{id}/review — post-workout adjustment (§8.4).
     */
    public function review(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $wpUserId, int $fcUserId) use ($request): array {
            $payload = [];

            foreach (['notes', 'perceived_exertion', 'difficulty_rating', 'sets'] as $field) {
                if (null !== $request->get_param($field)) {
                    $payload[$field] = $request->get_param($field);
                }
            }

            return $this->sessions->review($fcUserId, (int) $request->get_param('id'), $payload);
        });
    }

    /**
     * Permission callback: logging a workout is a member capability.
     */
    public static function canLog(): bool|WP_Error
    {
        return self::requireCapability('fc_log_workouts');
    }

    /**
     * Reading your own sessions only needs app access — an admin reviewing their
     * own history is not "logging a workout".
     */
    public static function canRead(): bool|WP_Error
    {
        return self::requireCapability('fc_access_app');
    }
}
