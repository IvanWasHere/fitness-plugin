<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_notifications
 *
 * An entire screen in the user prototype with nothing behind it in the original
 * specification. Keyed on account_id rather than on fc_users.id, so trainers and
 * administrators — who have accounts but no member profile — can be notified too.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_notifications',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  account_id bigint(20) unsigned NOT NULL,
  type varchar(32) NOT NULL DEFAULT 'system',
  title varchar(255) NOT NULL DEFAULT '',
  body text DEFAULT NULL,
  icon varchar(64) DEFAULT NULL,
  color varchar(32) DEFAULT NULL,
  action_url varchar(500) DEFAULT NULL,
  meta longtext DEFAULT NULL,
  is_read tinyint(1) NOT NULL DEFAULT 0,
  read_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_inbox (account_id,is_read,created_at)
            ) {$this->charsetCollate};"
        );
    }
};
