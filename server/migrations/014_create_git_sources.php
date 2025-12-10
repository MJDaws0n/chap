<?php
/**
 * Create git_sources table for private deploy keys
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS git_sources (
                id INT PRIMARY KEY AUTO_INCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                team_id INT NOT NULL,
                
                -- Git source config
                name VARCHAR(255) NOT NULL,
                type ENUM('github', 'gitlab', 'bitbucket', 'custom') NOT NULL,
                base_url VARCHAR(500),
                api_url VARCHAR(500),
                
                -- Authentication
                is_oauth BOOLEAN DEFAULT FALSE,
                oauth_token TEXT,
                deploy_key_public TEXT,
                deploy_key_private TEXT,
                
                -- Status
                is_active BOOLEAN DEFAULT TRUE,
                
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                INDEX idx_uuid (uuid),
                INDEX idx_team_id (team_id),
                INDEX idx_type (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS git_sources");
    }
];
