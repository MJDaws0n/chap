<?php
/**
 * Backfill the `applications.resources` permission for existing built-in roles.
 *
 * For fresh installs, 040_create_team_roles.php already seeds this key.
 * This migration ensures existing installs also get sane defaults.
 */

return [
    'up' => function($db) {
        $permKey = 'applications.resources';

        $roles = $db->fetchAll(
            "SELECT id, slug FROM team_roles WHERE slug IN ('manager','member','read_only_member')"
        );

        foreach ($roles as $r) {
            $roleId = (int)($r['id'] ?? 0);
            if ($roleId <= 0) {
                continue;
            }

            $slug = (string)($r['slug'] ?? '');

            $existing = $db->fetch(
                "SELECT id FROM team_role_permissions WHERE role_id = ? AND perm_key = ? LIMIT 1",
                [$roleId, $permKey]
            );
            if ($existing) {
                continue;
            }

            $db->insert('team_role_permissions', [
                'role_id' => $roleId,
                'perm_key' => $permKey,
                'can_read' => 1,
                'can_write' => $slug === 'manager' ? 1 : 0,
                'can_execute' => 0,
            ]);
        }
    },
    'down' => function($db) {
        $db->query("DELETE FROM team_role_permissions WHERE perm_key = 'applications.resources'");
    }
];
