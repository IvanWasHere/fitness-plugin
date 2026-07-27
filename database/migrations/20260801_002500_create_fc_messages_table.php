<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_messages
 *
 * `sender_account_id` gives one authoritative sender instead of the original
 * user_id + trainer_id + direction trio; `direction` is kept as a denormalised
 * convenience for the weekly quota query.
 *
 * Quota counts user_to_trainer messages only, per THREAD (Q3) — trainer replies are
 * never counted, or a trainer could exhaust their own client's allowance.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_messages',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  thread_id bigint(20) unsigned NOT NULL,
  sender_account_id bigint(20) unsigned NOT NULL,
  direction varchar(20) NOT NULL DEFAULT 'user_to_trainer',
  message text DEFAULT NULL,
  attachments longtext DEFAULT NULL,
  is_read tinyint(1) NOT NULL DEFAULT 0,
  read_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_thread_time (thread_id,created_at),
  KEY idx_thread_unread (thread_id,is_read),
  KEY idx_quota (thread_id,direction,created_at)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_messages');
        $this->foreign('fc_messages', 'thread_id', 'fc_message_threads', 'id', 'CASCADE');
    }
};
