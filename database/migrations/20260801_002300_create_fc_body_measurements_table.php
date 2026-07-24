<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_body_measurements
 *
 * Separate from fc_health_stats because measurement cadence is monthly while weight
 * is daily; merging them yields a table that is ~90% NULL.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_body_measurements',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  user_id bigint(20) unsigned NOT NULL,
  record_date date NOT NULL DEFAULT '1970-01-01',
  chest_cm decimal(5,2) DEFAULT NULL,
  waist_cm decimal(5,2) DEFAULT NULL,
  hips_cm decimal(5,2) DEFAULT NULL,
  arms_cm decimal(5,2) DEFAULT NULL,
  thighs_cm decimal(5,2) DEFAULT NULL,
  shoulders_cm decimal(5,2) DEFAULT NULL,
  neck_cm decimal(5,2) DEFAULT NULL,
  calves_cm decimal(5,2) DEFAULT NULL,
  notes text DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_user_date (user_id,record_date)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_body_measurements');
        $this->foreign('fc_body_measurements', 'user_id', 'fc_users', 'id', 'CASCADE');
    }
};
