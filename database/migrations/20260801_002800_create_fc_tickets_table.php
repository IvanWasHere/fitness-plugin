<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_tickets
 *
 * `assigned_to_wp_user_id` references a WP user, not a trainer. The original schema
 * FK'd this to the trainers table, which makes assigning a ticket to an
 * administrator impossible — the exact case section 13 requires.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_tickets',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  user_wp_user_id bigint(20) unsigned NOT NULL,
  subject varchar(255) NOT NULL DEFAULT '',
  message text DEFAULT NULL,
  category varchar(20) NOT NULL DEFAULT 'general',
  priority varchar(20) NOT NULL DEFAULT 'medium',
  status varchar(20) NOT NULL DEFAULT 'open',
  assigned_to_wp_user_id bigint(20) unsigned DEFAULT NULL,
  first_response_at datetime DEFAULT NULL,
  resolved_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_queue (status,priority),
  KEY idx_assignee (assigned_to_wp_user_id,status),
  KEY idx_requester (user_wp_user_id)
            ) {$this->charsetCollate};"
        );
    }
};
