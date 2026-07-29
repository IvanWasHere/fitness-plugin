<?php

namespace FitnessClub\Cli;

use FitnessClub\Support\OpenApi;
use WP_CLI;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Write the OpenAPI document — `wp fitnessclub openapi`.
 *
 * WP-CLI rather than `php bones`: the spec is generated from the **live route
 * registry**, which only exists once WordPress has booted and `rest_api_init`
 * has fired. Bones does not bootstrap WordPress for custom commands.
 *
 * ## It refuses to write an incomplete document
 *
 * A partially-documented API reads as a fully-documented one — an endpoint that
 * is simply absent looks like an endpoint that does not exist, which is worse
 * than an obvious gap. So an undocumented route is an **error**, not a warning,
 * and adding a route breaks this command until {@see \FitnessClub\Support\ApiDocs}
 * describes it. `--allow-incomplete` exists for inspecting the output mid-work
 * and is deliberately not the default.
 *
 * ## EXAMPLES
 *
 *     wp fitnessclub openapi                          # write docs/openapi.json
 *     wp fitnessclub openapi --output=/tmp/spec.json
 *     wp fitnessclub openapi --print
 *     wp fitnessclub openapi --check                  # verify only; exit non-zero if stale
 */
class OpenApiCommand
{
    private const DEFAULT_OUTPUT = 'docs/openapi.json';

    /**
     * @param array<int,string>    $args
     * @param array<string,string> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        unset($args);

        $allowIncomplete = isset($assoc['allow-incomplete']);
        $complete        = $this->reportCoverage($allowIncomplete);

        if (!$complete && !$allowIncomplete) {
            WP_CLI::error(
                'Refusing to write an incomplete document. Describe the routes above in '
                . 'plugin/Support/ApiDocs.php, or pass --allow-incomplete to inspect the output anyway.'
            );
        }

        $spec = OpenApi::generate();
        $json = (string) wp_json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (isset($assoc['print'])) {
            WP_CLI::line($json);

            return;
        }

        $path = $this->resolvePath($assoc['output'] ?? self::DEFAULT_OUTPUT);

        if (isset($assoc['check'])) {
            $this->check($path, $json);

            return;
        }

        $this->write($path, $json);

        WP_CLI::success(sprintf(
            'Wrote %s — %d paths, %d operations.',
            $path,
            count($spec['paths']),
            $this->countOperations($spec)
        ));
    }

    /**
     * Report both directions of drift. Returns true when the docs are complete.
     */
    private function reportCoverage(bool $lenient): bool
    {
        $undocumented = OpenApi::undocumented();
        $orphaned     = OpenApi::orphanedDocs();

        foreach ($undocumented as $route) {
            WP_CLI::warning("Undocumented route: {$route}");
        }

        // An orphan never blocks the write — it describes something that is
        // already gone, so the document is accurate without it. It is still
        // worth saying, because it is usually the residue of a rename and the
        // new name is probably in the undocumented list right above.
        foreach ($orphaned as $route) {
            WP_CLI::warning("Documented but no longer registered: {$route}");
        }

        if ([] === $undocumented && [] === $orphaned && !$lenient) {
            WP_CLI::log('Documentation covers every registered route.');
        }

        return [] === $undocumented;
    }

    /**
     * Verify the committed document matches what the code would generate.
     *
     * This is the CI shape: a spec is only trustworthy if it is regenerated when
     * the routes change, and the only way to know is to compare.
     */
    private function check(string $path, string $expected): void
    {
        if (!is_readable($path)) {
            WP_CLI::error("No document at {$path}. Run: wp fitnessclub openapi");
        }

        $actual = (string) file_get_contents($path);

        if (trim($actual) !== trim($expected)) {
            WP_CLI::error(
                "{$path} is out of date with the routes. Run: wp fitnessclub openapi"
            );
        }

        WP_CLI::success("{$path} is up to date.");
    }

    private function write(string $path, string $json): void
    {
        $dir = dirname($path);

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            WP_CLI::error("Could not create {$dir}");
        }

        // Trailing newline: a file without one is a diff that touches its last
        // line on every regeneration.
        if (false === file_put_contents($path, $json . "\n")) {
            WP_CLI::error("Could not write {$path}");
        }
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim(FitnessClub()->basePath, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @param array<string,mixed> $spec
     */
    private function countOperations(array $spec): int
    {
        $count = 0;

        foreach ((array) $spec['paths'] as $operations) {
            $count += count((array) $operations);
        }

        return $count;
    }
}
