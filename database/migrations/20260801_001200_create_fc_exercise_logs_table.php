<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_exercise_logs
 *
 * One row per exercise per session: planned versus performed.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_exercise_logs',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  session_id bigint(20) unsigned NOT NULL,
  exercise_id bigint(20) unsigned NOT NULL,
  order_index int(11) NOT NULL DEFAULT 0,
  planned_sets int(11) DEFAULT NULL,
  planned_reps int(11) DEFAULT NULL,
  planned_weight_kg decimal(6,2) DEFAULT NULL,
  actual_sets int(11) DEFAULT NULL,
  actual_reps int(11) DEFAULT NULL,
  actual_weight_kg decimal(6,2) DEFAULT NULL,
  total_volume_kg decimal(10,2) NOT NULL DEFAULT 0.00,
  was_skipped tinyint(1) NOT NULL DEFAULT 0,
  notes text DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_session_exercise (session_id,exercise_id),
  KEY idx_exercise (exercise_id)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_exercise_logs');
        $this->foreign('fc_exercise_logs', 'session_id', 'fc_workout_sessions', 'id', 'CASCADE');
        $this->foreign('fc_exercise_logs', 'exercise_id', 'fc_exercises', 'id', 'CASCADE');
    }
};
