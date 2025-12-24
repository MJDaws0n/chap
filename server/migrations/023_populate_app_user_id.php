<?php
/**
 * Migration: Populate missing `user_id` on `applications` table
 *
 * WARNING: This will set `user_id = 1` for any application rows where
 * `user_id` is currently NULL. If you want a different mapping, update
 * this migration before running it.
 */

return [
    'up' => function($db) {
        // Populate NULL user_id with a sensible default (user_id = 1)
        $db->query("UPDATE applications SET user_id = 1 WHERE user_id IS NULL");
    },

    'down' => function($db) {
        // Revert the above: set to NULL where we set to 1
        // Be cautious: this will NULL-out any rows with user_id=1
        $db->query("UPDATE applications SET user_id = NULL WHERE user_id = 1");
    }
];
