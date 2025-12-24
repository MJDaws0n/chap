<?php
/**
 * Create activity_logs table
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS activity_logs (
                id INT PRIMARY KEY AUTO_INCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                team_id INT,
                user_id INT,
                
                -- Action details
                action VARCHAR(100) NOT NULL,
                description TEXT,
                
                -- Subject (what was acted upon)
                subject_type VARCHAR(100),
                subject_id INT,
                
                -- Metadata
                properties JSON,
                ip_address VARCHAR(45),
                user_agent TEXT,
                
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_uuid (uuid),
                INDEX idx_team_id (team_id),
                INDEX idx_user_id (user_id),
                INDEX idx_action (action),
                INDEX idx_subject (subject_type, subject_id),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS activity_logs");
    }
];
