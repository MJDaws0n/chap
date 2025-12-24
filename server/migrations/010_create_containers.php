<?php
/**
 * Create containers table for tracking running containers
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS containers (
                id INT PRIMARY KEY AUTO_INCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                node_id INT NOT NULL,
                container_id VARCHAR(64) NOT NULL,
                name VARCHAR(255),
                image VARCHAR(500),
                
                -- Ownership - one of these will be set
                application_id INT,
                database_id INT,
                service_id INT,
                
                -- Status and metrics
                status ENUM('created', 'running', 'paused', 'restarting', 'exited', 'dead') DEFAULT 'created',
                ports JSON,
                networks JSON,
                volumes JSON,
                labels JSON,
                
                -- Resource usage (updated by node agent)
                cpu_usage DECIMAL(5,2),
                memory_usage BIGINT,
                memory_limit BIGINT,
                network_rx BIGINT,
                network_tx BIGINT,
                
                started_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
                FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL,
                FOREIGN KEY (database_id) REFERENCES `databases`(id) ON DELETE SET NULL,
                FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL,
                INDEX idx_uuid (uuid),
                INDEX idx_node_id (node_id),
                INDEX idx_container_id (container_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS containers");
    }
];
