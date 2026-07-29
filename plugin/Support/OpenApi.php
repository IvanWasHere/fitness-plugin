<?php

namespace FitnessClub\Support;

use FitnessClub\Auth\Csrf;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * OpenAPI 3.1 generated from the live route registry (W4.6).
 *
 * ## Why generated, and generated from *WordPress* rather than from the source
 *
 * The REST API stopped being an internal detail when
 * [D11](../../plans/00-architecture.md) made front-end themes possible: third-party
 * apps now compile against it. A hand-written spec for 118 endpoints is wrong
 * within a week and nobody finds out until somebody builds against the wrong
 * thing.
 *
 * So this reads `rest_get_server()->get_routes()` — the same registry WordPress
 * dispatches from. Not a parse of `routes.php`, which would be a second
 * interpretation of the file and could disagree with the one that matters; and
 * not a static list, which would be a third. Every type, enum, bound, default and
 * required flag in the output came from the schema the request validator actually
 * enforces, so the spec cannot describe a parameter the API does not have.
 *
 * ## What is *not* derived, and how that is kept honest
 *
 * Prose — what an endpoint is *for* — exists nowhere in the registry, so it lives
 * in {@see ApiDocs}. That is a hand-maintained list, and a hand-maintained list
 * next to a generated one goes stale silently. It does not here: a route with no
 * entry is reported by {@see undocumented()}, the CLI command refuses to write a
 * spec while any exist, and a test asserts the set is empty. Adding a route
 * therefore breaks the build until it is documented, which is the only mechanism
 * that has ever kept API docs current.
 */
final class OpenApi
{
    public const OPENAPI_VERSION = '3.1.0';

    public const REST_NAMESPACE = 'fitnessclub/v1';

    /** Methods whose non-path parameters travel in a JSON body rather than the query string. */
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    /**
     * Keys WordPress uses for validation that mean nothing to a consumer.
     *
     * `sanitize_callback` and `validate_callback` are PHP callables — emitting
     * them would leak internal function names into a public document and tell a
     * client nothing it can act on.
     */
    private const INTERNAL_ARG_KEYS = ['sanitize_callback', 'validate_callback', 'required'];

    /**
     * The whole document.
     *
     * @return array<string,mixed>
     */
    public static function generate(): array
    {
        $paths = [];

        foreach (self::routes() as $route => $handlers) {
            $path = self::templatePath($route);

            foreach ($handlers as $handler) {
                foreach (self::methodsFor($handler) as $method) {
                    $operation = self::operation($method, $path, $route, $handler);

                    if (null !== $operation) {
                        $paths[$path][strtolower($method)] = $operation;
                    }
                }
            }
        }

        ksort($paths);

        return [
            'openapi' => self::OPENAPI_VERSION,
            'info'    => self::info(),
            'servers' => [[
                'url'         => rest_url(self::REST_NAMESPACE),
                'description' => 'This site',
            ]],
            'tags'       => ApiDocs::tags(),
            'paths'      => $paths,
            'components' => self::components(),
        ];
    }

    /**
     * Routes registered but not described in {@see ApiDocs}.
     *
     * The build gate. Returns `["POST /sessions", …]`, empty when the docs are
     * complete.
     *
     * @return string[]
     */
    public static function undocumented(): array
    {
        $missing = [];

        foreach (self::routes() as $route => $handlers) {
            $path = self::templatePath($route);

            foreach ($handlers as $handler) {
                foreach (self::methodsFor($handler) as $method) {
                    if (null === ApiDocs::describe($method, $path)) {
                        $missing[] = "{$method} {$path}";
                    }
                }
            }
        }

        sort($missing);

        return $missing;
    }

    /**
     * Documented routes that no longer exist.
     *
     * The other direction, and the one that rots quietly: a renamed endpoint
     * leaves its old description behind, describing something nobody can call.
     *
     * @return string[]
     */
    public static function orphanedDocs(): array
    {
        $live = [];

        foreach (self::routes() as $route => $handlers) {
            $path = self::templatePath($route);

            foreach ($handlers as $handler) {
                foreach (self::methodsFor($handler) as $method) {
                    $live[] = "{$method} {$path}";
                }
            }
        }

        $orphans = array_values(array_diff(array_keys(ApiDocs::all()), $live));
        sort($orphans);

        return $orphans;
    }

    // ------------------------------------------------------------- internals

