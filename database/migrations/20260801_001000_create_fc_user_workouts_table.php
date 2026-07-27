<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_user_workouts
 *
 * Assignment + per-user progress. Nothing in the original schema could produce the
 * progress percentage every workout card in the user prototype displays.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_user_workouts',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  user_id bigint(20) unsigned NOT NULL,
  workout_id bigint(20) unsigned NOT NULL,
  assigned_by_account_id bigint(20) unsigned DEFAULT NULL,
  assigned_date date DEFAULT NULL,
  scheduled_for date DEFAULT NULL,
  progress_percentage decimal(5,2) NOT NULL DEFAULT 0.00,
  last_session_id bigint(20) unsigned DEFAULT NULL,
  times_completed int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'assigned',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_user_workout (user_id,workout_id),
  KEY idx_user_status (user_id,status),
  KEY idx_scheduled (user_id,scheduled_for)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_user_workouts');
        $this->foreign('fc_user_workouts', 'user_id', 'fc_users', 'id', 'CASCADE');
        $this->foreign('fc_user_workouts', 'workout_id', 'fc_workouts', 'id', 'CASCADE');
    }
};
