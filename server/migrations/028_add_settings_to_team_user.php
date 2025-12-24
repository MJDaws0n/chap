<?php
/**
 * Add settings column to team_user
 */

return [
    'up' => function($db) {
        $db->query("ALTER TABLE team_user ADD COLUMN settings TEXT NULL AFTER role");
    },
    'down' => function($db) {
        $db->query("ALTER TABLE team_user DROP COLUMN settings");
    }
];
