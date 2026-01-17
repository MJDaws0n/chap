<?php
/**
 * Add extra_files JSON to templates
 */

return [
    'up' => function($db) {
        $columns = [];
        $result = $db->fetchAll('SHOW COLUMNS FROM templates');
        foreach ($result as $row) {
            $columns[] = $row['Field'];
        }

        if (!in_array('extra_files', $columns)) {
            $db->query('ALTER TABLE templates ADD COLUMN extra_files JSON NULL AFTER volumes');
        }
    },
    'down' => function($db) {
        $db->query('ALTER TABLE templates DROP COLUMN IF EXISTS extra_files');
    }
];
