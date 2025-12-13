<?php
/**
 * Migration: Add logs_websocket_url column to nodes table
 * This URL is where browsers connect directly for live logs
 */

return new class {
    public function up($db): void
    {
        $db->query("ALTER TABLE nodes ADD COLUMN logs_websocket_url VARCHAR(255) NULL AFTER description");
    }

    public function down($db): void
    {
        $db->query("ALTER TABLE nodes DROP COLUMN logs_websocket_url");
    }
};
