<?php

use FitnessClub\Database\Migration;

if (!defined('ABSPATH')) {
    exit();
}

/**
 * Table: fc_users
 *
 * Profile extension of a WP user. `wp_user_id` is the identity anchor; `id` is an
 * internal surrogate and is never exposed as "the user id" by the API.
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
  wp_user_id bigint(20) unsigned NOT NULL,
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
  UNIQUE KEY uq_wp_user (wp_user_id)
            ) {$this->charsetCollate};"
        );
    }
};
