<?php

namespace FitnessClub\Http\Controllers\Api;

use FitnessClub\Services\FoodService;
use FitnessClub\Services\NutritionService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `/nutrition/*` and `/foods/*` (plans/02-api-contract.md#nutrition, W2.1).
 *
 * Reads need `fc_access_app`; **writes need the `can_log_nutrition`
 * entitlement**, which the free tier does not grant. The gate is applied per
 * action rather than in the permission callback because it produces
 * `fc_feature_unavailable` with the feature name attached, so the client can
 * offer an upgrade instead of an error — see MemberController::requireFeature().
 */
final class NutritionController extends MemberController
{
    private NutritionService $nutrition;

    private FoodService $foods;

    public function __construct($request, $vendor)
    {
        parent::__construct($request, $vendor);

        $this->nutrition = new NutritionService();
        $this->foods     = new FoodService();
    }

    /**
     * GET /nutrition/day?date=
     */
    public function day(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $accountId, int $fcUserId): array => $this->nutrition->day(
            $fcUserId,
            $request->get_param('date')
        ));
    }

    /**
     * GET /nutrition/logs?from=&to=
     */
    public function history(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request): array {
            $to   = (string) ($request->get_param('to') ?? gmdate('Y-m-d'));
            $from = (string) ($request->get_param('from') ?? gmdate('Y-m-d', strtotime($to . ' -29 days')));

            return ['items' => $this->nutrition->range($fcUserId, $from, $to), 'from' => $from, 'to' => $to];
        });
    }

    /**
     * POST /nutrition/logs
     */
    public function store(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request) {
            $refusal = $this->requireFeature($fcUserId, 'can_log_nutrition');
            if (null !== $refusal) {
                return $refusal;
            }

            return $this->response(
                $this->nutrition->logMeal($fcUserId, $this->mealPayload($request)),
                201
            );
        });
    }

    /**
     * PUT /nutrition/logs/{id}
     */
    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request) {
            $refusal = $this->requireFeature($fcUserId, 'can_log_nutrition');
            if (null !== $refusal) {
                return $refusal;
            }

            return $this->nutrition->updateMeal(
                $fcUserId,
                (int) $request->get_param('id'),
                $this->mealPayload($request, true)
            );
        });
    }

    /**
     * DELETE /nutrition/logs/{id}
     */
    public function destroy(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request) {
            $refusal = $this->requireFeature($fcUserId, 'can_log_nutrition');
            if (null !== $refusal) {
                return $refusal;
            }

            $this->nutrition->deleteMeal($fcUserId, (int) $request->get_param('id'));

            return ['ok' => true];
        });
    }

    /**
     * POST /nutrition/water — `{delta_ml}` for the +1 button, `{total_ml}` for
     * clicking the n-th dot.
     */
    public function water(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request) {
            $refusal = $this->requireFeature($fcUserId, 'can_log_nutrition');
            if (null !== $refusal) {
                return $refusal;
            }

            $delta = $request->get_param('delta_ml');
            $total = $request->get_param('total_ml');

            return $this->nutrition->setWater(
                $fcUserId,
                null === $delta ? null : (int) $delta,
                null === $total ? null : (int) $total,
                $request->get_param('date')
            );
        });
    }

    /**
     * PUT /nutrition/goals
     */
    public function goals(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request) {
            $refusal = $this->requireFeature($fcUserId, 'can_log_nutrition');
            if (null !== $refusal) {
                return $refusal;
            }

            return $this->nutrition->setGoals($fcUserId, [
                'calories'  => $request->get_param('calories'),
                'protein_g' => $request->get_param('protein_g'),
                'carbs_g'   => $request->get_param('carbs_g'),
                'fat_g'     => $request->get_param('fat_g'),
                'water_ml'  => $request->get_param('water_ml'),
            ], $request->get_param('date'));
        });
    }

    /**
     * GET /foods?q=&category=
     */
    public function foods(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $accountId, int $fcUserId): array => [
            'items' => $this->foods->search([
                'q'        => (string) ($request->get_param('q') ?? ''),
                'category' => (string) ($request->get_param('category') ?? ''),
                'per_page' => $request->get_param('per_page'),
            ]),
        ]);
    }

    /**
     * GET /foods/barcode/{code}
     */
    public function barcode(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request) {
            $food = $this->foods->findByBarcode((string) $request->get_param('code'));

            if (null === $food) {
                // A miss is a 404, not an empty 200: the scanner needs to tell
                // "we do not have this product" from "here it is".
                return $this->responseError(
                    'fc_food_not_found',
                    __('No food matches that barcode.', 'fitnessclub'),
                    404
                );
            }

            return $food;
        });
    }

    /**
     * POST /foods
     */
    public function createFood(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request) {
            $refusal = $this->requireFeature($fcUserId, 'can_log_nutrition');
            if (null !== $refusal) {
                return $refusal;
            }

            return $this->response(
                $this->foods->create((array) $request->get_json_params(), $accountId),
                201
            );
        });
    }

    /**
     * The meal fields, read straight from the request.
     *
     * On update, only the keys actually present are returned, so a PATCH-shaped
     * edit that omits `items` leaves the items alone rather than emptying the
     * meal.
     *
     * @return array<string,mixed>
     */
    private function mealPayload(WP_REST_Request $request, bool $partial = false): array
    {
        $body    = (array) $request->get_json_params();
        $payload = [];

        foreach (['meal_type', 'log_date', 'logged_at', 'notes', 'items'] as $key) {
            if (!$partial || array_key_exists($key, $body)) {
                $payload[$key] = $body[$key] ?? null;
            }
        }

        return $payload;
    }

    public static function canRead(): bool|WP_Error
    {
        return self::requireCapability('fc_access_app');
    }

    public static function canLog(): bool|WP_Error
    {
        return self::requireCapability('fc_log_nutrition');
    }
}
