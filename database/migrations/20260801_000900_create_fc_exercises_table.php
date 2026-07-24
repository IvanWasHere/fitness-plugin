<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_exercises
 *
 * `default_rest_seconds` is the column the original specification omitted; without
 * it the workout player's rest timer (spec 8.2) cannot be built at all.
 *
 * `metric` resolves the prototype's ambiguity where "3 sets x 60 reps" actually
 * meant seconds (Plank Hold) and "reps = jumps" (Jump Rope). Encoding the unit as
 * free-text notes makes it unlabelable in the UI and unaggregatable in analytics.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_exercises',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  workout_id bigint(20) unsigned NOT NULL,
  exercise_name varchar(255) NOT NULL DEFAULT '',
  exercise_type varchar(20) NOT NULL DEFAULT 'compound',
  muscle_groups longtext DEFAULT NULL,
  instructions text DEFAULT NULL,
  notes varchar(500) DEFAULT NULL,
  video_url varchar(500) DEFAULT NULL,
  thumbnail_url varchar(500) DEFAULT NULL,
  media_gallery longtext DEFAULT NULL,
  default_sets int(11) NOT NULL DEFAULT 3,
  default_reps int(11) NOT NULL DEFAULT 10,
  default_weight_kg decimal(6,2) NOT NULL DEFAULT 0.00,
  default_rest_seconds int(11) NOT NULL DEFAULT 60,
  metric varchar(20) NOT NULL DEFAULT 'reps',
  order_index int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_workout_order (workout_id,order_index)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_exercises');
        $this->foreign('fc_exercises', 'workout_id', 'fc_workouts', 'id', 'CASCADE');
    }
};
