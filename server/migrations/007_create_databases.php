<?php
/**
 * Create databases table for managed databases
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS `databases` (
                id INT PRIMARY KEY AUTO_INCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                environment_id INT NOT NULL,
                node_id INT,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                type ENUM('mysql', 'mariadb', 'postgresql', 'mongodb', 'redis', 'memcached') NOT NULL,
                version VARCHAR(50),
                
                -- Connection settings
                internal_host VARCHAR(255),
                internal_port INT,
                public_port INT,
                root_password TEXT,
                database_name VARCHAR(255),
                username VARCHAR(255),
                password TEXT,
                
                -- Resource limits
                cpu_limit VARCHAR(20),
                memory_limit VARCHAR(20),
                storage_limit VARCHAR(20),
                
                -- Volumes
                volumes JSON,
                
                -- Status
                status ENUM('draft', 'creating', 'running', 'stopped', 'error') DEFAULT 'draft',
                container_id VARCHAR(64),
                
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (environment_id) REFERENCES environments(id) ON DELETE CASCADE,
                FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE SET NULL,
                INDEX idx_uuid (uuid),
                INDEX idx_environment_id (environment_id),
                INDEX idx_node_id (node_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS `databases`");
    }
];
