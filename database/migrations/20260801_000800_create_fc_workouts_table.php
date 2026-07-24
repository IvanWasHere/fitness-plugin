<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_workouts
 *
 * Workout template. `muscle_groups` and `equipment` hold JSON arrays rendered as
 * chips on the workout card in both prototypes.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_workouts',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  trainer_id bigint(20) unsigned DEFAULT NULL,
  plan_id bigint(20) unsigned DEFAULT NULL,
  workout_name varchar(255) NOT NULL DEFAULT '',
  slug varchar(191) DEFAULT NULL,
  description text DEFAULT NULL,
  workout_type varchar(20) NOT NULL DEFAULT 'strength',
  difficulty varchar(20) NOT NULL DEFAULT 'beginner',
  estimated_duration_minutes int(11) DEFAULT NULL,
  calories_burn_estimate int(11) DEFAULT NULL,
  instructions text DEFAULT NULL,
  video_url varchar(500) DEFAULT NULL,
  cover_image_url varchar(500) DEFAULT NULL,
  media_gallery longtext DEFAULT NULL,
  equipment longtext DEFAULT NULL,
  muscle_groups longtext DEFAULT NULL,
  is_template tinyint(1) NOT NULL DEFAULT 1,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_trainer (trainer_id),
  KEY idx_plan (plan_id),
  KEY idx_browse (difficulty,is_active)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_workouts');
        $this->foreign('fc_workouts', 'trainer_id', 'fc_trainers', 'id', 'CASCADE');
        $this->foreign('fc_workouts', 'plan_id', 'fc_plans', 'id', 'SET NULL');
    }
};
