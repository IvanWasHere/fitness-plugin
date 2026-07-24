<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_workout_sessions
 *
 * The workout state machine's store.
 *
 * `current_exercise_index` / `current_set_index` are the resume cursor the original
 * schema lacked, so "pause and continue later" (spec 8.1) was unimplementable.
 *
 * Elapsed time is ALWAYS derived server-side from started_at / last_resumed_at /
 * paused_seconds. A client-supplied duration is never persisted, or session length,
 * calories and any future leaderboard become client-editable.
 *
 * At most one in_progress|paused session per user; MySQL has no partial unique
 * index, so this is enforced in WorkoutSessionService::start() within a transaction.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_workout_sessions',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  user_id bigint(20) unsigned NOT NULL,
  workout_id bigint(20) unsigned NOT NULL,
  user_workout_id bigint(20) unsigned DEFAULT NULL,
  log_date date DEFAULT NULL,
  started_at datetime DEFAULT NULL,
  ended_at datetime DEFAULT NULL,
  last_resumed_at datetime DEFAULT NULL,
  duration_seconds int(11) NOT NULL DEFAULT 0,
  paused_seconds int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'in_progress',
  current_exercise_index int(11) NOT NULL DEFAULT 0,
  current_set_index int(11) NOT NULL DEFAULT 0,
  completion_percentage decimal(5,2) NOT NULL DEFAULT 0.00,
  calories_burned int(11) DEFAULT NULL,
  perceived_exertion tinyint(4) DEFAULT NULL,
  difficulty_rating tinyint(4) DEFAULT NULL,
  notes text DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_user_date (user_id,log_date),
  KEY idx_user_status (user_id,status),
  KEY idx_workout (workout_id),
  KEY idx_weekly (user_id,status,log_date)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_workout_sessions');
        $this->foreign('fc_workout_sessions', 'user_id', 'fc_users', 'id', 'CASCADE');
        $this->foreign('fc_workout_sessions', 'workout_id', 'fc_workouts', 'id', 'CASCADE');
    }
};
