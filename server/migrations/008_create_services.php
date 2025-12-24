<?php
/**
 * Create services table for one-click services
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS services (
                id INT PRIMARY KEY AUTO_INCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                environment_id INT NOT NULL,
                node_id INT,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                template_id INT,
                
                -- Service configuration
                docker_compose TEXT,
                environment_variables JSON,
                volumes JSON,
                ports JSON,
                
                -- Status
                status ENUM('draft', 'creating', 'running', 'stopped', 'error') DEFAULT 'draft',
                
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (environment_id) REFERENCES environments(id) ON DELETE CASCADE,
                FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE SET NULL,
                INDEX idx_uuid (uuid),
                INDEX idx_environment_id (environment_id),
                INDEX idx_node_id (node_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS services");
    }
];
