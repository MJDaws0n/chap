<?php
/**
 * Create port_allocations table
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS port_allocations (
                id INT PRIMARY KEY AUTO_INCREMENT,
                node_id INT NOT NULL,
                application_id INT NULL,
                reservation_uuid VARCHAR(64) NULL,
                port INT NOT NULL,
                expires_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
                FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,

                UNIQUE KEY uniq_node_port (node_id, port),
                INDEX idx_application_id (application_id),
                INDEX idx_reservation_uuid (reservation_uuid),
                INDEX idx_expires_at (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS port_allocations");
    }
];
