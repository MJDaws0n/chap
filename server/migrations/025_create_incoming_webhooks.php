<?php
/**
 * Create incoming_webhooks table
 * 
 * Incoming webhooks are used to trigger deployments (e.g. GitHub push events).
 * Multiple incoming webhooks can be created per application.
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS incoming_webhooks (
                id INT PRIMARY KEY AUTO_INCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                application_id INT NOT NULL,

                provider ENUM('github', 'gitlab', 'bitbucket', 'custom') NOT NULL DEFAULT 'github',
                name VARCHAR(255) NOT NULL,
                secret VARCHAR(255) NOT NULL,
                branch VARCHAR(255) NULL,

                is_active BOOLEAN DEFAULT TRUE,

                last_received_at TIMESTAMP NULL,
                last_event VARCHAR(50) NULL,
                last_delivery_id VARCHAR(100) NULL,
                last_status VARCHAR(50) NULL,
                last_error TEXT NULL,

                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
                INDEX idx_uuid (uuid),
                INDEX idx_application_id (application_id),
                INDEX idx_provider (provider),
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS incoming_webhooks");
    }
];
