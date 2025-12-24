<?php
/**
 * Add triggered_by + triggered_by_name columns to deployments
 */

return [
    'up' => function($db) {
        $columns = [];
        $result = $db->fetchAll("SHOW COLUMNS FROM deployments");
        foreach ($result as $row) {
            $columns[] = $row['Field'];
        }

        if (!in_array('triggered_by', $columns)) {
            $db->query("ALTER TABLE deployments ADD COLUMN triggered_by VARCHAR(50) NULL AFTER user_id");
            $db->query("CREATE INDEX idx_deployments_triggered_by ON deployments(triggered_by)");
        }

        if (!in_array('triggered_by_name', $columns)) {
            $db->query("ALTER TABLE deployments ADD COLUMN triggered_by_name VARCHAR(255) NULL AFTER triggered_by");
        }
    },
    'down' => function($db) {
        $indexes = $db->fetchAll("SHOW INDEX FROM deployments WHERE Key_name = 'idx_deployments_triggered_by'") ?: [];
        if (!empty($indexes)) {
            $db->query("DROP INDEX idx_deployments_triggered_by ON deployments");
        }

        $columns = [];
        $result = $db->fetchAll("SHOW COLUMNS FROM deployments") ?: [];
        foreach ($result as $row) {
            $columns[] = $row['Field'];
        }

        $dropParts = [];
        if (in_array('triggered_by_name', $columns, true)) {
            $dropParts[] = "DROP COLUMN triggered_by_name";
        }
        if (in_array('triggered_by', $columns, true)) {
            $dropParts[] = "DROP COLUMN triggered_by";
        }

        if (!empty($dropParts)) {
            $db->query("ALTER TABLE deployments " . implode(", ", $dropParts));
        }
    }
];

