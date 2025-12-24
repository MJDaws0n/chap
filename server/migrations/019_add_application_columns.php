<?php
/**
 * Add missing columns to applications table
 */

return [
    'up' => function($db) {
        // Check which columns exist
        $columns = [];
        $result = $db->fetchAll("SHOW COLUMNS FROM applications");
        foreach ($result as $row) {
            $columns[] = $row['Field'];
        }
        
        // Add build_pack column
        if (!in_array('build_pack', $columns)) {
            $db->query("ALTER TABLE applications ADD COLUMN build_pack VARCHAR(50) DEFAULT 'dockerfile' AFTER git_commit_sha");
        }
        
        // Add build_context column
        if (!in_array('build_context', $columns)) {
            $db->query("ALTER TABLE applications ADD COLUMN build_context VARCHAR(500) DEFAULT '.' AFTER dockerfile_path");
        }
        
        // Add port column (singular, not ports JSON)
        if (!in_array('port', $columns)) {
            $db->query("ALTER TABLE applications ADD COLUMN port INT NULL AFTER build_context");
        }
        
        // Add domains column
        if (!in_array('domains', $columns)) {
            $db->query("ALTER TABLE applications ADD COLUMN domains TEXT NULL AFTER port");
        }
        
        // Add build_args column
        if (!in_array('build_args', $columns)) {
            $db->query("ALTER TABLE applications ADD COLUMN build_args JSON NULL AFTER environment_variables");
        }
        
        // Add health check columns
        if (!in_array('health_check_enabled', $columns)) {
            $db->query("ALTER TABLE applications ADD COLUMN health_check_enabled BOOLEAN DEFAULT TRUE AFTER memory_limit");
        }
        if (!in_array('health_check_path', $columns)) {
            $db->query("ALTER TABLE applications ADD COLUMN health_check_path VARCHAR(255) DEFAULT '/' AFTER health_check_enabled");
        }
        if (!in_array('health_check_interval', $columns)) {
            $db->query("ALTER TABLE applications ADD COLUMN health_check_interval INT DEFAULT 30 AFTER health_check_path");
        }
    },
    'down' => function($db) {
        $db->query("ALTER TABLE applications DROP COLUMN IF EXISTS build_pack");
        $db->query("ALTER TABLE applications DROP COLUMN IF EXISTS build_context");
        $db->query("ALTER TABLE applications DROP COLUMN IF EXISTS port");
        $db->query("ALTER TABLE applications DROP COLUMN IF EXISTS domains");
        $db->query("ALTER TABLE applications DROP COLUMN IF EXISTS build_args");
        $db->query("ALTER TABLE applications DROP COLUMN IF EXISTS health_check_enabled");
        $db->query("ALTER TABLE applications DROP COLUMN IF EXISTS health_check_path");
        $db->query("ALTER TABLE applications DROP COLUMN IF EXISTS health_check_interval");
    }
];
