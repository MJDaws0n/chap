<?php
/**
 * Create team_invitations table
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS team_invitations (
                id INT PRIMARY KEY AUTO_INCREMENT,
                uuid VARCHAR(36) UNIQUE NOT NULL,

                team_id INT NOT NULL,
                inviter_user_id INT NOT NULL,
                invitee_user_id INT NULL,

                email VARCHAR(190) NOT NULL,
                token_hash CHAR(64) NOT NULL,

                status ENUM('pending','accepted','declined','revoked') NOT NULL DEFAULT 'pending',

                base_role_slug VARCHAR(64) NOT NULL DEFAULT 'member',
                custom_role_ids JSON NULL,

                accepted_by_user_id INT NULL,
                accepted_at TIMESTAMP NULL,
                declined_at TIMESTAMP NULL,
                revoked_at TIMESTAMP NULL,

                expires_at TIMESTAMP NULL,

                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                FOREIGN KEY (inviter_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (invitee_user_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (accepted_by_user_id) REFERENCES users(id) ON DELETE SET NULL,

                UNIQUE KEY uniq_token_hash (token_hash),
                INDEX idx_team_id (team_id),
                INDEX idx_email (email),
                INDEX idx_status (status),
                INDEX idx_expires_at (expires_at),
                INDEX idx_team_email_status (team_id, email, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS team_invitations");
    }
];
