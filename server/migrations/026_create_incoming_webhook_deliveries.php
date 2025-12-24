<?php
/**
 * Create incoming_webhook_deliveries table
 * 
 * Stores provider delivery IDs for deduplication and basic auditing.
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS incoming_webhook_deliveries (
                id INT PRIMARY KEY AUTO_INCREMENT,
                incoming_webhook_id INT NOT NULL,

                provider VARCHAR(50) NOT NULL,
                delivery_id VARCHAR(100) NOT NULL,
                event VARCHAR(50) NULL,
                ref VARCHAR(255) NULL,
                commit_sha VARCHAR(40) NULL,

                received_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

                FOREIGN KEY (incoming_webhook_id) REFERENCES incoming_webhooks(id) ON DELETE CASCADE,
                UNIQUE KEY uniq_incoming_webhook_delivery (incoming_webhook_id, delivery_id),
                INDEX idx_received_at (received_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS incoming_webhook_deliveries");
    }
];
