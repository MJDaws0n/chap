<?php
/**
 * Add port_cursor column to nodes table
 */

return [
    'up' => function($db) {
        $columns = [];
        $result = $db->fetchAll("SHOW COLUMNS FROM nodes");
        foreach ($result as $row) {
            $columns[] = $row['Field'];
        }

        if (!in_array('port_cursor', $columns)) {
            $db->query("ALTER TABLE nodes ADD COLUMN port_cursor INT NULL AFTER settings");
        }
    },
    'down' => function($db) {
        $db->query("ALTER TABLE nodes DROP COLUMN IF EXISTS port_cursor");
    }
];
