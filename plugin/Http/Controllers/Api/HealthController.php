<?php

namespace FitnessClub\Http\Controllers\Api;

use FitnessClub\Services\HealthService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `/health/*` (plans/02-api-contract.md#health--progress, W2.2).
 *
 * Reads need `fc_access_app`; **writes need the `can_log_health` entitlement**,
 * applied per action rather than in the permission callback so the refusal
 * carries the feature name and the client can offer an upgrade — see
 * MemberController::requireFeature().
 *
 * No id in any of the collection paths: the subject is always the signed-in
 * member, resolved from the session.
 */
final class HealthController extends MemberController
{
    private HealthService $health;

    public function __construct($request, $vendor)
    {
        parent::__construct($request, $vendor);

        $this->health = new HealthService();
    }

    /**
     * GET /health/summary
     */
    public function summary(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);

        return $this->asMember(
            fn(int $accountId, int $fcUserId): array => $this->health->summary($fcUserId)
        );
    }

    /**
     * GET /health/stats?from=&to=&metrics=
     */
    public function stats(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $accountId, int $fcUserId): array => $this->health->stats(
            $fcUserId,
            $request->get_param('from'),
            $request->get_param('to'),
            $this->metrics($request)
        ));
    }

    /**
     * POST /health/stats — upserts on `record_date`.
     */
    public function store(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request) {
            $refusal = $this->requireFeature($fcUserId, 'can_log_health');
            if (null !== $refusal) {
                return $refusal;
            }

            // 200, not 201: the endpoint upserts, so a second entry on the same
            // day updates a record rather than creating one, and answering
            // "Created" to an update is a lie the client may act on.
            return $this->health->save($fcUserId, $this->payload($request));
        });
    }

    /**
     * PUT /health/stats/{id}
     */
    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request) {
            $refusal = $this->requireFeature($fcUserId, 'can_log_health');
            if (null !== $refusal) {
                return $refusal;
            }

            return $this->health->updateStat(
                $fcUserId,
                (int) $request->get_param('id'),
                $this->payload($request)
            );
        });
    }

    /**
     * DELETE /health/stats/{id}
     */
    public function destroy(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request) {
            $refusal = $this->requireFeature($fcUserId, 'can_log_health');
            if (null !== $refusal) {
                return $refusal;
            }

            $this->health->deleteStat($fcUserId, (int) $request->get_param('id'));

            return ['ok' => true];
        });
    }

    /**
     * GET /health/measurements?from=&to=
     */
    public function measurements(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(fn(int $accountId, int $fcUserId): array => $this->health->measurements(
            $fcUserId,
            $request->get_param('from'),
            $request->get_param('to')
        ));
    }

    /**
     * POST /health/measurements — upserts on `record_date`.
     */
    public function storeMeasurements(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asMember(function (int $accountId, int $fcUserId) use ($request) {
            $refusal = $this->requireFeature($fcUserId, 'can_log_health');
            if (null !== $refusal) {
                return $refusal;
            }

            return $this->health->saveMeasurements($fcUserId, $this->payload($request));
        });
    }

    /**
     * The writable fields, read from the JSON body.
     *
     * Only the keys actually sent are forwarded, because the service treats a
     * present key as "write this" and an absent one as "leave it alone" — the
     * rule that lets a member log weight in the morning and sleep at night
     * without the second entry erasing the first. Reading through
     * `get_param()` instead would flatten that distinction: an unsent field and
     * a field sent as null would arrive identically.
     *
     * @return array<string,mixed>
     */
    private function payload(WP_REST_Request $request): array
    {
        $body    = (array) $request->get_json_params();
        $keys    = array_merge(
            array_keys(HealthService::FIELDS),
            array_keys(HealthService::MEASUREMENTS),
            ['record_date', 'notes']
        );
        $payload = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $body)) {
                $payload[$key] = $body[$key];
            }
        }

        return $payload;
    }

    /**
     * `?metrics=weight_kg,sleep_hours` — a comma list, filtered to what exists.
     *
     * @return string[]
     */
    private function metrics(WP_REST_Request $request): array
    {
        $raw = $request->get_param('metrics');

        if (!is_string($raw) || '' === trim($raw)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public static function canRead(): bool|WP_Error
    {
        return self::requireCapability('fc_access_app');
    }

    public static function canLog(): bool|WP_Error
    {
        return self::requireCapability('fc_log_health');
    }
}
