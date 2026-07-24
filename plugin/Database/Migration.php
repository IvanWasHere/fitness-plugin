<?php

namespace FitnessClub\Database;

use FitnessClub\WPBones\Database\DB;
use FitnessClub\WPBones\Database\Migrations\Migration as BaseMigration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Base migration for FitnessClub.
 *
 * Adds three things the framework's Migration does not provide, all of which
 * plans/01-database.md requires:
 *
 *  1. foreign() — dbDelta cannot process FOREIGN KEY clauses (it mangles them and
 *     re-issues them on every activation). Constraints are added by a separate,
 *     guarded ALTER that degrades to a no-op rather than aborting activation on
 *     hosts with FK support stripped or a MyISAM default. Referential integrity is
 *     ALSO enforced in the service layer — this is defence in depth.
 *  2. hasTable()/hasIndex()/hasForeignKey() — activation runs on EVERY (re)activation,
 *     so everything must be safely re-runnable.
 *  3. engine() — dbDelta does not set a storage engine; FKs need InnoDB.
 *
 * @see plans/01-database.md#migration-mechanics
 */
abstract class Migration extends BaseMigration
{
    protected function table(string $name): string
    {
        return DB::getTableName($name, $this->usePrefix);
    }

    protected function hasTable(string $name): bool
    {
        global $wpdb;

        $table = $this->table($name);

        return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    }

    protected function hasIndex(string $name, string $index): bool
    {
        global $wpdb;

        if (!$this->hasTable($name)) {
            return false;
        }

        $table = $this->table($name);

        // $table derives from our own constant list via DB::getTableName(), never
        // from user input, so interpolating it is safe.
        $found = $wpdb->get_results(
            $wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index)
        );

        return !empty($found);
    }

    protected function hasForeignKey(string $constraint): bool
    {
        global $wpdb;

        // $wpdb->dbname, not the DB_NAME constant: the connection may have been
        // switched (multisite, tests), and information_schema must be queried for
        // the schema we are actually writing to.
        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT CONSTRAINT_NAME
                   FROM information_schema.TABLE_CONSTRAINTS
                  WHERE CONSTRAINT_SCHEMA = %s
                    AND CONSTRAINT_NAME = %s
                    AND CONSTRAINT_TYPE = %s',
                $wpdb->dbname,
                $constraint,
                'FOREIGN KEY'
            )
        );

        return !empty($found);
    }

    /**
     * Ensure the table uses InnoDB. dbDelta never sets an engine, and foreign
     * keys are silently ignored by MyISAM.
     */
    protected function engine(string $name, string $engine = 'InnoDB'): void
    {
        global $wpdb;

        if (!$this->hasTable($name)) {
            return;
        }

        $table = $this->table($name);

        $current = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
                $wpdb->dbname,
                $table
            )
        );

        if ($current && 0 !== strcasecmp($current, $engine)) {
            $wpdb->query("ALTER TABLE `{$table}` ENGINE = {$engine}");
        }
    }

    /**
     * Add a foreign key constraint, guarded.
     *
     * A failure here is logged and swallowed so activation never dies on a
     * restrictive host; integrity is still enforced in the service layer.
     *
     * @param string $name     Child table, unprefixed (e.g. "fc_workout_sessions").
     * @param string $column   Child column (e.g. "user_id").
     * @param string $onTable  Parent table, unprefixed (e.g. "fc_users").
     * @param string $onColumn Parent column, normally "id".
     * @param string $onDelete CASCADE | SET NULL | RESTRICT | NO ACTION.
     */
    protected function foreign(
        string $name,
        string $column,
        string $onTable,
        string $onColumn = 'id',
        string $onDelete = 'CASCADE'
    ): void {
        global $wpdb;

        if (!$this->hasTable($name) || !$this->hasTable($onTable)) {
            return;
        }

        $child  = $this->table($name);
        $parent = $this->table($onTable);

        // Deterministic, collision-free, within MySQL's 64-char limit.
        $constraint = 'fk_' . substr(md5($child . $column . $parent . $onColumn), 0, 24);

        if ($this->hasForeignKey($constraint)) {
            return;
        }

        $onDelete = strtoupper($onDelete);
        if (!in_array($onDelete, ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION'], true)) {
            $onDelete = 'CASCADE';
        }

        $suppress = $wpdb->suppress_errors(true);

        $wpdb->query(
            "ALTER TABLE `{$child}`
                ADD CONSTRAINT `{$constraint}`
                FOREIGN KEY (`{$column}`)
                REFERENCES `{$parent}` (`{$onColumn}`)
                ON DELETE {$onDelete}"
        );

        if ($wpdb->last_error) {
            // Expected on hosts without InnoDB/FK support. Integrity is still
            // enforced by the service layer, so this is informational only.
            error_log(sprintf(
                '[fitnessclub] Skipped foreign key %s on %s.%s: %s',
                $constraint,
                $child,
                $column,
                $wpdb->last_error
            ));
        }

        $wpdb->suppress_errors($suppress);
    }
}
