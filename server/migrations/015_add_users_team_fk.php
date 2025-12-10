<?php
/**
 * Add current_team_id column and foreign key to users table
 */

return [
    'up' => function($db) {
        // First add the column
        $db->query("
            ALTER TABLE users
            ADD COLUMN current_team_id INT NULL AFTER is_admin
        ");
        
        // Then add the foreign key
        $db->query("
            ALTER TABLE users
            ADD CONSTRAINT fk_users_current_team
            FOREIGN KEY (current_team_id) REFERENCES teams(id) ON DELETE SET NULL
        ");
    },
    'down' => function($db) {
        $db->query("ALTER TABLE users DROP FOREIGN KEY fk_users_current_team");
        $db->query("ALTER TABLE users DROP COLUMN current_team_id");
    }
];
