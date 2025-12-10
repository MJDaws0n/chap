<?php
/**
 * Create nodes table
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS nodes (
                id INT PRIMARY KEY AUTO_INCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                team_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                token VARCHAR(64) NOT NULL,
                status ENUM('pending', 'online', 'offline', 'error') DEFAULT 'pending',
                agent_version VARCHAR(20),
                docker_version VARCHAR(50),
                os_info VARCHAR(255),
                cpu_cores INT,
                memory_total BIGINT,
                disk_total BIGINT,
                last_seen_at TIMESTAMP NULL,
                settings JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                INDEX idx_uuid (uuid),
                INDEX idx_team_id (team_id),
                INDEX idx_token (token),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS nodes");
    }
];
