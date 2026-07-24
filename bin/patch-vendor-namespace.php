#!/usr/bin/env php
<?php
/**
 * Rewrites the WP Bones framework namespace inside vendor/ from the upstream
 * default (WPKirk) to this plugin's namespace (FitnessClub).
 *
 * WHY NOT `php bones rename --update`?
 * -----------------------------------
 * The upstream command performs a global str_replace across EVERY file in the
 * project (see `setPluginNameAndNamespace()` in the bones CLI). It scans with
 * `recursiveScan('*')` and excludes only node_modules and the bones binary, so
 * it happily rewrites Markdown, HTML and any other text file. This repository
 * keeps `plans/00-architecture.md`, which legitimately cites
 * "wpbones/WPKirk-Boilerplate" — the upstream renamer corrupts it to
 * "wpbones/FitnessClub-Boilerplate".
 *
 * This script does the same job, but scoped strictly to vendor/wpbones/wpbones,
 * and is idempotent so it can run on every `composer install|update|dump-autoload`.
 *
 * Run automatically via composer's post-autoload-dump hook.
 */

declare(strict_types=1);

const SEARCH_NS  = 'WPKirk';
const REPLACE_NS = 'FitnessClub';

/**
 * The framework also hardcodes the upstream plugin SLUG, not just the namespace.
 * Two of these are live code, not cosmetics:
 *
 *   Plugin.php:101  $this->file = $this->basePath . '/wp-kirk.php';
 *                   -> register_activation_hook() binds to a file that does not
 *                      exist, so migrations and role installation NEVER RUN.
 *   Plugin.php:252  load_plugin_textdomain('wp-kirk', ...)
 *                   -> translations never load.
 *
 * Missing these is silent: WordPress reports the plugin as activated.
 */
const SEARCH_SLUG  = 'wp-kirk';
const REPLACE_SLUG = 'fitnessclub';
const SEARCH_VAR   = 'wp_kirk';
const REPLACE_VAR  = 'fitnessclub';

$root      = dirname(__DIR__);
$vendorLib = $root . '/vendor/wpbones/wpbones';

if (!is_dir($vendorLib)) {
    fwrite(STDOUT, "patch-vendor-namespace: wpbones not installed yet, skipping.\n");
    exit(0);
}

/** The CLI itself must keep the upstream defaults — it uses them as search terms. */
$skip = [
    $vendorLib . '/src/Console/bin/bones',
];

$changed = 0;
$scanned = 0;

$targets = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($vendorLib . '/src', FilesystemIterator::SKIP_DOTS)
);

/** @var SplFileInfo $file */
foreach ($targets as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    if (in_array($path, $skip, true)) {
        continue;
    }
    // Stubs use {Namespace} placeholders and must stay untouched.
    if (str_ends_with($path, '.stub')) {
        continue;
    }
    if (!in_array($file->getExtension(), ['php'], true)) {
        continue;
    }

    $scanned++;
    $original = file_get_contents($path);
    $patched  = strtr($original, [
        SEARCH_NS . '\\'  => REPLACE_NS . '\\',   // namespace + use statements
        SEARCH_NS . '()'  => REPLACE_NS . '()',   // the global plugin helper
        SEARCH_SLUG       => REPLACE_SLUG,        // main file name + text domain
        SEARCH_VAR        => REPLACE_VAR,         // snake_case text domain
    ]);

    if ($patched !== $original) {
        file_put_contents($path, $patched);
        $changed++;
    }
}

/** The package's own composer.json declares the PSR-4 prefix. */
$vendorComposer = $vendorLib . '/composer.json';
$autoloadDirty  = false;

if (is_file($vendorComposer)) {
    $json = json_decode(file_get_contents($vendorComposer), true, 512, JSON_THROW_ON_ERROR);
    $psr4 = $json['autoload']['psr-4'] ?? [];

    if (isset($psr4[SEARCH_NS . '\\WPBones\\'])) {
        $psr4[REPLACE_NS . '\\WPBones\\'] = $psr4[SEARCH_NS . '\\WPBones\\'];
        unset($psr4[SEARCH_NS . '\\WPBones\\']);
        $json['autoload']['psr-4'] = $psr4;
        file_put_contents(
            $vendorComposer,
            json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
        $autoloadDirty = true;
    }
}

/**
 * The autoload maps were generated before this script ran, so they still point at
 * the old prefix. Patch them in place rather than recursing into dump-autoload.
 */
foreach (['autoload_psr4.php', 'autoload_static.php', 'autoload_classmap.php'] as $mapFile) {
    $mapPath = $root . '/vendor/composer/' . $mapFile;
    if (!is_file($mapPath)) {
        continue;
    }
    $original = file_get_contents($mapPath);
    $patched  = str_replace(
        [SEARCH_NS . '\\\\WPBones\\\\', "'" . SEARCH_NS . '\\\\'],
        [REPLACE_NS . '\\\\WPBones\\\\', "'" . REPLACE_NS . '\\\\'],
        $original
    );
    // autoload_static.php also keys prefixes by first letter.
    $patched = str_replace(
        "'W' => \n    array (\n        '" . REPLACE_NS . "\\\\WPBones\\\\'",
        "'F' => \n    array (\n        '" . REPLACE_NS . "\\\\WPBones\\\\'",
        $patched
    );
    if ($patched !== $original) {
        file_put_contents($mapPath, $patched);
        $autoloadDirty = true;
    }
}

fwrite(STDOUT, sprintf(
    "patch-vendor-namespace: %s → %s (%d/%d files rewritten%s)\n",
    SEARCH_NS,
    REPLACE_NS,
    $changed,
    $scanned,
    $autoloadDirty ? ', autoload maps updated' : ''
));

exit(0);
