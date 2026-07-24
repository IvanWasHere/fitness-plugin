<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_user_food_plans
 *
 * Assignment of a food plan to a user. The original specification had no way to
 * assign a food plan to anybody, which makes section 12.1 ("user follows food
 * plan") unimplementable.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_user_food_plans',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  user_id bigint(20) unsigned NOT NULL,
  food_plan_id bigint(20) unsigned NOT NULL,
  assigned_by_wp_user_id bigint(20) unsigned DEFAULT NULL,
  start_date date DEFAULT NULL,
  end_date date DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_user_plan (user_id,food_plan_id),
  KEY idx_user_status (user_id,status)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_user_food_plans');
        $this->foreign('fc_user_food_plans', 'user_id', 'fc_users', 'id', 'CASCADE');
        $this->foreign('fc_user_food_plans', 'food_plan_id', 'fc_food_plans', 'id', 'CASCADE');
    }
};
