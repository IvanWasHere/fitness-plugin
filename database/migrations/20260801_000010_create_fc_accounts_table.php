<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_accounts
 *
 * **The identity anchor.** Every other table that used to name a WordPress user
 * now names a row here. The plugin owns its own credentials: a WordPress
 * administrator is not a FitnessClub administrator, and signing in to wp-admin
 * grants nothing in the app.
 *
 * ## One table for all three roles
 *
 * `role` is single-valued — user | trainer | admin — which is what lets the
 * provenance columns spread across a dozen tables (`actor_account_id`,
 * `assigned_by_account_id`, `last_edited_by_account_id`, …) point at *one* id
 * space. Before this they pointed at "any WordPress user", which is a
 * polymorphic reference with no constraint behind it.
 *
 * An admin who also coaches is not a second role: give the admin role the
 * trainer capabilities (Auth\Capabilities already does) and the account an
 * `fc_trainers` row.
 *
 * ## Column notes worth keeping
 *
 * - `login` is required and unique; `email` is nullable, because the bootstrap
 *   administrator exists before anyone has attached an address to it. Sign-in
 *   accepts either.
 * - `email_canonical` carries the uniqueness rather than `email`. The default
 *   collation is case-insensitive, but the collation is a per-install variable —
 *   an install running `_bin` would happily accept `Ivan@x.com` and `ivan@x.com`
 *   as two accounts, which is an account-takeover-adjacent bug.
 * - `varchar(190)`, not 255, on the indexed string columns: utf8mb4 against the
 *   767-byte legacy index limit. WordPress uses 191 for exactly this reason.
 * - `failed_login_count`/`locked_until` give per-account lockout. RateLimiter
 *   throttles per IP, which does nothing against a slow distributed attack
 *   spread across many addresses but aimed at one account.
 * - **Accounts are never hard-deleted.** A dozen tables reference this row as
 *   provenance, so a DELETE either orphans audit history or cascades a person's
 *   entire record away. `status = 'deleted'` is the tombstone, and the child
 *   anchors use ON DELETE RESTRICT to make the point structural.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_accounts',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  login varchar(60) NOT NULL,
  email varchar(190) DEFAULT NULL,
  email_canonical varchar(190) DEFAULT NULL,
  password_hash varchar(255) NOT NULL,
  display_name varchar(100) NOT NULL DEFAULT '',
  role varchar(20) NOT NULL DEFAULT 'user',
  extra_caps longtext DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  locale varchar(10) DEFAULT NULL,
  timezone varchar(64) DEFAULT NULL,
  avatar_url varchar(500) DEFAULT NULL,
  password_changed_at datetime DEFAULT NULL,
  failed_login_count int(10) unsigned NOT NULL DEFAULT 0,
  locked_until datetime DEFAULT NULL,
  last_login_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_login (login),
  UNIQUE KEY uq_email (email_canonical),
  KEY idx_role_status (role,status)
            ) {$this->charsetCollate};"
        );

        $this->engine('fc_accounts');
    }
};
