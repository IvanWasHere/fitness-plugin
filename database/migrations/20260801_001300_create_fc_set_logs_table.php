<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_set_logs
 *
 * One row per completed set — what the player actually emits.
 *
 * Aggregating sets into a single row (as the original spec did) discards exactly the
 * data the statistics screen is specified to show: volume, per-set progression and
 * personal records.
 *
 * UNIQUE(exercise_log_id,set_index) makes set logging idempotent, so a retry after a
 * dropped connection on gym wifi updates rather than duplicates.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_set_logs',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  exercise_log_id bigint(20) unsigned NOT NULL,
  set_index int(11) NOT NULL DEFAULT 0,
  reps int(11) DEFAULT NULL,
  weight_kg decimal(6,2) DEFAULT NULL,
  duration_seconds int(11) DEFAULT NULL,
  rest_taken_seconds int(11) DEFAULT NULL,
  rpe tinyint(4) DEFAULT NULL,
  completed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_set (exercise_log_id,set_index)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_set_logs');
        $this->foreign('fc_set_logs', 'exercise_log_id', 'fc_exercise_logs', 'id', 'CASCADE');
    }
};
