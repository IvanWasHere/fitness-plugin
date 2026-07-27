<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_trainers
 *
 * Trainer profile. `hourly_rate` is DISPLAY ONLY — trainers are traced, not paid
 * through the platform (Q2), so nothing multiplies it. `accepting_clients` and
 * `max_clients` gate visibility in the trainer directory (Q4).
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_trainers',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  account_id bigint(20) unsigned NOT NULL,
  display_name varchar(100) DEFAULT NULL,
  bio text DEFAULT NULL,
  specialization varchar(255) DEFAULT NULL,
  avatar_url varchar(500) DEFAULT NULL,
  phone varchar(32) DEFAULT NULL,
  hourly_rate decimal(10,2) DEFAULT NULL,
  currency char(3) NOT NULL DEFAULT 'USD',
  rating decimal(3,2) DEFAULT NULL,
  rating_count int(10) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  accepting_clients tinyint(1) NOT NULL DEFAULT 1,
  max_clients int(10) unsigned DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_account (account_id),
  KEY idx_directory (status,accepting_clients)
            ) {$this->charsetCollate};"
        );

        // RESTRICT for the same reason as fc_users: the account is provenance.
        $this->engine('fc_trainers');
        $this->foreign('fc_trainers', 'account_id', 'fc_accounts', 'id', 'RESTRICT');
    }
};
