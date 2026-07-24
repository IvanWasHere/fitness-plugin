<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_ticket_replies
 *
 * `is_internal_note` marks staff-only replies that are never returned to the user.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_ticket_replies',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  ticket_id bigint(20) unsigned NOT NULL,
  author_wp_user_id bigint(20) unsigned NOT NULL,
  author_role varchar(20) NOT NULL DEFAULT 'user',
  message text DEFAULT NULL,
  attachments longtext DEFAULT NULL,
  is_internal_note tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_ticket_time (ticket_id,created_at)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_ticket_replies');
        $this->foreign('fc_ticket_replies', 'ticket_id', 'fc_tickets', 'id', 'CASCADE');
    }
};
