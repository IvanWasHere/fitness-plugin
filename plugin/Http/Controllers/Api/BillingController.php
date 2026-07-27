<?php

namespace FitnessClub\Http\Controllers\Api;

use FitnessClub\Payments\GatewayRegistry;
use FitnessClub\Services\PaymentService;
use FitnessClub\Services\SubscriptionService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `/billing/*` (plans/02-api-contract.md#subscriptions--billing, W3.2).
 *
 * Member-scoped, so it goes through `MemberController::asMember()` — a
 * subscription belongs to an `fc_users` row, and a trainer or administrator has
 * none. Administrators manage subscriptions through `/admin/subscriptions`
 * instead, which is a different question with a different capability.
 *
 * **The webhook is the exception and is deliberately public** — a gateway cannot
 * authenticate as anybody. Its security is the adapter's signature check, and it
 * is the only route in this plugin whose permission callback is
 * `__return_true`.
 */
final class BillingController extends MemberController
{
    private SubscriptionService $subscriptions;

    private PaymentService $payments;

    public function __construct($request, $vendor)
    {
        parent::__construct($request, $vendor);

        $this->subscriptions = new SubscriptionService();
        $this->payments      = new PaymentService();
    }

    /**
     * GET /billing/subscription
     */
    public function subscription(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);

        return $this->asMember(
            fn(int $accountId, int $fcUserId): array => $this->subscriptions->forUser($fcUserId)
        );
    }

    /**
     * GET /billing/plans?trainer_id=
     */
    public function plans(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request): array {
            unset($fcUserId);

            $trainerId = $request->get_param('trainer_id');

            return [
                'items' => $this->subscriptions->availablePlans(
                    null === $trainerId ? null : (int) $trainerId
                ),
                // The screen needs to know whether checkout will redirect or
                // settle on the spot, and which processor is actually live.
                'gateway' => GatewayRegistry::active()->name(),
            ];
        });
    }

    /**
     * POST /billing/checkout
     *
     * Takes a `plan_id` and a `cycle` and **never a price** — the server looks
     * the amount up. A client-supplied price is, per the contract, the single
     * most commonly exploited endpoint in subscription plugins.
     */
    public function checkout(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $accountId, int $fcUserId): array => $this->subscriptions->checkout(
            $fcUserId,
            (int) $request->get_param('plan_id'),
            (string) $request->get_param('cycle'),
            $request->get_param('return_url')
        ));
    }

    /**
     * POST /billing/subscription/cancel
     */
    public function cancel(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $accountId, int $fcUserId): array => $this->subscriptions->cancel(
            $fcUserId,
            (int) $request->get_param('subscription_id'),
            // Defaults true, and the member-facing UI never sends false: they
            // keep what they paid for. Immediate cancellation is an admin action.
            (bool) ($request->get_param('at_period_end') ?? true)
        ));
    }

    /**
     * POST /billing/subscription/resume
     */
    public function resume(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $accountId, int $fcUserId): array => $this->subscriptions->resume(
            $fcUserId,
            (int) $request->get_param('subscription_id')
        ));
    }

    /**
     * POST /billing/subscription/change
     */
    public function change(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $accountId, int $fcUserId): array => $this->subscriptions->change(
            $fcUserId,
            (int) $request->get_param('subscription_id'),
            (int) $request->get_param('plan_id'),
            (string) $request->get_param('cycle')
        ));
    }

    /**
     * GET /billing/payments
     */
    public function paymentHistory(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $accountId, int $fcUserId): array => $this->payments->history(
            $fcUserId,
            (int) ($request->get_param('page') ?? 1),
            (int) ($request->get_param('per_page') ?? 20)
        ));
    }

    /**
     * POST /billing/webhook/{gateway} — public.
     *
     * Returns the adapter's own status: 401 only for a failed signature, 200 for
     * everything else including duplicates and event types we ignore, so the
     * gateway stops retrying what we will never act on.
     */
    public function webhook(WP_REST_Request $request): WP_REST_Response
    {
        $result = $this->payments->handleWebhook(
            (string) $request->get_param('gateway'),
            $request
        );

        return new WP_REST_Response($result['body'], $result['status']);
    }

    public static function canRead(): bool|WP_Error
    {
        return self::requireCapability('fc_access_app');
    }

    public static function canManage(): bool|WP_Error
    {
        return self::requireCapability('fc_manage_own_subscription');
    }
}
