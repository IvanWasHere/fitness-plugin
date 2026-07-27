<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_account_tokens
 *
 * Single-use, expiring links: password reset, invitation, email verification.
 * One table for all three because the mechanics are identical — issue a
 * selector plus a hashed verifier, mail the pair, redeem once — and three
 * near-identical tables would drift.
 *
 * Same split-token scheme as fc_sessions, for the same reasons.
 *
 * `used_at` is what makes redemption single-use, and it is enforced by a
 * conditional UPDATE (`WHERE used_at IS NULL AND expires_at > NOW()`) with the
 * affected-row count checked — never by reading the row and then writing it.
 * Two concurrent redemptions of the same link is not a hypothetical: a mail
 * client that prefetches links plus the human clicking one is exactly that
 * race, and the read-then-write version lets both through.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_account_tokens',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  account_id bigint(20) unsigned NOT NULL,
  purpose varchar(32) NOT NULL DEFAULT 'password_reset',
  selector char(32) NOT NULL,
  token_hash char(64) NOT NULL,
  requested_ip_hash varchar(64) DEFAULT NULL,
  expires_at datetime NOT NULL,
  used_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_selector (selector),
  KEY idx_pending (account_id,purpose,used_at),
  KEY idx_gc (expires_at)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_account_tokens');
        $this->foreign('fc_account_tokens', 'account_id', 'fc_accounts', 'id', 'CASCADE');
    }
};