    /**
     * @return array<string,array<int,array<string,mixed>>>
     */
    private static function routes(): array
    {
        $routes = rest_get_server()->get_routes(self::REST_NAMESPACE);

        // WordPress synthesises an index route for the namespace itself. It is
        // core's, not part of this plugin's contract, and it has no permission
        // callback — which is why it shows up as an anomaly if left in.
        unset($routes['/' . self::REST_NAMESPACE]);

        return $routes;
    }

    /**
     * @param array<string,mixed> $handler
     * @return string[]
     */
    private static function methodsFor(array $handler): array
    {
        $methods = array_keys(array_filter((array) ($handler['methods'] ?? [])));

        // HEAD is registered alongside GET by WordPress and documents nothing
        // extra; OPTIONS is the CORS preflight, which is transport, not contract.
        return array_values(array_diff($methods, ['HEAD', 'OPTIONS']));
    }

    /**
     * @param array<string,mixed> $handler
     * @return array<string,mixed>|null
     */
    private static function operation(string $method, string $path, string $route, array $handler): ?array
    {
        $described = ApiDocs::describe($method, $path);

        if (null === $described) {
            // Undocumented routes are omitted rather than emitted blank. The CLI
            // refuses to write at all while any exist, so this is unreachable in
            // a generated artefact — it matters only for a caller that ignores
            // the gate.
            return null;
        }

        $args       = (array) ($handler['args'] ?? []);
        $pathParams = self::pathParamNames($route);
        $auth       = ApiDocs::auth(self::permissionName($handler));

        $operation = [
            'summary'     => $described['summary'],
            'operationId' => self::operationId($method, $path),
            'tags'        => [$described['tag']],
        ];

        $description = trim(($described['description'] ?? '') . "\n\n" . $auth['description']);

        if ('' !== $description) {
            $operation['description'] = $description;
        }

        $parameters = [];
        $bodyProps  = [];
        $required   = [];
        $inBody     = in_array($method, self::BODY_METHODS, true);

        foreach ($args as $name => $schema) {
            $isPath = in_array($name, $pathParams, true);

            if (!$isPath && $inBody) {
                $bodyProps[$name] = self::schemaFor($schema);

                if (!empty($schema['required'])) {
                    $required[] = $name;
                }

                continue;
            }

            $parameters[] = array_filter([
                'name'        => $name,
                'in'          => $isPath ? 'path' : 'query',
                // A path parameter is required by definition — it is part of the
                // URL. WordPress does not always mark it so in the arg schema.
                'required'    => $isPath ? true : !empty($schema['required']),
                'description' => $schema['description'] ?? null,
                'schema'      => self::schemaFor($schema),
            ], static fn($value): bool => null !== $value && false !== $value);
        }

        if ([] !== $parameters) {
            $operation['parameters'] = $parameters;
        }

        if ([] !== $bodyProps) {
            $operation['requestBody'] = [
                'required' => [] !== $required,
                'content'  => [
                    'application/json' => [
                        'schema' => array_filter([
                            'type'       => 'object',
                            'properties' => $bodyProps,
                            'required'   => [] === $required ? null : $required,
                        ], static fn($value): bool => null !== $value),
                    ],
                ],
            ];
        }

        if ([] !== $auth['security']) {
            $operation['security'] = $auth['security'];
        }

        $operation['responses'] = self::responses($method, $described, $auth);

        return $operation;
    }

    /**
     * @param array<string,mixed> $described
     * @param array<string,mixed> $auth
     * @return array<string,mixed>
     */
    private static function responses(string $method, array $described, array $auth): array
    {
        $successCode = 'POST' === $method ? '201' : '200';
        $successCode = $described['status'] ?? $successCode;

        $schema = isset($described['response'])
            ? ['$ref' => '#/components/schemas/' . $described['response']]
            : ['type' => 'object'];

        $responses = [
            (string) $successCode => [
                'description' => $described['returns'] ?? 'Success.',
                'content'     => ['application/json' => ['schema' => $schema]],
            ],
            '400' => self::errorResponse('The request failed validation.'),
        ];

        if ([] !== $auth['security']) {
            $responses['401'] = self::errorResponse('No session, or the session has expired.');
            $responses['403'] = self::errorResponse('Signed in, but not allowed to do this.');
        }

        $responses['429'] = self::errorResponse(
            'Rate limited. `retry_after` and the `Retry-After` header say how long to wait.'
        );

        return $responses;
    }

