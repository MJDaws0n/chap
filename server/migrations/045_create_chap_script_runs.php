<?php
/**
 * Create chap_script_runs table for interactive template scripts.
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS chap_script_runs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                application_id INT NULL,
                template_slug VARCHAR(190) NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'running',
                state_json MEDIUMTEXT NULL,
                prompt_json MEDIUMTEXT NULL,
                context_json MEDIUMTEXT NULL,
                user_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_app (application_id),
                INDEX idx_status (status),
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS chap_script_runs");
    }
];
