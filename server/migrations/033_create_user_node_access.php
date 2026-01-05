<?php
/**
 * Create user_node_access table.
 * Admin assigns which nodes a user can access.
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS user_node_access (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                node_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
                UNIQUE KEY uniq_user_node (user_id, node_id),
                INDEX idx_user_id (user_id),
                INDEX idx_node_id (node_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS user_node_access");
    }
];
