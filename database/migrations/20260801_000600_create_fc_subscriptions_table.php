<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_subscriptions
 *
 * A user may hold several concurrent subscriptions — one per trainer (Q3).
 * `trainer_id` is the ATTRIBUTION key (whose plan was bought, Q2); it confers no
 * entitlement to proceeds and drives no payout.
 *
 * `cancel_at_period_end` preserves the very common "cancelled but paid through the
 * 28th" state that a flat status enum loses.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_subscriptions',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  user_id bigint(20) unsigned NOT NULL,
  plan_id bigint(20) unsigned NOT NULL,
  trainer_id bigint(20) unsigned DEFAULT NULL,
  start_date date DEFAULT NULL,
  end_date date DEFAULT NULL,
  trial_ends_at date DEFAULT NULL,
  subscription_type varchar(20) NOT NULL DEFAULT 'monthly',
  status varchar(20) NOT NULL DEFAULT 'active',
  auto_renew tinyint(1) NOT NULL DEFAULT 1,
  cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
  price_paid decimal(10,2) DEFAULT NULL,
  currency char(3) NOT NULL DEFAULT 'USD',
  gateway varchar(32) DEFAULT NULL,
  gateway_customer_id varchar(255) DEFAULT NULL,
  gateway_subscription_id varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_user_status (user_id,status),
  KEY idx_expiry (status,end_date),
  KEY idx_trainer_status (trainer_id,status),
  KEY idx_plan (plan_id)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_subscriptions');
        $this->foreign('fc_subscriptions', 'user_id', 'fc_users', 'id', 'CASCADE');
        $this->foreign('fc_subscriptions', 'plan_id', 'fc_plans', 'id', 'CASCADE');
        $this->foreign('fc_subscriptions', 'trainer_id', 'fc_trainers', 'id', 'SET NULL');
    }
};
