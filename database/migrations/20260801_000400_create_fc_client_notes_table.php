<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_client_notes
 *
 * Trainer-private notes. Scoped (trainer_id,user_id) and never readable by another
 * trainer, even though Q13 opened assignments and plans to all assigned trainers —
 * observations are not programming (Q15).
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_client_notes',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  trainer_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  body text DEFAULT NULL,
  pinned tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_trainer_user (trainer_id,user_id,created_at)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_client_notes');
        $this->foreign('fc_client_notes', 'trainer_id', 'fc_trainers', 'id', 'CASCADE');
        $this->foreign('fc_client_notes', 'user_id', 'fc_users', 'id', 'CASCADE');
    }
};
