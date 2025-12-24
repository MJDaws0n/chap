<?php
/**
 * Migration: Create container_logs table for storing container log lines
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS container_logs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                container_id INT NOT NULL,
                application_id INT,
                node_id INT,
                level VARCHAR(20) DEFAULT 'info',
                line TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                INDEX idx_container_logs_container (container_id),
                INDEX idx_container_logs_application (application_id),
                INDEX idx_container_logs_created (created_at),
                
                FOREIGN KEY (container_id) REFERENCES containers(id) ON DELETE CASCADE,
                FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
                FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },

    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS container_logs");
    }
];