    /**
     * @return array<string,mixed>
     */
    private static function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content'     => [
                'application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']],
            ],
        ];
    }

    /**
     * A WordPress arg schema, reduced to the parts that describe the value.
     *
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     */
    private static function schemaFor(array $schema): array
    {
        $out = array_diff_key($schema, array_flip(self::INTERNAL_ARG_KEYS));

        unset($out['description']);

        // WordPress writes a union as `['integer', 'null']`, which OpenAPI 3.1
        // accepts verbatim — one of the reasons this targets 3.1 rather than 3.0,
        // where a nullable union needs restating as `nullable: true`.
        if ([] === $out) {
            $out = ['type' => 'string'];
        }

        return $out;
    }

    /**
     * Parameter names embedded in the route regex, e.g. `(?P<id>\d+)` → `id`.
     *
     * @return string[]
     */
    private static function pathParamNames(string $route): array
    {
        preg_match_all('#\(\?P<(\w+)>#', $route, $matches);

        return $matches[1];
    }

    /** `/fitnessclub/v1/workouts/(?P<id>\d+)` → `/workouts/{id}` */
    private static function templatePath(string $route): string
    {
        $path = preg_replace('#\(\?P<(\w+)>[^)]*\)#', '{$1}', $route) ?? $route;
        $path = (string) preg_replace('#^/' . preg_quote(self::REST_NAMESPACE, '#') . '#', '', $path);

        return '' === $path ? '/' : $path;
    }

    /** `GET /sessions/{id}/sets` → `getSessionsIdSets` */
    private static function operationId(string $method, string $path): string
    {
        $parts = preg_split('#[/{}\-]+#', trim($path, '/')) ?: [];
        $parts = array_values(array_filter($parts));

        return strtolower($method) . implode('', array_map('ucfirst', $parts));
    }

    /**
     * @param array<string,mixed> $handler
     */
    private static function permissionName(array $handler): string
    {
        $callback = $handler['permission_callback'] ?? null;

        if (is_array($callback)) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];

            return str_replace('FitnessClub\\Http\\Controllers\\Api\\', '', $class) . '::' . $callback[1];
        }

        return is_string($callback) ? $callback : 'unknown';
    }

    /**
     * @return array<string,mixed>
     */
    private static function info(): array
    {
        return [
            'title'   => 'FitnessClub REST API',
            'version' => defined('FITNESSCLUB_VERSION') ? FITNESSCLUB_VERSION : '0.0.0',
            'summary' => 'Workout, nutrition, health, coaching and billing for the FitnessClub plugin.',
            'description' => trim('
This document is **generated from the live route registry**, not written by hand —
every type, enum, bound and required flag below came from the schema the request
validator actually enforces.

## Authentication

The plugin owns its accounts. A WordPress login is not a FitnessClub login, and a
WordPress administrator with no FitnessClub account is anonymous to this API.

Two things authenticate a request, and the front end receives both in its boot
payload:

- the session **cookie**, sent automatically for same-origin requests;
- the **`' . Csrf::HEADER . '`** header, required on every method except GET, HEAD
  and OPTIONS.

The CSRF token is bound to the session and expires with it, so there is no refresh
step: a rejected token means the session is gone and the correct response is to
show the sign-in screen.

## Errors

Every failure is a WordPress `WP_Error` envelope with a stable, namespaced code.
**Branch on `code`, never on `message`** — messages are translated and may change.

## Pagination

Every list endpoint takes `page` and `per_page` (default 20, maximum 100) and
returns `X-WP-Total` and `X-WP-TotalPages` headers.

## Response bodies

Request schemas are exact, because they are generated. **Response schemas are
modelled only where the shape is contract-critical** — the boot payload and the
error envelope. Everywhere else a response is described as a generic object rather
than being guessed at; an inaccurate schema is worse than an absent one.
            '),
            'license' => [
                'name' => 'GPL-2.0-or-later',
                'url'  => 'https://www.gnu.org/licenses/gpl-2.0.html',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function components(): array
    {
        return [
            'securitySchemes' => [
                'sessionCookie' => [
                    'type'        => 'apiKey',
                    'in'          => 'cookie',
                    'name'        => 'fc_session_*',
                    'description' => 'The FitnessClub session cookie, set on sign-in. '
                        . 'Sent automatically for same-origin requests.',
                ],
                'csrfToken' => [
                    'type'        => 'apiKey',
                    'in'          => 'header',
                    'name'        => Csrf::HEADER,
                    'description' => 'Required on every method except GET, HEAD and OPTIONS. '
                        . 'Delivered in the boot payload as `csrf`.',
                ],
            ],
            'schemas' => [
                'Error' => [
                    'type'        => 'object',
                    'description' => 'The WordPress `WP_Error` envelope. Branch on `code`.',
                    'properties'  => [
                        'code'    => [
                            'type'        => 'string',
                            'description' => 'Stable, namespaced identifier, e.g. `fc_not_authenticated`.',
                            'examples'    => ['fc_not_authenticated', 'fc_forbidden', 'fc_invalid_credentials'],
                        ],
                        'message' => [
                            'type'        => 'string',
                            'description' => 'Human-readable and translated. Never branch on this.',
                        ],
                        'data'    => [
                            'type'       => 'object',
                            'properties' => [
                                'status'      => ['type' => 'integer'],
                                'retry_after' => [
                                    'type'        => ['integer', 'null'],
                                    'description' => 'Seconds to wait, on a 429.',
                                ],
                            ],
                        ],
                    ],
                    'required' => ['code', 'message'],
                ],
                'Boot' => self::bootSchema(),
            ],
        ];
    }

    /**
     * The boot payload — the contract a front-end theme compiles against (D11).
     *
     * Modelled by hand and deliberately so: it is assembled by `BootPresenter`
     * rather than by a route schema, so there is nothing to generate it from.
     * `BootPayloadTest` is what keeps it honest.
     *
     * @return array<string,mixed>
     */
    private static function bootSchema(): array
    {
        return [
            'type'        => 'object',
            'description' => 'Everything a front end needs for its first frame. Delivered two ways: '
                . 'as the `data-boot` attribute on `#fc-app` in the rendered shell, and as the body of '
                . '`GET /auth/me` (which omits the transport-only keys `restUrl`, `csrf`, `brand`, '
                . '`locale` and `flags`). Login and register return the full object, so signing in '
                . 'needs no second request.',
            'properties' => [
                'user' => [
                    'type'        => ['object', 'null'],
                    'description' => 'Null when nobody is signed in.',
                    'properties'  => [
                        'id'           => ['type' => 'integer', 'description' => 'fc_users.id — the member profile.'],
                        'account_id'   => [
                            'type'        => 'integer',
                            'description' => 'fc_accounts.id — the identity anchor.',
                        ],
                        'display_name' => ['type' => 'string'],
                        'email'        => ['type' => 'string'],
                        'avatar_url'   => ['type' => ['string', 'null']],
                        'role'         => ['type' => 'string', 'enum' => ['user', 'trainer', 'admin']],
                        'timezone'     => ['type' => ['string', 'null']],
                        'onboarded'    => ['type' => 'boolean'],
                        'trainer_id'   => [
                            'type'        => ['integer', 'null'],
                            'description' => 'Present for trainers and admins.',
                        ],
                    ],
                ],
                'subscriptions' => [
                    'type'        => 'array',
                    'description' => 'A member holds one subscription per trainer, plus the platform tier.',
                    'items'       => ['type' => 'object'],
                ],
                'trainers' => [
                    'type'        => 'array',
                    'description' => 'The member\'s coaches. A member may have several.',
                    'items'       => ['type' => 'object'],
                ],
                'entitlements' => [
                    'type'        => 'object',
                    'description' => 'Merged across every active subscription: booleans union, caps take the '
                        . 'maximum (never the sum), null beats any number.',
                ],
                'theme' => [
                    'type'        => 'object',
                    'description' => 'Colour, typography and layout tokens, also emitted as `--fc-*` custom '
                        . 'properties on `#fc-app`. A front-end theme may use them or ignore them.',
                ],
                'app' => [
                    'type'       => 'object',
                    'properties' => [
                        'base'     => [
                            'type'        => 'string',
                            'description' => 'The configurable front-end slug. Never hardcode it.',
                        ],
                        'basename' => ['type' => 'string', 'description' => 'Router basename.'],
                        'spa'      => [
                            'type'        => 'string',
                            'enum'        => ['user', 'trainer', 'admin'],
                            'description' => 'Which app the server resolved for this role.',
                        ],
                    ],
                ],
                'counts' => [
                    'type'       => 'object',
                    'properties' => [
                        'unread_messages'      => ['type' => 'integer'],
                        'unread_notifications' => ['type' => 'integer'],
                    ],
                ],
                'restUrl' => [
                    'type'        => 'string',
                    'description' => 'Shell only. REST root for this site.',
                ],
                'csrf' => [
                    'type'        => 'string',
                    'description' => 'Shell only. Send as `' . Csrf::HEADER . '` on every non-GET.',
                ],
                'brand'  => ['type' => 'string', 'description' => 'Shell only. The configured brand name.'],
                'locale' => ['type' => 'string', 'description' => 'Shell only.'],
                'flags'  => [
                    'type'        => 'object',
                    'description' => 'Shell only. Feature switches, e.g. `registration_open`.',
                ],
            ],
            'required' => ['user', 'app', 'counts'],
        ];
    }
}
