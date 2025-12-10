<?php
/**
 * Create deployment_tasks table for queueing tasks to nodes
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS deployment_tasks (
                id INT PRIMARY KEY AUTO_INCREMENT,
                node_id INT NOT NULL,
                deployment_id INT,
                task_type VARCHAR(50) NOT NULL,
                task_data JSON NOT NULL,
                status ENUM('pending', 'sent', 'acknowledged', 'completed', 'failed') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
                FOREIGN KEY (deployment_id) REFERENCES deployments(id) ON DELETE SET NULL,
                INDEX idx_node_status (node_id, status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS deployment_tasks");
    }
];
