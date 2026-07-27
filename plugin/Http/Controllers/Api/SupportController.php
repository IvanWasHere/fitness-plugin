<?php

namespace FitnessClub\Http\Controllers\Api;

use FitnessClub\Auth\Auth;
use FitnessClub\Services\TicketService;
use FitnessClub\Support\DomainException;
use FitnessClub\WPBones\Routing\API\RestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `/support/*` (plans/02-api-contract.md#notifications--support, W3.3).
 *
 * Account-keyed, like notifications and messages: a trainer raises tickets and
 * has no `fc_users` row.
 *
 * **One set of endpoints for members and staff.** Which you are decides what the
 * same URL returns — the whole queue or your own tickets, with or without
 * internal notes — and that decision is made in `TicketService`, not here.
 * Two parallel route trees would mean two places for the internal-note filter
 * to be forgotten.
 */
final class SupportController extends RestController
{
    private TicketService $tickets;

    public function __construct($request, $vendor)
    {
        parent::__construct($request, $vendor);

        $this->tickets = new TicketService();
    }

    /**
     * GET /support/tickets
     */
    public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->tickets->index(
            $accountId,
            [
                'status'      => $request->get_param('status'),
                'priority'    => $request->get_param('priority'),
                'category'    => $request->get_param('category'),
                'assigned_to' => $request->get_param('assigned_to'),
                'q'           => $request->get_param('q'),
            ],
            (int) ($request->get_param('page') ?? 1),
            (int) ($request->get_param('per_page') ?? 20)
        ));
    }

    /**
     * GET /support/tickets/{id}
     */
    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->tickets->show(
            $accountId,
            (int) $request->get_param('id')
        ));
    }

    /**
     * POST /support/tickets
     */
    public function store(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(
            fn(int $accountId): array => $this->tickets->create(
                $accountId,
                (array) $request->get_json_params()
            ),
            201
        );
    }

    /**
     * POST /support/tickets/{id}/replies
     */
    public function reply(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (int $accountId) use ($request): array {
            $body = (array) $request->get_json_params();

            return $this->tickets->reply(
                $accountId,
                (int) $request->get_param('id'),
                (string) ($body['message'] ?? ''),
                // Requested here, granted in the service — only staff may write
                // one, and a member asking for it is ignored rather than refused.
                (bool) ($body['is_internal_note'] ?? false)
            );
        }, 201);
    }

    /**
     * PATCH /support/tickets/{id}
     */
    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->tickets->update(
            $accountId,
            (int) $request->get_param('id'),
            (array) $request->get_json_params()
        ));
    }

    /**
     * GET /support/faq
     */
    public function faq(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);

        return $this->run(fn(int $accountId): array => ['items' => $this->tickets->faq()]);
    }

    /**
     * @param callable(int): (array<string,mixed>|WP_REST_Response|WP_Error) $action
     */
    private function run(callable $action, int $successStatus = 200): WP_REST_Response|WP_Error
    {
        $accountId = Auth::accountId();

        if ($accountId <= 0) {
            return $this->responseError(
                'fc_not_authenticated',
                __('You need to be signed in.', 'fitnessclub'),
                401
            );
        }

        try {
            $result = $action($accountId);
        } catch (DomainException $e) {
            return $e->toWpError();
        }

        if ($result instanceof WP_REST_Response || $result instanceof WP_Error) {
            return $result;
        }

        return $this->response($result, $successStatus);
    }

    /**
     * Any signed-in, active account. Raising a ticket is how somebody reports
     * that the rest of the app is refusing them, so gating it on a feature
     * capability would lock the door and post the key inside.
     */
    public static function canAccess(): bool|WP_Error
    {
        $account = Auth::account();

        if (null === $account) {
            return new WP_Error(
                'fc_not_authenticated',
                __('You need to be signed in.', 'fitnessclub'),
                ['status' => 401]
            );
        }

        if (!$account->isActive()) {
            return new WP_Error(
                'fc_account_inactive',
                __('This account is not active.', 'fitnessclub'),
                ['status' => 403]
            );
        }

        return true;
    }
}
