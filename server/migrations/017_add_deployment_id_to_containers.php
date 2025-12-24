<?php
/**
 * Add deployment_id column to containers table
 */

return [
    'up' => function($db) {
        $db->query("ALTER TABLE containers ADD COLUMN deployment_id INT DEFAULT NULL AFTER node_id");
        $db->query("ALTER TABLE containers ADD CONSTRAINT fk_containers_deployment_id FOREIGN KEY (deployment_id) REFERENCES deployments(id) ON DELETE SET NULL");
        $db->query("CREATE INDEX idx_deployment_id ON containers(deployment_id)");
    },
    'down' => function($db) {
        $db->query("ALTER TABLE containers DROP FOREIGN KEY fk_containers_deployment_id");
        $db->query("ALTER TABLE containers DROP COLUMN deployment_id");
    }
];
