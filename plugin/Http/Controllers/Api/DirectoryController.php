<?php

namespace FitnessClub\Http\Controllers\Api;

use FitnessClub\Services\TrainerDirectoryService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `/trainers/*` and `/user/trainers/*` — the directory and the request flow
 * (plans/02-api-contract.md#trainer-directory--requests-trainers-q4, W3.5).
 *
 * Every route here goes through `asMember()`, which is the point: the directory
 * is the one part of the API where a member reads another account's record, and
 * the caller's own `fc_users.id` has to come from the session rather than the
 * request. An endpoint that accepted a user id would be an endpoint that
 * requested a trainer on somebody else's behalf, spending *their* slot.
 *
 * Gated on `fc_access_app` rather than on an entitlement. Whether the member may
 * actually *request* is a Q14 question the service answers with the eligibility
 * block; browsing is deliberately open to everybody signed in, because a member
 * with no plan is exactly who the directory is trying to reach.
 */
final class DirectoryController extends MemberController
{
    private TrainerDirectoryService $directory;

    public function __construct($request, $vendor)
    {
        parent::__construct($request, $vendor);

        $this->directory = new TrainerDirectoryService();
    }

    /**
     * GET /trainers?q=&specialization=
     */
    public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $accountId, int $fcUserId): array => $this->directory->directory(
            $fcUserId,
            (string) ($request->get_param('q') ?? ''),
            (string) ($request->get_param('specialization') ?? '')
        ));
    }

    /**
     * GET /trainers/{id}
     */
    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $accountId, int $fcUserId): array => $this->directory->profile(
            $fcUserId,
            (int) $request->get_param('id')
        ));
    }

    /**
     * POST /trainers/{id}/request
     */
    public function requestTrainer(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $message = $request->get_param('message');

        return $this->asMember(
            fn(int $accountId, int $fcUserId): array => $this->directory->request(
                $fcUserId,
                (int) $request->get_param('id'),
                null === $message ? null : (string) $message
            ),
            201
        );
    }

    /**
     * DELETE /trainers/{id}/request
     */
    public function withdraw(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $accountId, int $fcUserId): array => $this->directory->withdraw(
            $fcUserId,
            (int) $request->get_param('id')
        ));
    }

    /**
     * GET /user/trainers
     */
    public function mine(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(
            fn(int $accountId, int $fcUserId): array => $this->directory->myTrainers($fcUserId)
        );
    }

    /**
     * DELETE /user/trainers/{trainerId}
     */
    public function leave(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $accountId, int $fcUserId): array => $this->directory->leave(
            $fcUserId,
            (int) $request->get_param('trainerId')
        ));
    }

    /**
     * POST /user/trainers/{trainerId}/primary
     */
    public function setPrimary(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $accountId, int $fcUserId): array => $this->directory->setPrimary(
            $fcUserId,
            (int) $request->get_param('trainerId')
        ));
    }

    public static function canRead(): bool|WP_Error
    {
        return self::requireCapability('fc_access_app');
    }
}
