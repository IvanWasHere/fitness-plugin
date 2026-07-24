<?php

namespace FitnessClub\Database\Upgrade;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Schema version dispatcher.
 *
 * WP Bones runs database/migrations/ on activation, and dbDelta is idempotent for
 * CREATE TABLE — so *creating* tables needs nothing more. But dbDelta will never
 * drop a column, rename one, change a primary key, or backfill data. Any schema
 * change beyond "add a column" needs an explicit, ordered, run-once upgrade path.
 *
 * This must exist BEFORE the first production install — retrofitting it later means
 * writing a migration to fix migrations on sites whose schema you can no longer
 * reason about. See plans/01-database.md#migration-mechanics.
 *
 * To add an upgrade step: bump self::VERSION and add a `to<N>()` method. Steps run
 * in ascending order, once per site, recording progress so a fatal mid-upgrade does
 * not re-run completed steps.
 */
final class Manager
{
    /** Current schema version. Bump when adding an upgrade step. */
    public const VERSION = 1;

    public const OPTION_VERSION = 'fitnessclub_db_version';

    /**
     * Run any pending upgrade steps. Safe to call on every request.
     */
    public static function run(): void
    {
        $installed = (int) get_option(self::OPTION_VERSION, 0);

        if (0 === $installed) {
            // Fresh install: migrations just created the current schema, so there
            // is nothing to upgrade. Record the version and stop.
            update_option(self::OPTION_VERSION, self::VERSION, false);

            return;
        }

        if ($installed >= self::VERSION) {
            return;
        }

        for ($version = $installed + 1; $version <= self::VERSION; $version++) {
            $step = 'to' . $version;

            if (!method_exists(self::class, $step)) {
                continue;
            }

            try {
                self::{$step}();
            } catch (\Throwable $e) {
                error_log(sprintf('[fitnessclub] Schema upgrade to v%d failed: %s', $version, $e->getMessage()));

                // Stop at the failed step; the site stays on the last good version
                // so the next request retries from here rather than skipping.
                return;
            }

            update_option(self::OPTION_VERSION, $version, false);
        }
    }

    /**
     * Reset on uninstall so a reinstall is treated as a fresh install.
     */
    public static function forget(): void
    {
        delete_option(self::OPTION_VERSION);
    }

    /*
    |--------------------------------------------------------------------------
    | Upgrade steps — add as the schema evolves
    |--------------------------------------------------------------------------
    |
    | private static function to2(): void
    | {
    |     global $wpdb;
    |     $table = DB::getTableName('fc_users');
    |     $wpdb->query("UPDATE `{$table}` SET activity_level = 'moderate' WHERE activity_level = ''");
    | }
    |
    */
}
