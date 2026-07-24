<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_plans
 *
 * Platform tiers (owner_type='platform') and trainer-authored plans
 * (owner_type='trainer') share this table. A NULL price tier means that billing
 * period is not offered, which is distinct from 0.00 (free).
 *
 * `features` is the single source of truth for entitlements and includes
 * `max_trainers` (Q14) — the cap on how many trainers the subscriber may hold.
 *
 * @see plans/01-database.md
 */
return new class extends Migration {
    public function up()
    {
        $this->create(
            'fc_plans',
            "(
  id bigint(20) unsigned NOT NULL auto_increment,
  owner_type varchar(20) NOT NULL DEFAULT 'trainer',
  trainer_id bigint(20) unsigned DEFAULT NULL,
  plan_name varchar(255) NOT NULL DEFAULT '',
  slug varchar(120) DEFAULT NULL,
  description text DEFAULT NULL,
  plan_type varchar(20) NOT NULL DEFAULT 'combined',
  difficulty varchar(20) NOT NULL DEFAULT 'beginner',
  weekly_sessions int(11) DEFAULT NULL,
  duration_weeks int(11) DEFAULT NULL,
  currency char(3) NOT NULL DEFAULT 'USD',
  price_weekly decimal(10,2) DEFAULT NULL,
  price_monthly decimal(10,2) DEFAULT NULL,
  price_quarterly decimal(10,2) DEFAULT NULL,
  price_yearly decimal(10,2) DEFAULT NULL,
  features longtext DEFAULT NULL,
  includes_nutrition tinyint(1) NOT NULL DEFAULT 0,
  includes_health_stats tinyint(1) NOT NULL DEFAULT 1,
  max_messages_per_week int(11) NOT NULL DEFAULT 10,
  max_trainers int(11) NOT NULL DEFAULT 1,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_owner_active (owner_type,is_active),
  KEY idx_trainer (trainer_id),
  KEY idx_slug (slug)
            ) {$this->charsetCollate};"
        );

        // dbDelta cannot express FOREIGN KEY; added separately and guarded.
        $this->engine('fc_plans');
        $this->foreign('fc_plans', 'trainer_id', 'fc_trainers', 'id', 'CASCADE');
    }
};
