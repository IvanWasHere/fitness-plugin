<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_nutrition_days
 *
 * Daily rollup and water intake. Water is a per-DAY quantity; the original schema
 * put water_intake_ml on each meal row, which makes "5 of 8 glasses today" a SUM
 * over rows that may not exist.
 *
 * Goals are snapshotted per day so changing a goal next week does not rewrite last
 * week's history.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_nutrition_days',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  user_id bigint(20) unsigned NOT NULL,
  log_date date NOT NULL DEFAULT '1970-01-01',
  water_ml int(11) NOT NULL DEFAULT 0,
  goal_calories int(11) DEFAULT NULL,
  goal_protein_g decimal(6,2) DEFAULT NULL,
  goal_carbs_g decimal(6,2) DEFAULT NULL,
  goal_fat_g decimal(6,2) DEFAULT NULL,
  goal_water_ml int(11) DEFAULT NULL,
  total_calories int(11) NOT NULL DEFAULT 0,
  total_protein_g decimal(6,2) NOT NULL DEFAULT 0.00,
  total_carbs_g decimal(6,2) NOT NULL DEFAULT 0.00,
  total_fat_g decimal(6,2) NOT NULL DEFAULT 0.00,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_user_day (user_id,log_date)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_nutrition_days');
        $this->foreign('fc_nutrition_days', 'user_id', 'fc_users', 'id', 'CASCADE');
    }
};
