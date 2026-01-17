<?php
/**
 * Backfill volume permissions for existing installs.
 *
 * Fresh installs are covered by 040_create_team_roles.php.
 */

return [
    'up' => function($db) {
        $permKeys = ['volumes', 'volume_files'];

        $roles = $db->fetchAll(
            "SELECT id, slug FROM team_roles WHERE slug IN ('manager','member','read_only_member')"
        );

        foreach ($roles as $r) {
            $roleId = (int)($r['id'] ?? 0);
            if ($roleId <= 0) {
                continue;
            }

            $slug = (string)($r['slug'] ?? '');

            foreach ($permKeys as $permKey) {
                $existing = $db->fetch(
                    "SELECT id FROM team_role_permissions WHERE role_id = ? AND perm_key = ? LIMIT 1",
                    [$roleId, $permKey]
                );
                if ($existing) {
                    continue;
                }

                $canRead = 1;
                $canWrite = 0;
                $canExecute = 0;

                if ($slug === 'manager') {
                    $canWrite = 1;
                    $canExecute = 1;
                } elseif ($slug === 'member') {
                    // Members can browse volumes, and can edit volume files, but cannot delete volumes.
                    if ($permKey === 'volume_files') {
                        $canWrite = 1;
                    }
                }

                $db->insert('team_role_permissions', [
                    'role_id' => $roleId,
                    'perm_key' => $permKey,
                    'can_read' => $canRead,
                    'can_write' => $canWrite,
                    'can_execute' => $canExecute,
                ]);
            }
        }
    },
    'down' => function($db) {
        $db->query("DELETE FROM team_role_permissions WHERE perm_key IN ('volumes','volume_files')");
    }
];
