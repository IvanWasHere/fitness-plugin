<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_personal_records
 *
 * Cache, derivable from fc_set_logs but needed in under 50ms at the exact moment a
 * session completes (the celebration screen). Keyed by exercise NAME, not id, so a
 * PR follows the movement across workout templates.
 *
 * Rebuildable from set logs via a bones command if it ever drifts.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_personal_records',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  user_id bigint(20) unsigned NOT NULL,
  exercise_name varchar(191) NOT NULL DEFAULT '',
  record_type varchar(20) NOT NULL DEFAULT 'max_weight',
  value decimal(10,2) NOT NULL DEFAULT 0.00,
  unit varchar(16) NOT NULL DEFAULT 'kg',
  session_id bigint(20) unsigned DEFAULT NULL,
  achieved_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_pr (user_id,exercise_name,record_type)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_personal_records');
        $this->foreign('fc_personal_records', 'user_id', 'fc_users', 'id', 'CASCADE');
    }
};
