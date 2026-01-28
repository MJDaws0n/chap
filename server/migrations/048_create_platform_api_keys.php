<?php
/**
 * Create platform_api_keys table (platform-wide API keys)
 *
 * These keys are not attached to an end-user session. They are intended for automation/integrations
 * and are created/revoked by an admin via the web UI.
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS platform_api_keys (
                id INT PRIMARY KEY AUTO_INCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                name VARCHAR(190) NULL,
                token_hash CHAR(64) NOT NULL,
                scopes JSON NULL,
                constraints JSON NULL,
                last_used_at TIMESTAMP NULL,
                expires_at TIMESTAMP NULL,
                revoked_at TIMESTAMP NULL,
                created_by_user_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_platform_token_hash (token_hash),
                INDEX idx_platform_expires_at (expires_at),
                INDEX idx_platform_revoked_at (revoked_at),
                INDEX idx_platform_created_by_user_id (created_by_user_id),
                FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS platform_api_keys");
    }
];
