<?php
/**
 * Migration: Add `user_id` column to `applications` table
 * Uses the project's closure-style migration format so the migration
 * runner can pass in the active `$db` connection.
 */

return [
	'up' => function($db) {
		// Add nullable user_id column after uuid
		$db->query("ALTER TABLE applications ADD COLUMN user_id INT NULL AFTER uuid");
		// NOTE: Do not populate existing rows here automatically.
		// Run an UPDATE afterwards to set correct owners, for safety.
	},

	'down' => function($db) {
		// Remove the column when rolling back
		$db->query("ALTER TABLE applications DROP COLUMN user_id");
	}
];
