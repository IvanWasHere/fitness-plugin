<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Support\ApiDocs;
use FitnessClub\Support\OpenApi;
use PHPUnit\Framework\TestCase;

/**
 * The generated API document (W4.6).
 *
 * Documentation rots because nothing fails when it does. These tests are that
 * failure: adding a route, renaming one, or changing a parameter schema without
 * regenerating `docs/openapi.json` all turn red here rather than being noticed
 * months later by somebody building against the wrong thing.
 *
 * That matters more since D11 — the REST API is a contract third-party front-end
 * themes compile against, so "the docs are a bit behind" is now somebody else's
 * broken app.
 *
 * @covers \FitnessClub\Support\OpenApi
 * @covers \FitnessClub\Support\ApiDocs
 */
final class OpenApiTest extends TestCase
{
    private const COMMITTED = __DIR__ . '/../../docs/openapi.json';

    /** @var array<string,mixed>|null */
    private static ?array $spec = null;

    /**
     * @return array<string,mixed>
     */
    private function spec(): array
    {
        return self::$spec ??= OpenApi::generate();
    }

    // -------------------------------------------------------------- coverage

    public function testEveryRegisteredRouteIsDocumented(): void
    {
        $undocumented = OpenApi::undocumented();

        $this->assertSame(
            [],
            $undocumented,
            "These routes have no entry in ApiDocs:\n  " . implode("\n  ", $undocumented)
        );
    }

    public function testNoDocumentationDescribesARouteThatNoLongerExists(): void
    {
        $orphaned = OpenApi::orphanedDocs();

        // The quiet direction: a renamed endpoint leaves its old description
        // behind, documenting something nobody can call.
        $this->assertSame(
            [],
            $orphaned,
            "ApiDocs describes routes that are not registered:\n  " . implode("\n  ", $orphaned)
        );
    }

    public function testTheCommittedDocumentIsUpToDateWithTheRoutes(): void
    {
        $this->assertFileExists(self::COMMITTED, 'Run: wp fitnessclub openapi');

        $committed = json_decode((string) file_get_contents(self::COMMITTED), true);

        $this->assertIsArray($committed);

        // Compare the contract, not the whole file: `info.version` moves with the
        // plugin version and `servers[0].url` is whatever host generated it, so
        // asserting on those would fail on a version bump or on a colleague's
        // machine for no reason anyone could act on.
        $this->assertSame(
            $this->spec()['paths'],
            $committed['paths'],
            'docs/openapi.json is out of date. Run: wp fitnessclub openapi'
        );
        $this->assertSame($this->spec()['components'], $committed['components']);
        $this->assertSame($this->spec()['tags'], $committed['tags']);
    }

    // ------------------------------------------------------------- structure

    public function testEveryOperationIsRenderable(): void
    {
        foreach ($this->operations() as $key => $operation) {
            $this->assertArrayHasKey('summary', $operation, "{$key} has no summary");
            $this->assertNotSame('', trim((string) $operation['summary']), "{$key} has an empty summary");
            $this->assertArrayHasKey('operationId', $operation, "{$key} has no operationId");
            $this->assertNotEmpty($operation['tags'], "{$key} has no tag");
            $this->assertArrayHasKey('responses', $operation, "{$key} documents no responses");
        }
    }

    public function testOperationIdsAreUnique(): void
    {
        $ids = array_column(array_values($this->operations()), 'operationId');

        $this->assertSame(
            array_unique($ids),
            $ids,
            'Duplicate operationId — client generators emit one method per id, so a collision silently '
            . 'drops an endpoint.'
        );
    }

    public function testEveryTagUsedIsDeclared(): void
    {
        $declared = array_column(ApiDocs::tags(), 'name');

        foreach ($this->operations() as $key => $operation) {
            foreach ($operation['tags'] as $tag) {
                $this->assertContains($tag, $declared, "{$key} uses an undeclared tag '{$tag}'");
            }
        }
    }

    public function testEveryPathPlaceholderIsADeclaredParameter(): void
    {
        foreach ($this->spec()['paths'] as $path => $methods) {
            preg_match_all('#\{(\w+)\}#', (string) $path, $matches);
            $placeholders = $matches[1];

            foreach ($methods as $method => $operation) {
                $declared = array_column(
                    array_filter(
                        $operation['parameters'] ?? [],
                        static fn(array $p): bool => 'path' === $p['in']
                    ),
                    'name'
                );

                foreach ($placeholders as $placeholder) {
                    $this->assertContains(
                        $placeholder,
                        $declared,
                        strtoupper((string) $method) . " {$path}: {$placeholder} is in the URL but not "
                        . 'declared as a path parameter'
                    );
                }
            }
        }
    }

