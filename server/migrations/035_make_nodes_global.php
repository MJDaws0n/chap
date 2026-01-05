<?php
/**
 * Make nodes global (unowned)
 * - Drop the foreign key on nodes.team_id (if present)
 * - Make nodes.team_id nullable
 */

return [
    'up' => function($db) {
        // Drop FK constraint for nodes.team_id if it exists (name may vary).
                $fk = $db->fetch(
                        "SELECT CONSTRAINT_NAME
                         FROM information_schema.KEY_COLUMN_USAGE
                         WHERE TABLE_SCHEMA = DATABASE()
                             AND TABLE_NAME = 'nodes'
                             AND COLUMN_NAME = 'team_id'
                             AND REFERENCED_TABLE_NAME IS NOT NULL
                         LIMIT 1"
                );

        if (!empty($fk['CONSTRAINT_NAME'])) {
            $constraint = $fk['CONSTRAINT_NAME'];
            $db->query("ALTER TABLE nodes DROP FOREIGN KEY `{$constraint}`");
        }

        // Schema: team_id nullable
        $db->query("ALTER TABLE nodes MODIFY team_id INT NULL");

        // Existing data: clear ownership
        $db->query("UPDATE nodes SET team_id = NULL");
    },
    'down' => function($db) {
        // Recreate as NOT NULL with FK (best-effort): set NULLs to an existing team id.
        $team = $db->fetch("SELECT id FROM teams ORDER BY id LIMIT 1");
        $fallbackTeamId = (int)($team['id'] ?? 0);
        if ($fallbackTeamId > 0) {
            $db->query("UPDATE nodes SET team_id = {$fallbackTeamId} WHERE team_id IS NULL");
        }
        $db->query("ALTER TABLE nodes MODIFY team_id INT NOT NULL");

        // Restore FK (named so it can be dropped reliably in future).
        $db->query(
            "ALTER TABLE nodes \
             ADD CONSTRAINT fk_nodes_team_id \
             FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE"
        );
    }
];
