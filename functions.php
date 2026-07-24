<?php

// This file is autoloaded by Composer (autoload.files), so it also loads under
// CLI tools (phpcs, phpunit). A bare exit() there kills those processes silently,
// so the direct-HTTP-access guard is scoped to non-CLI SAPIs. (No wpBones-native
// way around this — the boilerplate ships no PHP test harness.)
if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit();
}

/*
|--------------------------------------------------------------------------
| Global functions
|--------------------------------------------------------------------------
|
| Here you can insert your global function loaded by composer settings.
|
*/

if (!function_exists('myGlobalFunction')) {
    function myGlobalFunction(): void
    {
      //
    }
}
