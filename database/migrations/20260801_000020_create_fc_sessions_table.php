<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_sessions
 *
 * Server-side sessions for the plugin's own cookie. WordPress' auth cookie is
 * not used and not consulted.
 *
 * ## Split token: selector + verifier
 *
 * The cookie carries `{selector}.{verifier}`. The row is found by `selector`,
 * which is indexed, public and safe to put in a log or an "active sessions"
 * screen; the `verifier` is compared against `token_hash` with `hash_equals`.
 * A single hashed-token lookup would also work, but then the only handle on a
 * session is the secret itself, and you end up logging it by accident.
 *
 * **The raw token is never stored.** `token_hash` is a plain SHA-256 rather
 * than bcrypt on purpose: the input is already 256 bits of CSPRNG output, so
 * there is nothing to brute-force, and a deliberately slow KDF here would tax
 * every single authenticated request for no gain.
 *
 * ## Two expiries, not one
 *
 * `expires_at` slides forward on activity — that is the idle timeout.
 * `absolute_expires_at` never moves. Without the absolute cap, a stolen cookie
 * on an app that polls stays valid indefinitely, because the theft itself keeps
 * refreshing it.
 *
 * `csrf_hash` binds the CSRF token to this session (see Auth\Csrf): a naive
 * double-submit check is defeated by anything that can set a cookie on the
 * parent domain, and storing the hash here is what closes that.
 *
 * ## `kind` — cookie sessions and API refresh tokens share this table (W4.2)
 *
 * A refresh token is a session by every property that matters here: it is a
 * long-lived credential, it is a split token, it has an idle and an absolute
 * expiry, and it must be revocable. Giving it its own table would mean a second
 * implementation of `revoke`, `revokeAllFor` and `gc` — and the one that would
 * have drifted is the one that matters, because `revokeAllFor()` runs on password
 * change. Sharing the table means changing a password kills the mobile session
 * too, for free and in one place, rather than because somebody remembered.
 *
 * `csrf_hash` is therefore nullable: a bearer token is not sent ambiently by a
 * browser, so there is no cross-site request to forge and nothing to bind.
 * Storing a meaningless hash to keep the column NOT NULL would be a lie in a
 * security-relevant field.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_sessions',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  account_id bigint(20) unsigned NOT NULL,
  kind varchar(20) NOT NULL DEFAULT 'cookie',
  selector char(32) NOT NULL,
  token_hash char(64) NOT NULL,
  csrf_hash char(64) DEFAULT NULL,
  remember tinyint(1) NOT NULL DEFAULT 0,
  ip_hash varchar(64) DEFAULT NULL,
  user_agent varchar(255) DEFAULT NULL,
  issued_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at datetime NOT NULL,
  absolute_expires_at datetime NOT NULL,
  revoked_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uq_selector (selector),
  KEY idx_account (account_id,expires_at),
  KEY idx_gc (expires_at)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_sessions');
        $this->foreign('fc_sessions', 'account_id', 'fc_accounts', 'id', 'CASCADE');
    }
};
