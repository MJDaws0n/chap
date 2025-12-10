<?php
/**
 * Create deployments table
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS deployments (
                id INT PRIMARY KEY AUTO_INCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                application_id INT NOT NULL,
                node_id INT,
                user_id INT,
                
                -- Deployment info
                type ENUM('deploy', 'rollback', 'restart', 'stop') DEFAULT 'deploy',
                status ENUM('queued', 'building', 'deploying', 'running', 'stopped', 'failed', 'cancelled') DEFAULT 'queued',
                
                -- Git info snapshot
                git_commit_sha VARCHAR(40),
                git_commit_message TEXT,
                git_branch VARCHAR(255),
                
                -- Container info
                container_id VARCHAR(64),
                image_tag VARCHAR(255),
                
                -- Logs and timing
                logs LONGTEXT,
                started_at TIMESTAMP NULL,
                finished_at TIMESTAMP NULL,
                
                -- Rollback reference
                rollback_to_deployment_id INT,
                
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
                FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE SET NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (rollback_to_deployment_id) REFERENCES deployments(id) ON DELETE SET NULL,
                INDEX idx_uuid (uuid),
                INDEX idx_application_id (application_id),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS deployments");
    }
];
