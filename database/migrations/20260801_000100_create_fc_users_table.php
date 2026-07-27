<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_users
 *
 * The member profile. `account_id` points at the fc_accounts row that signs in;
 * `id` is an internal surrogate and is never exposed as "the user id" by the API.
 *
 * The split is deliberate: an account is *who you are*, this table is *what you
 * are as a member*. A trainer or an administrator has an account and no row
 * here, which is why every member endpoint answers `fc_no_member_profile`
 * rather than inventing an empty profile.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_users',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  account_id bigint(20) unsigned NOT NULL,
  display_name varchar(100) DEFAULT NULL,
  phone varchar(32) DEFAULT NULL,
  avatar_url varchar(500) DEFAULT NULL,
  date_of_birth date DEFAULT NULL,
  gender varchar(20) NOT NULL DEFAULT 'undisclosed',
  height_cm decimal(5,2) DEFAULT NULL,
  weight_kg decimal(5,2) DEFAULT NULL,
  target_weight_kg decimal(5,2) DEFAULT NULL,
  body_fat_percentage decimal(5,2) DEFAULT NULL,
  fitness_level varchar(20) NOT NULL DEFAULT 'beginner',
  fitness_goal varchar(64) DEFAULT NULL,
  activity_level varchar(20) NOT NULL DEFAULT 'moderate',
  nutrition_goals longtext DEFAULT NULL,
  preferences longtext DEFAULT NULL,
  timezone varchar(64) DEFAULT NULL,
  locale varchar(10) DEFAULT NULL,
  onboarded_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_account (account_id)
            ) {$this->charsetCollate};"
        );

        // RESTRICT, not CASCADE: a dozen tables reference the account as
        // provenance, so deleting one must fail loudly rather than quietly
        // taking a person's entire history with it. Accounts are tombstoned
        // (status = 'deleted'), never removed.
        $this->engine('fc_users');
        $this->foreign('fc_users', 'account_id', 'fc_accounts', 'id', 'RESTRICT');
    }
};
