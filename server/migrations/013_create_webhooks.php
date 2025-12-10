<?php
/**
 * Create webhooks table
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS webhooks (
                id INT PRIMARY KEY AUTO_INCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                team_id INT NOT NULL,
                application_id INT,
                
                -- Webhook config
                name VARCHAR(255) NOT NULL,
                url VARCHAR(500) NOT NULL,
                secret VARCHAR(255),
                events JSON NOT NULL,
                
                -- Status
                is_active BOOLEAN DEFAULT TRUE,
                last_triggered_at TIMESTAMP NULL,
                last_response_code INT,
                
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
                INDEX idx_uuid (uuid),
                INDEX idx_team_id (team_id),
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS webhooks");
    }
];
