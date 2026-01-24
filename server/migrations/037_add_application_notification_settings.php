<?php
/**
 * Add notification_settings to applications
 */

return [
    'up' => function($db) {
        $columns = [];
        $result = $db->fetchAll("SHOW COLUMNS FROM applications");
        foreach ($result as $row) {
            $columns[] = $row['Field'];
        }

        if (!in_array('notification_settings', $columns, true)) {
            $db->query("ALTER TABLE applications ADD COLUMN notification_settings JSON NULL AFTER health_check_interval");
        }
    },
    'down' => function($db) {
        $db->query("ALTER TABLE applications DROP COLUMN IF EXISTS notification_settings");
    }
];
