<?php
/**
 * Add uuid column to node_port_ranges table.
 *
 * BaseModel::create() always writes a uuid, so every table needs a uuid column.
 */

return [
    'up' => function($db) {
        // 1) Add uuid column (nullable for backfill)
        $db->query("ALTER TABLE node_port_ranges ADD COLUMN uuid VARCHAR(36) NULL AFTER id");

        // 2) Backfill existing rows
        $db->query("UPDATE node_port_ranges SET uuid = UUID() WHERE uuid IS NULL OR uuid = ''");

        // 3) Enforce not-null + uniqueness
        $db->query("ALTER TABLE node_port_ranges MODIFY uuid VARCHAR(36) NOT NULL");
        $db->query("CREATE UNIQUE INDEX uniq_node_port_ranges_uuid ON node_port_ranges (uuid)");
    },
    'down' => function($db) {
        // Best-effort rollback
        $db->query("DROP INDEX uniq_node_port_ranges_uuid ON node_port_ranges");
        $db->query("ALTER TABLE node_port_ranges DROP COLUMN uuid");
    }
];
