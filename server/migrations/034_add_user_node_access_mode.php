<?php
/**
 * Add per-user node access mode.
 *
 * Modes:
 * - allow_selected: user may use ONLY nodes listed in user_node_access
 * - allow_all_except: user may use ALL nodes except those listed in user_node_access
 */

return [
    'up' => function($db) {
        $columns = [];
        foreach ($db->fetchAll("SHOW COLUMNS FROM users") as $row) {
            $columns[] = $row['Field'];
        }

        if (!in_array('node_access_mode', $columns, true)) {
            $db->query("ALTER TABLE users ADD COLUMN node_access_mode VARCHAR(32) NOT NULL DEFAULT 'allow_selected' AFTER max_pids");
        }
    },

    'down' => function($db) {
        $columns = [];
        foreach ($db->fetchAll("SHOW COLUMNS FROM users") as $row) {
            $columns[] = $row['Field'];
        }

        if (in_array('node_access_mode', $columns, true)) {
            $db->query("ALTER TABLE users DROP COLUMN node_access_mode");
        }
    },
];
