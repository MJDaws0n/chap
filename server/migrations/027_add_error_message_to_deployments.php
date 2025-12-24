<?php
/**
 * Add error_message to deployments
 */

return [
    'up' => function($db) {
        $exists = $db->fetch(
            "SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'deployments' AND column_name = 'error_message'"
        );
        if (($exists['c'] ?? 0) == 0) {
            $db->query("ALTER TABLE deployments ADD COLUMN error_message TEXT NULL AFTER logs");
        }
    },
    'down' => function($db) {
        // Drop column if it exists
        try {
            $db->query("ALTER TABLE deployments DROP COLUMN error_message");
        } catch (\Throwable $e) {
            // ignore
        }
    }
];
