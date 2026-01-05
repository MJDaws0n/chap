<?php
/**
 * Create node_port_ranges table
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS node_port_ranges (
                id INT PRIMARY KEY AUTO_INCREMENT,
                node_id INT NOT NULL,
                start_port INT NOT NULL,
                end_port INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
                INDEX idx_node_id (node_id),
                INDEX idx_node_start_end (node_id, start_port, end_port)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS node_port_ranges");
    }
];
