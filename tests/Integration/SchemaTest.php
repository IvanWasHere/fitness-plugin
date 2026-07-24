<?php

namespace FitnessClub\Tests\Integration;

use FitnessClub\Database\Upgrade\Manager;
use PHPUnit\Framework\TestCase;

/**
 * The 29-table schema is present, InnoDB, and carries the columns the original
 * spec omitted (the plan's blockers). See plans/01-database.md.
 */
final class SchemaTest extends TestCase
{
    public function testAll29TablesExist(): void
    {
        global $wpdb;

        $tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}fc\\_%'");
        $this->assertCount(29, $tables);
    }

    public function testEveryTableIsInnoDb(): void
    {
        global $wpdb;

        $nonInno = $wpdb->get_col($wpdb->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s AND ENGINE <> 'InnoDB'",
            $wpdb->dbname,
            $wpdb->prefix . 'fc\\_%'
        ));

        $this->assertSame([], $nonInno, 'FKs require InnoDB.');
    }

    /**
     * @dataProvider blockerColumnProvider
     * @param string[] $columns
     */
    public function testBlockerColumnsPresent(string $table, array $columns): void
    {
        global $wpdb;

        $have    = $wpdb->get_col("SHOW COLUMNS FROM `{$wpdb->prefix}fc_{$table}`");
        $missing = array_diff($columns, $have);

        $this->assertSame([], $missing, "fc_{$table} is missing: " . implode(', ', $missing));
    }

    /**
     * @return array<string,array{0:string,1:string[]}>
     */
    public static function blockerColumnProvider(): array
    {
        return [
            'exercise rest timer + metric'  => ['exercises', ['default_rest_seconds', 'metric']],
            'session resume cursor'         => ['workout_sessions', ['current_exercise_index', 'current_set_index', 'last_resumed_at']],
            'multi-trainer request fields'  => ['user_trainers', ['is_primary', 'requested_at', 'decline_reason']],
            'plan entitlement cap'          => ['plans', ['max_trainers', 'owner_type']],
            'health provenance (Q10)'       => ['health_stats', ['source', 'last_edited_by_wp_user_id']],
            'per-set logging'               => ['set_logs', ['exercise_log_id', 'set_index', 'rpe']],
        ];
    }

    public function testSchemaVersionRecorded(): void
    {
        $this->assertSame(Manager::VERSION, (int) get_option(Manager::OPTION_VERSION));
    }
}