    public function testEveryReferencedSchemaExists(): void
    {
        $schemas = array_keys($this->spec()['components']['schemas']);

        foreach ($this->operations() as $key => $operation) {
            foreach ($operation['responses'] as $status => $response) {
                $ref = $response['content']['application/json']['schema']['$ref'] ?? null;

                if (null === $ref) {
                    continue;
                }

                $this->assertContains(
                    basename((string) $ref),
                    $schemas,
                    "{$key} response {$status} references a schema that does not exist: {$ref}"
                );
            }
        }
    }

    // ------------------------------------------------------------------ leaks

    public function testNoInternalCallbackNamesLeakIntoTheDocument(): void
    {
        $json = (string) wp_json_encode($this->spec());

        // WordPress arg schemas carry PHP callables. They are validation
        // machinery, they mean nothing to a client, and publishing them would
        // put internal function names in a public document.
        $this->assertStringNotContainsString('sanitize_callback', $json);
        $this->assertStringNotContainsString('validate_callback', $json);
        $this->assertStringNotContainsString('sanitize_text_field', $json);
        $this->assertStringNotContainsString('FitnessClub\\\\Http', $json);
    }

    public function testWordPressOwnNamespaceIndexIsNotDocumented(): void
    {
        // `/fitnessclub/v1` is synthesised by core, has no permission callback,
        // and is not part of this plugin's contract.
        $this->assertArrayNotHasKey('/', $this->spec()['paths']);
        $this->assertArrayNotHasKey('', $this->spec()['paths']);
    }

    // ------------------------------------------------------------- semantics

    public function testPublicRoutesCarryNoSecurityRequirement(): void
    {
        // Exactly three routes are public, each for a reason recorded in the
        // plans: liveness, a reset key that *is* the authorisation, and a
        // webhook protected by signature verification instead.
        foreach (['get /health', 'post /auth/password/reset'] as $key) {
            [$method, $path] = explode(' ', $key);

            $this->assertArrayNotHasKey(
                'security',
                $this->spec()['paths'][$path][$method],
                "{$key} should be documented as public"
            );
        }
    }

    public function testAuthenticatedRoutesRequireBothCookieAndCsrf(): void
    {
        $operation = $this->spec()['paths']['/sessions']['post'];

        $this->assertSame(
            [['sessionCookie' => [], 'csrfToken' => []]],
            $operation['security'],
            'A write endpoint needs the session cookie *and* the CSRF header — documenting only the '
            . 'cookie would tell a client to build something that 403s.'
        );
    }

    public function testWriteMethodsPutParametersInTheBodyAndReadsInTheQuery(): void
    {
        $post = $this->spec()['paths']['/sessions']['post'];
        $get  = $this->spec()['paths']['/workouts']['get'];

        $this->assertArrayHasKey('requestBody', $post, 'POST parameters belong in a JSON body');
        $this->assertArrayNotHasKey('requestBody', $get, 'GET parameters belong in the query string');
        $this->assertNotEmpty($get['parameters']);
    }

    public function testPathParametersAreRequiredEvenWhenTheArgSchemaIsSilent(): void
    {
        $parameters = $this->spec()['paths']['/workouts/{id}']['get']['parameters'];
        $id         = null;

        foreach ($parameters as $parameter) {
            if ('id' === $parameter['name']) {
                $id = $parameter;
            }
        }

        $this->assertNotNull($id);
        $this->assertSame('path', $id['in']);
        // A path parameter is part of the URL and cannot be optional, whatever
        // the arg schema happens to say.
        $this->assertTrue($id['required']);
    }

    public function testGeneratedSchemasCarryTheEnumsTheApiActuallyEnforces(): void
    {
        $parameters = $this->spec()['paths']['/workouts']['get']['parameters'];
        $difficulty = null;

        foreach ($parameters as $parameter) {
            if ('difficulty' === $parameter['name']) {
                $difficulty = $parameter;
            }
        }

        $this->assertNotNull($difficulty, 'The generator should surface every registered query parameter');
        $this->assertSame(
            ['beginner', 'intermediate', 'advanced'],
            $difficulty['schema']['enum'],
            'Enums come from the route schema, so the document cannot describe a value the API rejects.'
        );
    }

    public function testTheBootPayloadIsModelledBecauseThemesCompileAgainstIt(): void
    {
        $boot = $this->spec()['components']['schemas']['Boot'];

        $this->assertSame('object', $boot['type']);

        foreach (['user', 'app', 'counts', 'entitlements', 'restUrl', 'csrf'] as $field) {
            $this->assertArrayHasKey($field, $boot['properties'], "Boot payload should document {$field}");
        }

        // Login and /auth/me both answer with it — a theme reads one shape.
        $this->assertSame(
            '#/components/schemas/Boot',
            $this->spec()['paths']['/auth/me']['get']['responses']['200']['content']['application/json']['schema']['$ref']
        );
    }

    // -------------------------------------------------------------- internals

    /**
     * @return array<string,array<string,mixed>>
     */
    private function operations(): array
    {
        $out = [];

        foreach ($this->spec()['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $out[strtoupper((string) $method) . ' ' . $path] = $operation;
            }
        }

        return $out;
    }
}
