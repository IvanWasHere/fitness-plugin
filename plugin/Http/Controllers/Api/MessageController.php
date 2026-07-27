<?php

namespace FitnessClub\Http\Controllers\Api;

use FitnessClub\Auth\Auth;
use FitnessClub\Services\MessageService;
use FitnessClub\Support\DomainException;
use FitnessClub\WPBones\Routing\API\RestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `/messages/*` (plans/02-api-contract.md#messaging, W3.1).
 *
 * Keyed on the **account**, like notifications: both a member and a trainer have
 * conversations, and a trainer has no `fc_users` row. Ownership is not a
 * capability question here — it is `Guard::participatesInThread()`, applied
 * inside every service call, because being a party to a thread is the only thing
 * that grants access to it.
 *
 * The trainer-side screens arrive in W3.4; this API already serves both sides,
 * which is why the service resolves the counterpart rather than assuming one.
 */
final class MessageController extends RestController
{
    private MessageService $messages;

    public function __construct($request, $vendor)
    {
        parent::__construct($request, $vendor);

        $this->messages = new MessageService();
    }

    /**
     * GET /messages/threads
     */
    public function threads(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);

        return $this->run(fn(int $accountId): array => $this->messages->threads($accountId));
    }

    /**
     * GET /messages/threads/{id}?before=&limit=
     */
    public function thread(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->messages->thread(
            $accountId,
            (int) $request->get_param('id'),
            null === $request->get_param('before') ? null : (int) $request->get_param('before'),
            null === $request->get_param('limit') ? null : (int) $request->get_param('limit')
        ));
    }

    /**
     * POST /messages/threads/{id}
     *
     * Both member-side gates — `can_message` and the per-thread quota — live in
     * the service, applied only after it has established that the caller is on
     * the thread. Gating here instead would answer 403 to a stranger probing a
     * thread id, which tells them the conversation exists.
     */
    public function send(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (int $accountId) use ($request) {
            $body = (array) $request->get_json_params();

            return $this->messages->send(
                $accountId,
                (int) $request->get_param('id'),
                (string) ($body['message'] ?? ''),
                array_values(array_filter(
                    (array) ($body['attachments'] ?? []),
                    static fn($url): bool => is_string($url) && '' !== $url
                ))
            );
        }, 201);
    }

    /**
     * POST /messages/threads/{id}/read
     */
    public function read(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->messages->markRead(
            $accountId,
            (int) $request->get_param('id')
        ));
    }

    /**
     * GET /messages/unread-count
     */
    public function unreadCount(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);

        return $this->run(fn(int $accountId): array => [
            'unread_total' => $this->messages->unreadCount($accountId),
        ]);
    }

    /**
     * GET /messages/poll?since=
     */
    public function poll(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->messages->poll(
            $accountId,
            null === $request->get_param('since') ? null : (int) $request->get_param('since')
        ));
    }

    /**
     * POST /messages/attachments — multipart, images only, ≤5 MB.
     *
     * Validated by **content**, not by filename: `wp_check_filetype_and_ext()`
     * reads the actual bytes, so `payload.php.jpg` is rejected rather than
     * trusted because of what it is called.
     */
    public function upload(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (int $accountId) use ($request) {
            $files = $request->get_file_params();
            $file  = $files['file'] ?? null;

            if (!is_array($file) || !isset($file['tmp_name'])) {
                return $this->responseError(
                    'fc_no_file',
                    __('No file was uploaded.', 'fitnessclub'),
                    400
                );
            }

            $maxBytes = 5 * MB_IN_BYTES;

            if ((int) ($file['size'] ?? 0) > $maxBytes) {
                return $this->responseError(
                    'fc_file_too_large',
                    __('Images must be 5 MB or smaller.', 'fitnessclub'),
                    413
                );
            }

            $allowed = [
                'jpg|jpeg|jpe' => 'image/jpeg',
                'gif'          => 'image/gif',
                'png'          => 'image/png',
                'webp'         => 'image/webp',
            ];

            $checked = wp_check_filetype_and_ext(
                $file['tmp_name'],
                (string) ($file['name'] ?? ''),
                $allowed
            );

            if (empty($checked['type'])) {
                return $this->responseError(
                    'fc_file_type',
                    __('Only JPEG, PNG, GIF and WebP images can be attached.', 'fitnessclub'),
                    415
                );
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';

            $result = wp_handle_upload($file, [
                'test_form' => false,
                'mimes'     => $allowed,
            ]);

            if (isset($result['error'])) {
                return $this->responseError('fc_upload_failed', (string) $result['error'], 400);
            }

            return $this->response([
                'url'  => $result['url'],
                'type' => $result['type'],
            ], 201);
        });
    }

    /**
     * Run an action as the signed-in account, mapping domain refusals.
     *
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
     * Any signed-in, active account — a trainer has conversations too, so this
     * is deliberately not `fc_access_app`.
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
