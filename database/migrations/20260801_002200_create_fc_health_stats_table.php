<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_health_stats
 *
 * One record per user per day (UNIQUE user_id,record_date), so health logging is an
 * upsert — which is what the weight-history chart assumes.
 *
 * mood/energy/stress are numeric scores rather than the original ENUMs: the
 * prototype charts these, and an ENUM cannot be averaged.
 *
 * BMI is computed on write from height, never trusted from the client, and NULL when
 * height is unknown.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_health_stats',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  user_id bigint(20) unsigned NOT NULL,
  record_date date NOT NULL DEFAULT '1970-01-01',
  recorded_at datetime DEFAULT NULL,
  weight_kg decimal(5,2) DEFAULT NULL,
  body_fat_percentage decimal(5,2) DEFAULT NULL,
  muscle_mass_kg decimal(5,2) DEFAULT NULL,
  bmi decimal(5,2) DEFAULT NULL,
  systolic_pressure int(11) DEFAULT NULL,
  diastolic_pressure int(11) DEFAULT NULL,
  heart_rate_resting int(11) DEFAULT NULL,
  sleep_hours decimal(3,1) DEFAULT NULL,
  mood_score tinyint(4) DEFAULT NULL,
  energy_score tinyint(4) DEFAULT NULL,
  stress_score tinyint(4) DEFAULT NULL,
  notes text DEFAULT NULL,
  source varchar(20) NOT NULL DEFAULT 'manual',
  last_edited_by_account_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_user_date (user_id,record_date),
  KEY idx_user_recent (user_id,record_date)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_health_stats');
        $this->foreign('fc_health_stats', 'user_id', 'fc_users', 'id', 'CASCADE');
    }
};
