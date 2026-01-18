<?php
/**
 * Create api_tokens table (v2 client tokens: PAT + session tokens)
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS api_tokens (
                id INT PRIMARY KEY AUTO_INCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                user_id INT NOT NULL,
                name VARCHAR(190) NULL,
                type ENUM('pat','session','deploy') NOT NULL DEFAULT 'pat',
                token_hash CHAR(64) NOT NULL,
                scopes JSON NULL,
                constraints JSON NULL,
                last_used_at TIMESTAMP NULL,
                expires_at TIMESTAMP NULL,
                revoked_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id),
                UNIQUE KEY uniq_token_hash (token_hash),
                INDEX idx_expires_at (expires_at),
                INDEX idx_revoked_at (revoked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS api_tokens");
    }
];
