<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_nutrition_log_items
 *
 * Replaces the original `food_items JSON` column.
 *
 * Nutrient values are COPIED from fc_foods, not joined: if an administrator corrects
 * a food's macros next month, historical logs must not silently change.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_nutrition_log_items',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  nutrition_log_id bigint(20) unsigned NOT NULL,
  food_id bigint(20) unsigned DEFAULT NULL,
  custom_name varchar(255) DEFAULT NULL,
  quantity decimal(7,2) NOT NULL DEFAULT 1.00,
  unit varchar(32) DEFAULT NULL,
  calories int(11) NOT NULL DEFAULT 0,
  protein_g decimal(6,2) NOT NULL DEFAULT 0.00,
  carbs_g decimal(6,2) NOT NULL DEFAULT 0.00,
  fat_g decimal(6,2) NOT NULL DEFAULT 0.00,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_log (nutrition_log_id),
  KEY idx_food (food_id)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_nutrition_log_items');
        $this->foreign('fc_nutrition_log_items', 'nutrition_log_id', 'fc_nutrition_logs', 'id', 'CASCADE');
        $this->foreign('fc_nutrition_log_items', 'food_id', 'fc_foods', 'id', 'SET NULL');
    }
};
