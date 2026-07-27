<?php

namespace FitnessClub\Http\Controllers\Api;

use FitnessClub\Auth\Auth;
use FitnessClub\Services\TrainerLibraryService;
use FitnessClub\Services\TrainerService;
use FitnessClub\Support\DomainException;
use FitnessClub\WPBones\Routing\API\RestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `/trainer/*` (plans/02-api-contract.md#trainer, W3.4 — endpoints and guards).
 *
 * Gated on `fc_manage_clients`. **No route here accepts a client id without the
 * roster check** — every service method starts by asking
 * `Guard::trainerCoachesClient`, and a client who is not on the caller's roster
 * is a 404 rather than a 403, so a trainer cannot enumerate the platform's
 * membership by probing ids.
 *
 * The two guards and the line between them are documented on `TrainerService`.
 * The short version: **read is shared** across a client's trainers (Q13),
 * **write is the assigner's alone**, and **notes are neither** (Q15).
 *
 * This slice is the API. The trainer SPA's screens follow separately.
 */
final class TrainerController extends RestController
{
    private TrainerService $trainer;

    private TrainerLibraryService $library;

    public function __construct($request, $vendor)
    {
        parent::__construct($request, $vendor);

        $this->trainer = new TrainerService();
        $this->library = new TrainerLibraryService();
    }

    // ------------------------------------------------------------- overview

    public function dashboard(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);

        return $this->run(fn(int $accountId): array => $this->library->dashboard($accountId));
    }

    // -------------------------------------------------------------- requests

    public function requests(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->trainer->requests(
            $accountId,
            (string) ($request->get_param('status') ?? 'pending')
        ));
    }

    public function acceptRequest(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->trainer->acceptRequest(
            $accountId,
            (int) $request->get_param('id')
        ));
    }

    public function declineRequest(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (int $accountId) use ($request): array {
            $body = (array) $request->get_json_params();

            return $this->trainer->declineRequest(
                $accountId,
                (int) $request->get_param('id'),
                isset($body['reason']) ? (string) $body['reason'] : null
            );
        });
    }

    // --------------------------------------------------------------- clients

    public function clients(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->trainer->clients($accountId, [
            'status' => $request->get_param('status'),
            'q'      => $request->get_param('q'),
        ]));
    }

    public function client(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->trainer->client(
            $accountId,
            (int) $request->get_param('userId')
        ));
    }

    public function clientProgress(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->trainer->progress(
            $accountId,
            (int) $request->get_param('userId'),
            (string) ($request->get_param('range') ?? 'month')
        ));
    }

    public function clientSessions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->trainer->sessions(
            $accountId,
            (int) $request->get_param('userId'),
            (int) ($request->get_param('page') ?? 1),
            (int) ($request->get_param('per_page') ?? 20)
        ));
    }

    public function clientNutrition(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->trainer->nutrition(
            $accountId,
            (int) $request->get_param('userId'),
            $request->get_param('from'),
            $request->get_param('to')
        ));
    }

    // ----------------------------------------------------------- assignments

    public function assignWorkout(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (int $accountId) use ($request): array {
            $body = (array) $request->get_json_params();

            return $this->trainer->assignWorkout(
                $accountId,
                (int) $request->get_param('userId'),
                (int) ($body['workout_id'] ?? 0),
                isset($body['scheduled_for']) ? (string) $body['scheduled_for'] : null
            );
        }, 201);
    }

    public function unassignWorkout(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (int $accountId) use ($request): array {
            $this->trainer->unassignWorkout(
                $accountId,
                (int) $request->get_param('userId'),
                (int) $request->get_param('id')
            );

            return ['ok' => true];
        });
    }

    public function assignFoodPlan(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (int $accountId) use ($request): array {
            $body = (array) $request->get_json_params();

            return $this->trainer->assignFoodPlan(
                $accountId,
                (int) $request->get_param('userId'),
                (int) ($body['food_plan_id'] ?? 0),
                isset($body['start_date']) ? (string) $body['start_date'] : null
            );
        }, 201);
    }

    // ----------------------------------------------------------------- notes

    public function notes(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => [
            'items' => $this->trainer->notes($accountId, (int) $request->get_param('userId')),
        ]);
    }

    public function createNote(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (int $accountId) use ($request): array {
            $body = (array) $request->get_json_params();

            return $this->trainer->createNote(
                $accountId,
                (int) $request->get_param('userId'),
                (string) ($body['body'] ?? ''),
                (bool) ($body['pinned'] ?? false)
            );
        }, 201);
    }

    public function updateNote(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (int $accountId) use ($request): array {
            $body = (array) $request->get_json_params();

            $this->trainer->updateNote(
                $accountId,
                (int) $request->get_param('id'),
                array_key_exists('body', $body) ? (string) $body['body'] : null,
                array_key_exists('pinned', $body) ? (bool) $body['pinned'] : null
            );

            return ['ok' => true];
        });
    }

    public function deleteNote(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (int $accountId) use ($request): array {
            $this->trainer->deleteNote($accountId, (int) $request->get_param('id'));

            return ['ok' => true];
        });
    }

    // --------------------------------------------------------------- library

    public function workouts(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->library->workouts(
            $accountId,
            (bool) $request->get_param('mine')
        ));
    }

    public function workout(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->library->workout(
            $accountId,
            (int) $request->get_param('id')
        ));
    }

    public function createWorkout(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(
            fn(int $accountId): array => $this->library->createWorkout(
                $accountId,
                (array) $request->get_json_params()
            ),
            201
        );
    }

    public function updateWorkout(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->library->updateWorkout(
            $accountId,
            (int) $request->get_param('id'),
            (array) $request->get_json_params()
        ));
    }

    /**
     * PUT /trainer/workouts/{id}/exercises — the whole ordered list at once.
     */
    public function replaceExercises(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (int $accountId) use ($request): array {
            $body = (array) $request->get_json_params();

            return $this->library->replaceWorkoutExercises(
                $accountId,
                (int) $request->get_param('id'),
                (array) ($body['exercises'] ?? [])
            );
        });
    }

    public function deleteWorkout(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (int $accountId) use ($request): array {
            $this->library->deleteWorkout($accountId, (int) $request->get_param('id'));

            return ['ok' => true];
        });
    }

    public function plans(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);

        return $this->run(fn(int $accountId): array => $this->library->plans($accountId));
    }

    public function createPlan(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(
            fn(int $accountId): array => $this->library->createPlan(
                $accountId,
                (array) $request->get_json_params()
            ),
            201
        );
    }

    public function updatePlan(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (int $accountId) use ($request): array {
            $this->library->updatePlan(
                $accountId,
                (int) $request->get_param('id'),
                (array) $request->get_json_params()
            );

            return ['ok' => true];
        });
    }

    public function deletePlan(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (int $accountId) use ($request): array {
            $this->library->deletePlan($accountId, (int) $request->get_param('id'));

            return ['ok' => true];
        });
    }

    public function foodPlans(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);

        return $this->run(fn(int $accountId): array => $this->library->foodPlans($accountId));
    }

    public function createFoodPlan(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(
            fn(int $accountId): array => $this->library->createFoodPlan(
                $accountId,
                (array) $request->get_json_params()
            ),
            201
        );
    }

    public function updateFoodPlan(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (int $accountId) use ($request): array {
            $this->library->updateFoodPlan(
                $accountId,
                (int) $request->get_param('id'),
                (array) $request->get_json_params()
            );

            return ['ok' => true];
        });
    }

    public function deleteFoodPlan(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(function (int $accountId) use ($request): array {
            $this->library->deleteFoodPlan($accountId, (int) $request->get_param('id'));

            return ['ok' => true];
        });
    }

    // --------------------------------------------------------------- profile

    public function profile(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);

        return $this->run(fn(int $accountId): array => $this->library->profile($accountId));
    }

    public function updateProfile(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->run(fn(int $accountId): array => $this->library->updateProfile(
            $accountId,
            (array) $request->get_json_params()
        ));
    }

    // -------------------------------------------------------------- internals

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
     * A signed-in, active account holding `fc_manage_clients`.
     *
     * The capability is the door; the roster check inside each service method is
     * the lock on each room. Administrators hold this capability too, which is
     * deliberate — support staff need to see what a trainer sees — but they only
     * reach clients on a roster they are actually on.
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

        if (!$account->can('fc_manage_clients')) {
            return new WP_Error(
                'fc_forbidden',
                __('You are not allowed to do that.', 'fitnessclub'),
                ['status' => 403]
            );
        }

        return true;
    }
}
