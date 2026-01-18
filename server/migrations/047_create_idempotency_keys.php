<?php
/**
 * Create idempotency_keys table (v2 mutating endpoints)
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS idempotency_keys (
                id INT PRIMARY KEY AUTO_INCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                api_token_id INT NOT NULL,
                idempotency_key VARCHAR(190) NOT NULL,
                method VARCHAR(10) NOT NULL,
                path VARCHAR(500) NOT NULL,
                status_code INT NOT NULL,
                response_body MEDIUMTEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                FOREIGN KEY (api_token_id) REFERENCES api_tokens(id) ON DELETE CASCADE,
                UNIQUE KEY uniq_token_key_method_path (api_token_id, idempotency_key, method, path),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS idempotency_keys");
    }
];
