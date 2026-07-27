<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_user_trainers
 *
 * The authorisation spine for the entire trainer role — every trainer-scoped query
 * joins it. Many-to-many (Q3). Users initiate via request (Q4): pending -> active |
 * declined | withdrawn. `is_primary` answers "your trainer" where a single answer
 * is needed; at most one per user, enforced in AssignmentService.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_user_trainers',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  user_id bigint(20) unsigned NOT NULL,
  trainer_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  is_primary tinyint(1) NOT NULL DEFAULT 0,
  requested_at datetime DEFAULT NULL,
  request_message varchar(500) DEFAULT NULL,
  responded_at datetime DEFAULT NULL,
  decline_reason varchar(255) DEFAULT NULL,
  assigned_date date DEFAULT NULL,
  ended_date date DEFAULT NULL,
  assigned_by_account_id bigint(20) unsigned DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_pair_status (user_id,trainer_id,status),
  KEY idx_trainer_status (trainer_id,status),
  KEY idx_user_primary (user_id,is_primary),
  KEY idx_queue (status,requested_at)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_user_trainers');
        $this->foreign('fc_user_trainers', 'user_id', 'fc_users', 'id', 'CASCADE');
        $this->foreign('fc_user_trainers', 'trainer_id', 'fc_trainers', 'id', 'CASCADE');
    }
};
