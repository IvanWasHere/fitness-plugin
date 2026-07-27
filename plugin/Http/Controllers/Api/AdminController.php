<?php

namespace FitnessClub\Http\Controllers\Api;

use FitnessClub\Auth\Auth;
use FitnessClub\Services\Admin\AdminDashboardService;
use FitnessClub\Services\Admin\AdminResourceService;
use FitnessClub\Services\Admin\AdminSettingsService;
use FitnessClub\Services\Admin\ResourceRegistry;
use FitnessClub\Support\DomainException;
use FitnessClub\WPBones\Routing\API\RestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * `/admin/*` (plans/02-api-contract.md#admin, W2.5).
 *
 * One controller for every resource, because the resources differ only in the
 * declarative config that {@see ResourceRegistry} holds — the same idea as the
 * prototype's generic `CrudTable`, which is the one piece of it worth keeping.
 *
 * ## Capabilities are the plugin's, not WordPress'
 *
 * The API contract says these routes need `manage_options`. That predates the
 * W1.2R/W1.3R conversion to plugin-owned identity: `current_user_can()` now
 * answers for a wp-admin session that has nothing to do with this app, and a
 * WordPress administrator with no `fc_accounts` row is anonymous here by design.
 * So each resource names the `fc_*` capability it needs and the registry decides
 * which — `fc_manage_users` for members, `fc_edit_user_health` for the two Q10
 * resources, and so on.
 *
 * ## No ownership scoping, deliberately
 *
 * Every other controller scopes to the caller. These do not: an administrator
 * acting on another account's data is the entire point. What replaces the
 * ownership check is the **audit trail** — every write to an audited resource
 * records actor, subject and before/after (Q10).
 */
final class AdminController extends RestController
{
    private AdminResourceService $resources;

    public function __construct($request, $vendor)
    {
        parent::__construct($request, $vendor);

        $this->resources = new AdminResourceService();
    }

    /**
     * GET /admin/{resource}
     */
    public function index(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asAdmin($request, function (string $resource) use ($request) {
            $result = $this->resources->index($resource, $this->queryParams($request, $resource));

            $response = $this->response($result);
            $response->header('X-WP-Total', (string) $result['total']);
            $response->header(
                'X-WP-TotalPages',
                (string) (int) ceil($result['total'] / max(1, $result['per_page']))
            );

            return $response;
        });
    }

    /**
     * GET /admin/{resource}/{id}
     */
    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asAdmin(
            $request,
            fn(string $resource): array => $this->resources->show($resource, (int) $request->get_param('id'))
        );
    }

    /**
     * POST /admin/{resource}
     */
    public function store(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asAdmin($request, function (string $resource) use ($request) {
            $created = $this->resources->create(
                $resource,
                (array) $request->get_json_params(),
                Auth::accountId()
            );

            return $this->response($created, 201);
        });
    }

    /**
     * PUT /admin/{resource}/{id}
     */
    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asAdmin(
            $request,
            fn(string $resource): array => $this->resources->update(
                $resource,
                (int) $request->get_param('id'),
                (array) $request->get_json_params(),
                Auth::accountId()
            )
        );
    }

    /**
     * DELETE /admin/{resource}/{id}
     */
    public function destroy(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->asAdmin($request, function (string $resource) use ($request): array {
            $this->resources->delete($resource, (int) $request->get_param('id'), Auth::accountId());

            return ['ok' => true];
        });
    }

    /**
     * GET /admin/dashboard
     */
    public function dashboard(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);

        $refusal = self::requireAdmin('fc_manage_all');

        if ($refusal instanceof WP_Error) {
            return $refusal;
        }

        return $this->response((new AdminDashboardService())->build());
    }

    /**
     * GET /admin/settings
     */
    public function settings(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);

        $refusal = self::requireAdmin('fc_manage_settings');

        if ($refusal instanceof WP_Error) {
            return $refusal;
        }

        return $this->response((new AdminSettingsService())->all());
    }

    /**
     * PUT /admin/settings
     */
    public function updateSettings(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $refusal = self::requireAdmin('fc_manage_settings');

        if ($refusal instanceof WP_Error) {
            return $refusal;
        }

        try {
            return $this->response(
                (new AdminSettingsService())->update((array) $request->get_json_params())
            );
        } catch (DomainException $e) {
            return $e->toWpError();
        }
    }

    /**
     * The resource list, so the SPA's navigation is built from the server's own
     * registry rather than a second hardcoded list that can drift from it.
     */
    public function resources(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        unset($request);

        $refusal = self::requireAdmin('fc_manage_all');

        if ($refusal instanceof WP_Error) {
            return $refusal;
        }

        $out = [];

        foreach (ResourceRegistry::all() as $name => $config) {
            $out[] = [
                'name'       => $name,
                'creatable'  => (bool) ($config['creatable'] ?? true),
                'audited'    => (bool) ($config['audit'] ?? false),
                'sorts'      => array_keys($config['sort']),
                'filters'    => array_keys($config['filters'] ?? []),
                'writable'   => $config['writable'],
                'required'   => $config['required'] ?? [],
            ];
        }

        return $this->response(['items' => $out]);
    }

    // ------------------------------------------------------------- internals

    /**
     * Resolve `{resource}`, check the capability it declares, run the action.
     *
     * The capability comes from the registry rather than from one blanket admin
     * gate, so a role granted `fc_edit_user_health` and nothing else can reach
     * exactly the two resources that need it.
     *
     * @param callable(string): (array<string,mixed>|WP_REST_Response|WP_Error) $action
     */
    private function asAdmin(WP_REST_Request $request, callable $action): WP_REST_Response|WP_Error
    {
        $resource = (string) $request->get_param('resource');
        $config   = ResourceRegistry::get($resource);

        if (null === $config) {
            return $this->responseError(
                'fc_unknown_resource',
                __('There is no such resource.', 'fitnessclub'),
                404
            );
        }

        $refusal = self::requireAdmin($config['capability']);

        if ($refusal instanceof WP_Error) {
            return $refusal;
        }

        try {
            $result = $action($resource);
        } catch (DomainException $e) {
            return $e->toWpError();
        }

        if ($result instanceof WP_REST_Response || $result instanceof WP_Error) {
            return $result;
        }

        return $this->response($result);
    }

    /**
     * Everything the list query understands, drawn from the registry so a filter
     * added there needs no controller change.
     *
     * @return array<string,mixed>
     */
    private function queryParams(WP_REST_Request $request, string $resource): array
    {
        $config = ResourceRegistry::get($resource) ?? [];
        $query  = [
            'page'     => $request->get_param('page'),
            'per_page' => $request->get_param('per_page'),
            'q'        => $request->get_param('q'),
            'sort'     => $request->get_param('sort'),
            'order'    => $request->get_param('order'),
        ];

        foreach (array_keys($config['filters'] ?? []) as $filter) {
            $query[$filter] = $request->get_param($filter);
        }

        return $query;
    }

    /**
     * A signed-in, active plugin account holding the capability.
     *
     * Not `current_user_can()`: the authority here is Auth\Capabilities. A
     * WordPress administrator without an `fc_accounts` row is anonymous to this
     * API, which is the property the identity conversion was for.
     */
    public static function requireAdmin(string $capability): bool|WP_Error
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

        if (!$account->can($capability)) {
            return new WP_Error(
                'fc_forbidden',
                __('You are not allowed to do that.', 'fitnessclub'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Route-level gate. The per-resource capability is applied inside, because
     * the resource is a path segment and is not known until dispatch.
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

        return true;
    }
}
