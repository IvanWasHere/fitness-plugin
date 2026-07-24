<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_payments
 *
 * `transaction_id` is UNIQUE and doubles as the webhook idempotency key: a
 * duplicate gateway event fails the insert harmlessly instead of double-crediting.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_payments',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  user_id bigint(20) unsigned NOT NULL,
  subscription_id bigint(20) unsigned DEFAULT NULL,
  amount decimal(10,2) NOT NULL DEFAULT 0.00,
  currency char(3) NOT NULL DEFAULT 'USD',
  payment_type varchar(20) NOT NULL DEFAULT 'subscription',
  gateway varchar(32) DEFAULT NULL,
  payment_method varchar(50) DEFAULT NULL,
  transaction_id varchar(191) DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  failure_reason varchar(255) DEFAULT NULL,
  gateway_payload longtext DEFAULT NULL,
  payment_date datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_transaction (transaction_id),
  KEY idx_user_status (user_id,status),
  KEY idx_payment_date (payment_date),
  KEY idx_subscription (subscription_id)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_payments');
        $this->foreign('fc_payments', 'user_id', 'fc_users', 'id', 'CASCADE');
        $this->foreign('fc_payments', 'subscription_id', 'fc_subscriptions', 'id', 'SET NULL');
    }
};
