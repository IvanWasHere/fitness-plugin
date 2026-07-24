<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_food_plan_meals
 *
 * Meals belonging to a food plan.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_food_plan_meals',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  food_plan_id bigint(20) unsigned NOT NULL,
  meal_name varchar(255) NOT NULL DEFAULT '',
  meal_type varchar(20) NOT NULL DEFAULT 'other',
  description text DEFAULT NULL,
  food_ids longtext DEFAULT NULL,
  calories int(11) NOT NULL DEFAULT 0,
  protein decimal(6,2) NOT NULL DEFAULT 0.00,
  carbs decimal(6,2) NOT NULL DEFAULT 0.00,
  fat decimal(6,2) NOT NULL DEFAULT 0.00,
  order_index int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_plan_order (food_plan_id,order_index)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_food_plan_meals');
        $this->foreign('fc_food_plan_meals', 'food_plan_id', 'fc_food_plans', 'id', 'CASCADE');
    }
};
