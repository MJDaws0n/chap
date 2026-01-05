<?php
/**
 * Create password_resets table for password reset tokens
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS password_resets (
                email VARCHAR(255) PRIMARY KEY,
                token CHAR(64) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS password_resets");
    }
];
