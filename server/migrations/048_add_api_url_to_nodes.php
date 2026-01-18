<?php
/**
 * Add api_url column to nodes table for client-facing node API base URL.
 */

return [
    'up' => function($db) {
        // Connection has no getColumnNames(); use MySQL's SHOW COLUMNS for idempotency.
        $col = $db->fetch("SHOW COLUMNS FROM nodes LIKE 'api_url'");
        if (!$col) {
            $db->query("ALTER TABLE nodes ADD COLUMN api_url VARCHAR(500) NULL AFTER logs_websocket_url");
        }

        // Create the index if missing.
        $idx = $db->fetch("SHOW INDEX FROM nodes WHERE Key_name = 'idx_api_url'");
        if (!$idx) {
            try {
                $db->query("CREATE INDEX idx_api_url ON nodes (api_url)");
            } catch (\Throwable $e) {
                // Best-effort: ignore if another migration run created it concurrently.
            }
        }
    },
    'down' => function($db) {
        // MySQL doesn't support DROP COLUMN IF EXISTS reliably across versions.
        try { $db->query("DROP INDEX idx_api_url ON nodes"); } catch (\Throwable $e) {}
        try { $db->query("ALTER TABLE nodes DROP COLUMN api_url"); } catch (\Throwable $e) {}
    }
];
