<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_nutrition_logs
 *
 * One row per logged meal. Totals are denormalised sums of the child item rows and
 * are recomputed on every child write.
 *
 * `source` and `last_edited_by_wp_user_id` exist because administrators may edit
 * these rows (Q10). Without them a staff correction is indistinguishable from a
 * self-reported entry and the user's charts change with no visible cause.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_nutrition_logs',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  user_id bigint(20) unsigned NOT NULL,
  log_date date NOT NULL DEFAULT '1970-01-01',
  logged_at datetime DEFAULT NULL,
  meal_type varchar(20) NOT NULL DEFAULT 'other',
  total_calories int(11) NOT NULL DEFAULT 0,
  total_protein_g decimal(6,2) NOT NULL DEFAULT 0.00,
  total_carbs_g decimal(6,2) NOT NULL DEFAULT 0.00,
  total_fat_g decimal(6,2) NOT NULL DEFAULT 0.00,
  notes text DEFAULT NULL,
  source varchar(20) NOT NULL DEFAULT 'manual',
  last_edited_by_wp_user_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_user_date (user_id,log_date)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_nutrition_logs');
        $this->foreign('fc_nutrition_logs', 'user_id', 'fc_users', 'id', 'CASCADE');
    }
};
