<?php

namespace FitnessClub\Http\Controllers\Api;

use FitnessClub\Services\EntitlementService;
use FitnessClub\Support\DomainException;
use FitnessClub\Support\Guard;
use FitnessClub\WPBones\Routing\API\RestController;
use WP_Error;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Shared plumbing for endpoints that act as the signed-in member.
 *
 * Three things every one of them needs, and getting any of them wrong is the
 * classic hole in a plugin of this shape (plans/03-backend.md):
 *
 *   - **the caller's fc_users.id**, resolved from the WordPress user rather than
 *     accepted from the request — an endpoint that takes a user id as a
 *     parameter is an endpoint that will be handed someone else's;
 *   - **entitlement gates**, returning `fc_feature_unavailable` with
 *     `required_feature` so the client can offer an upgrade instead of an error;
 *   - **one place to turn a DomainException into its REST answer**, so services
 *     can refuse an operation from inside a transaction without every action
 *     re-implementing the mapping.
 */
abstract class MemberController extends RestController
{
    private ?EntitlementService $entitlements = null;

    /**
     * Run an action as the current member, converting domain refusals into the
     * contract's error envelope.
     *
     * @param callable(int, int): (array<string,mixed>|WP_REST_Response|WP_Error) $action
     *        Receives (wpUserId, fcUserId).
     */
    protected function asMember(callable $action, int $successStatus = 200): WP_REST_Response|WP_Error
    {
        $wpUserId = get_current_user_id();
        $fcUserId = Guard::userId($wpUserId);

        if (null === $fcUserId) {
            // Signed in to WordPress but with no member profile — a trainer or
            // an admin hitting a member endpoint, or an account that predates
            // the plugin. Not a 500, and not silently empty either.
            return $this->responseError(
                'fc_no_member_profile',
                __('This account does not have a member profile.', 'fitnessclub'),
                403
            );
        }

        try {
            $result = $action($wpUserId, $fcUserId);
        } catch (DomainException $e) {
            return $e->toWpError();
        }

        if ($result instanceof WP_REST_Response || $result instanceof WP_Error) {
            return $result;
        }

        return $this->response($result, $successStatus);
    }

    /**
     * Refuse the request unless the member's plan grants the feature.
     */
    protected function requireFeature(int $fcUserId, string $feature): ?WP_Error
    {
        if ($this->entitlements()->can($fcUserId, $feature)) {
            return null;
        }

        return $this->entitlements()->unavailable($feature);
    }

    protected function entitlements(): EntitlementService
    {
        return $this->entitlements ??= new EntitlementService();
    }

    /**
     * Collection response with the contract's pagination headers, so a client
     * can page without parsing the body first.
     *
     * @param array{items:array<int,mixed>,total:int,page:int,per_page:int} $result
     */
    protected function collection(array $result): WP_REST_Response
    {
        $response = $this->response($result);

        $response->header('X-WP-Total', (string) $result['total']);
        $response->header(
            'X-WP-TotalPages',
            (string) (int) ceil($result['total'] / max(1, $result['per_page']))
        );

        return $response;
    }

    /**
     * Permission callback: a signed-in member with the capability.
     */
    protected static function requireCapability(string $capability): bool|WP_Error
    {
        if (!is_user_logged_in()) {
            return new WP_Error(
                'fc_not_authenticated',
                __('You need to be signed in.', 'fitnessclub'),
                ['status' => 401]
            );
        }

        if (!current_user_can($capability)) {
            return new WP_Error(
                'fc_forbidden',
                __('You are not allowed to do that.', 'fitnessclub'),
                ['status' => 403]
            );
        }

        return true;
    }
}
