<?php
/**
 * Add GitHub App fields to git_sources
 */

return [
    'up' => function($db) {
        // Add columns if they don't exist (MySQL 8 supports IF NOT EXISTS for ADD COLUMN)
        try {
            $db->query("ALTER TABLE git_sources ADD COLUMN auth_method ENUM('oauth','deploy_key','github_app') NULL AFTER api_url");
        } catch (\Throwable $e) {
            // ignore (column may already exist)
        }

        try {
            $db->query("ALTER TABLE git_sources ADD COLUMN github_app_id BIGINT NULL AFTER oauth_token");
        } catch (\Throwable $e) {
        }

        try {
            $db->query("ALTER TABLE git_sources ADD COLUMN github_app_installation_id BIGINT NULL AFTER github_app_id");
        } catch (\Throwable $e) {
        }

        try {
            $db->query("ALTER TABLE git_sources ADD COLUMN github_app_private_key TEXT NULL AFTER github_app_installation_id");
        } catch (\Throwable $e) {
        }

        try {
            $db->query("CREATE INDEX idx_git_sources_auth_method ON git_sources (auth_method)");
        } catch (\Throwable $e) {
        }

        try {
            $db->query("CREATE INDEX idx_git_sources_github_app_installation_id ON git_sources (github_app_installation_id)");
        } catch (\Throwable $e) {
        }

        // Backfill auth_method for existing rows
        $db->query("UPDATE git_sources SET auth_method = 'github_app' WHERE auth_method IS NULL AND github_app_id IS NOT NULL");
        $db->query("UPDATE git_sources SET auth_method = 'oauth' WHERE auth_method IS NULL AND is_oauth = 1");
        $db->query("UPDATE git_sources SET auth_method = 'deploy_key' WHERE auth_method IS NULL AND deploy_key_private IS NOT NULL");
    },
    'down' => function($db) {
        // Best-effort rollback
        try { $db->query("DROP INDEX idx_git_sources_auth_method ON git_sources"); } catch (\Throwable $e) {}
        try { $db->query("DROP INDEX idx_git_sources_github_app_installation_id ON git_sources"); } catch (\Throwable $e) {}

        try { $db->query("ALTER TABLE git_sources DROP COLUMN github_app_private_key"); } catch (\Throwable $e) {}
        try { $db->query("ALTER TABLE git_sources DROP COLUMN github_app_installation_id"); } catch (\Throwable $e) {}
        try { $db->query("ALTER TABLE git_sources DROP COLUMN github_app_id"); } catch (\Throwable $e) {}
        try { $db->query("ALTER TABLE git_sources DROP COLUMN auth_method"); } catch (\Throwable $e) {}
    }
];
