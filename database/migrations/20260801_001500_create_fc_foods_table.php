<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_foods
 *
 * The admin "Food Database". FULLTEXT on (name,brand) because the food search modal
 * is a typeahead and LIKE '%x%' will not scale past a few thousand rows.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_foods',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  name varchar(255) NOT NULL DEFAULT '',
  brand varchar(120) DEFAULT NULL,
  category varchar(32) NOT NULL DEFAULT 'other',
  serving_size varchar(64) DEFAULT NULL,
  serving_grams decimal(7,2) DEFAULT NULL,
  calories int(11) NOT NULL DEFAULT 0,
  protein_g decimal(6,2) NOT NULL DEFAULT 0.00,
  carbs_g decimal(6,2) NOT NULL DEFAULT 0.00,
  fat_g decimal(6,2) NOT NULL DEFAULT 0.00,
  fiber_g decimal(7,2) DEFAULT NULL,
  sugar_g decimal(7,2) DEFAULT NULL,
  sodium_mg decimal(7,2) DEFAULT NULL,
  barcode varchar(32) DEFAULT NULL,
  source varchar(20) NOT NULL DEFAULT 'system',
  created_by_wp_user_id bigint(20) unsigned DEFAULT NULL,
  is_verified tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_category (category),
  KEY idx_barcode (barcode),
  FULLTEXT KEY ft_name (name,brand)
            ) {$this->charsetCollate};"
        );
    }
};
