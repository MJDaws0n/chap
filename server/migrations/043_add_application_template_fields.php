<?php
/**
 * Add template fields to applications for template-based deploys
 */

return [
    'up' => function($db) {
        $columns = [];
        $result = $db->fetchAll('SHOW COLUMNS FROM applications');
        foreach ($result as $row) {
            $columns[] = $row['Field'];
        }

        if (!in_array('template_slug', $columns)) {
            $db->query("ALTER TABLE applications ADD COLUMN template_slug VARCHAR(255) NULL AFTER build_context");
        }
        if (!in_array('template_version', $columns)) {
            $db->query("ALTER TABLE applications ADD COLUMN template_version VARCHAR(50) NULL AFTER template_slug");
        }
        if (!in_array('template_docker_compose', $columns)) {
            $db->query("ALTER TABLE applications ADD COLUMN template_docker_compose TEXT NULL AFTER template_version");
        }
        if (!in_array('template_extra_files', $columns)) {
            $db->query("ALTER TABLE applications ADD COLUMN template_extra_files JSON NULL AFTER template_docker_compose");
        }

        // Helpful index for lookups
        try {
            $db->query('CREATE INDEX idx_applications_template_slug ON applications (template_slug)');
        } catch (\Throwable $e) {
            // ignore (may already exist)
        }
    },
    'down' => function($db) {
        $db->query('ALTER TABLE applications DROP COLUMN IF EXISTS template_extra_files');
        $db->query('ALTER TABLE applications DROP COLUMN IF EXISTS template_docker_compose');
        $db->query('ALTER TABLE applications DROP COLUMN IF EXISTS template_version');
        $db->query('ALTER TABLE applications DROP COLUMN IF EXISTS template_slug');
        try {
            $db->query('DROP INDEX idx_applications_template_slug ON applications');
        } catch (\Throwable $e) {
            // ignore
        }
    }
];
