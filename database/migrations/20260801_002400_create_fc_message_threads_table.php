<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_message_threads
 *
 * The conversation list (avatar, last message, unread badge, timestamp) is the most
 * frequently polled query in the product. Without a thread table it is a correlated
 * subquery over the whole message table per conversation, per poll, per user.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_message_threads',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  user_id bigint(20) unsigned NOT NULL,
  trainer_id bigint(20) unsigned NOT NULL,
  last_message_at datetime DEFAULT NULL,
  last_message_preview varchar(255) DEFAULT NULL,
  user_unread_count int(11) NOT NULL DEFAULT 0,
  trainer_unread_count int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'open',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_thread (user_id,trainer_id),
  KEY idx_user_recent (user_id,last_message_at),
  KEY idx_trainer_recent (trainer_id,last_message_at)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_message_threads');
        $this->foreign('fc_message_threads', 'user_id', 'fc_users', 'id', 'CASCADE');
        $this->foreign('fc_message_threads', 'trainer_id', 'fc_trainers', 'id', 'CASCADE');
    }
};
