<?php
/**
 * Migration: Add logs_websocket_url column to nodes table
 * This URL is where browsers connect directly for live logs
 */

return [
    'up' => function($db) {
        $col = $db->fetch("SHOW COLUMNS FROM nodes LIKE 'logs_websocket_url'");
        if (!$col) {
            $db->query("ALTER TABLE nodes ADD COLUMN logs_websocket_url VARCHAR(255) NULL AFTER description");
        }
    },
    'down' => function($db) {
        try {
            $db->query("ALTER TABLE nodes DROP COLUMN logs_websocket_url");
        } catch (\Throwable $e) {
            // Best-effort rollback.
        }
    }
];
