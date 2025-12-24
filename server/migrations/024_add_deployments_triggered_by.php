<?php
/**
 * Add triggered_by fields to deployments
 */

return [
    'up' => function($db) {
        $db->query(
            "ALTER TABLE deployments\n"
            . "  ADD COLUMN triggered_by VARCHAR(50) NULL AFTER user_id,\n"
            . "  ADD COLUMN triggered_by_name VARCHAR(255) NULL AFTER triggered_by"
        );
    },
    'down' => function($db) {
        $db->query(
            "ALTER TABLE deployments\n"
            . "  DROP COLUMN triggered_by_name,\n"
            . "  DROP COLUMN triggered_by"
        );
    }
];
