<?php
/**
 * Create project_user join table (roles + per-user project settings)
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS project_user (
                id INT PRIMARY KEY AUTO_INCREMENT,
                project_id INT NOT NULL,
                user_id INT NOT NULL,
                role ENUM('admin', 'member', 'viewer') DEFAULT 'member',
                settings TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY unique_project_user (project_id, user_id),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS project_user");
    }
];
