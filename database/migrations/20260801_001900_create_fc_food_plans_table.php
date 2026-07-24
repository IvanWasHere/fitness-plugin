<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_food_plans
 *
 * Trainer-authored meal plan.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_food_plans',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  trainer_id bigint(20) unsigned DEFAULT NULL,
  plan_name varchar(255) NOT NULL DEFAULT '',
  description text DEFAULT NULL,
  daily_calories int(11) DEFAULT NULL,
  meal_count int(11) DEFAULT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_trainer (trainer_id)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_food_plans');
        $this->foreign('fc_food_plans', 'trainer_id', 'fc_trainers', 'id', 'CASCADE');
    }
};
