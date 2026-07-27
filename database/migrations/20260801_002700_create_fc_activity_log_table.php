<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_activity_log
 *
 * Powers the dashboard "Recent Activity" feed AND doubles as the audit trail
 * required by section 15.2: actor_account_id is who did it, account_id is to whom.
 *
 * Admin edits of health/nutrition data (Q10) write before/after values into `meta`.
 * Those audit rows are exempt from the 12-month prune.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_activity_log',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  account_id bigint(20) unsigned NOT NULL,
  actor_account_id bigint(20) unsigned DEFAULT NULL,
  type varchar(48) NOT NULL DEFAULT '',
  subject_type varchar(48) DEFAULT NULL,
  subject_id bigint(20) unsigned DEFAULT NULL,
  title varchar(255) DEFAULT NULL,
  detail varchar(255) DEFAULT NULL,
  meta longtext DEFAULT NULL,
  is_audit tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_feed (account_id,created_at),
  KEY idx_type (type,created_at),
  KEY idx_prune (is_audit,created_at)
            ) {$this->charsetCollate};"
        );
    }
};
