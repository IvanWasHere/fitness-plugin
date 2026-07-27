<?php

namespace FitnessClub\Http\Controllers\Api;

use FitnessClub\Auth\Auth;
use FitnessClub\Services\ActivityService;
use FitnessClub\Services\NotificationService;
use FitnessClub\Support\DomainException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `/notifications/*`, `/activity` and `/user/preferences`
 * (plans/02-api-contract.md#notifications--support, W2.4).
 *
 * These are keyed on the **account**, not the member profile, which is why they
 * do not go through `asMember()` like the rest of the member API: a trainer and
 * an administrator both have an inbox, and neither has an `fc_users` row. Using
 * `asMember()` here would answer `fc_no_member_profile` to a trainer looking at
 * their own notifications.
 *
 * The one exception is preferences, which are stored on `fc_users.preferences`
 * and so genuinely do require a member profile.
 */
final class NotificationController extends MemberController
{
    private NotificationService $notifications;

    private ActivityService $activity;

    public function __construct($request, $vendor)
    {
        parent::__construct($request, $vendor);

        $this->notifications = new NotificationService();
        $this->activity      = new ActivityService();
    }

    /**
     * GET /notifications?unread_only=&page=&per_page=
     */
    public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asAccount(fn(int $accountId): array => $this->notifications->list(
            $accountId,
            (bool) $request->get_param('unread_only'),
            (int) ($request->get_param('page') ?? 1),
            (int) ($request->get_param('per_page') ?? 20)
        ));
    }

    /**
     * POST /notifications/{id}/read
     */
    public function read(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asAccount(fn(int $accountId): array => $this->notifications->markRead(
            $accountId,
            (int) $request->get_param('id')
        ));
    }

    /**
     * POST /notifications/read-all
     */
    public function readAll(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);

        return $this->asAccount(fn(int $accountId): array => $this->notifications->markAllRead($accountId));
    }

    /**
     * DELETE /notifications/{id}
     */
    public function destroy(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asAccount(fn(int $accountId): array => $this->notifications->dismiss(
            $accountId,
            (int) $request->get_param('id')
        ));
    }

    /**
     * GET /activity?page=&per_page=&types=
     */
    public function activity(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asAccount(function (int $accountId) use ($request): array {
            $raw   = $request->get_param('types');
            $types = is_string($raw) && '' !== trim($raw)
                ? array_values(array_filter(array_map('trim', explode(',', $raw))))
                : [];

            $result = $this->activity->paged(
                $accountId,
                (int) ($request->get_param('page') ?? 1),
                (int) ($request->get_param('per_page') ?? 20),
                $types
            );

            // The filter's options travel with the list, so the control can be
            // built without a second request — and only ever offers types the
            // member actually has.
            $result['available_types'] = $this->activity->types($accountId);

            return $result;
        });
    }

    /**
     * GET /user/preferences
     */
    public function preferences(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);

        return $this->asMember(fn(int $accountId, int $fcUserId): array => [
            'notifications' => $this->notifications->preferences($accountId),
        ]);
    }

    /**
     * PUT /user/preferences
     *
     * Partial by design: the screen submits the one switch that was flipped, and
     * the service merges rather than replacing — `fc_users.preferences` is a
     * shared blob and a wholesale write would drop the privacy settings that
     * live beside these.
     */
    public function updatePreferences(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request): array {
            $body = (array) $request->get_json_params();

            return [
                'notifications' => $this->notifications->setPreferences(
                    $accountId,
                    (array) ($body['notifications'] ?? [])
                ),
            ];
        });
    }

    /**
     * Run an action as the signed-in **account**, whatever its role.
     *
     * The account-level counterpart to `MemberController::asMember()`: same
     * DomainException mapping, no `fc_users` lookup, because an inbox belongs to
     * whoever signs in rather than to a member profile.
     *
     * @param callable(int): (array<string,mixed>|WP_REST_Response|WP_Error) $action
     */
    private function asAccount(callable $action): WP_REST_Response|WP_Error
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

        return $this->response($result);
    }

    /**
     * Any signed-in, active account.
     *
     * Deliberately **not** `fc_access_app`: that capability belongs to the
     * member role alone, and a trainer or an administrator has an inbox too. The
     * rows are keyed on `account_id`, so the ownership scoping is in every query
     * rather than in the capability — there is no id in these paths for a caller
     * to swap.
     */
    public static function canRead(): bool|WP_Error
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

    /**
     * Preferences live on `fc_users.preferences`, so these two genuinely do
     * require a member profile — unlike the inbox above.
     */
    public static function canManagePreferences(): bool|WP_Error
    {
        return self::requireCapability('fc_access_app');
    }
}
