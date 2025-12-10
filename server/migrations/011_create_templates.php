<?php
/**
 * Create templates table for one-click services
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS templates (
                id INT PRIMARY KEY AUTO_INCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) UNIQUE NOT NULL,
                description TEXT,
                category VARCHAR(100),
                icon VARCHAR(255),
                
                -- Template configuration
                docker_compose TEXT NOT NULL,
                documentation TEXT,
                default_environment_variables JSON,
                required_environment_variables JSON,
                ports JSON,
                volumes JSON,
                
                -- Metadata
                version VARCHAR(50),
                source_url VARCHAR(500),
                is_official BOOLEAN DEFAULT FALSE,
                is_active BOOLEAN DEFAULT TRUE,
                
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                
                INDEX idx_uuid (uuid),
                INDEX idx_slug (slug),
                INDEX idx_category (category),
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS templates");
    }
];
