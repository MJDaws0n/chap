<?php
/**
 * Create applications table
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS applications (
                id INT PRIMARY KEY AUTO_INCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                environment_id INT NOT NULL,
                node_id INT,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                type ENUM('git', 'docker', 'dockerfile', 'compose') DEFAULT 'git',
                
                -- Git settings
                git_repository VARCHAR(500),
                git_branch VARCHAR(255) DEFAULT 'main',
                git_commit_sha VARCHAR(40),
                git_provider ENUM('github', 'gitlab', 'bitbucket', 'custom') DEFAULT 'github',
                git_token TEXT,
                
                -- Docker settings  
                docker_image VARCHAR(500),
                docker_registry VARCHAR(255),
                docker_registry_username VARCHAR(255),
                docker_registry_password TEXT,
                dockerfile_path VARCHAR(500) DEFAULT 'Dockerfile',
                docker_compose_path VARCHAR(500) DEFAULT 'docker-compose.yml',
                docker_context VARCHAR(500) DEFAULT '.',
                
                -- Build settings
                build_command TEXT,
                install_command TEXT,
                start_command TEXT,
                base_directory VARCHAR(500) DEFAULT '/',
                publish_directory VARCHAR(500),
                
                -- Runtime settings
                ports JSON,
                environment_variables JSON,
                secrets JSON,
                volumes JSON,
                labels JSON,
                health_check JSON,
                
                -- Resource limits
                cpu_limit VARCHAR(20),
                memory_limit VARCHAR(20),
                
                -- Status
                status ENUM('draft', 'building', 'deploying', 'running', 'stopped', 'error') DEFAULT 'draft',
                auto_deploy BOOLEAN DEFAULT TRUE,
                
                -- Timestamps
                last_deployed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                FOREIGN KEY (environment_id) REFERENCES environments(id) ON DELETE CASCADE,
                FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE SET NULL,
                INDEX idx_uuid (uuid),
                INDEX idx_environment_id (environment_id),
                INDEX idx_node_id (node_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS applications");
    }
];
