<?php
/**
 * Create team roles, permissions, and multi-role assignments.
 *
 * - Adds `team_roles`, `team_role_permissions`, `team_user_roles`
 * - Seeds built-in roles per team (Admin/Manager/Member/Read-only Member)
 * - Backfills existing `team_user.role` (admin/member) into `team_user_roles`
 *   (Owner remains implicit via `team_user.role = 'owner'`)
 */

return [
    'up' => function($db) {
        $db->query("
            CREATE TABLE IF NOT EXISTS team_roles (
                id INT PRIMARY KEY AUTO_INCREMENT,
                team_id INT NOT NULL,
                uuid VARCHAR(36) UNIQUE NOT NULL,
                name VARCHAR(80) NOT NULL,
                slug VARCHAR(80) NOT NULL,
                is_builtin TINYINT(1) NOT NULL DEFAULT 0,
                is_locked TINYINT(1) NOT NULL DEFAULT 0,
                hierarchy_level INT NOT NULL DEFAULT 0,
                created_by_user_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_team_role_slug (team_id, slug),
                INDEX idx_team_roles_team (team_id),
                CONSTRAINT fk_team_roles_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                CONSTRAINT fk_team_roles_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS team_role_permissions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                role_id INT NOT NULL,
                perm_key VARCHAR(120) NOT NULL,
                can_read TINYINT(1) NOT NULL DEFAULT 0,
                can_write TINYINT(1) NOT NULL DEFAULT 0,
                can_execute TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_role_perm (role_id, perm_key),
                INDEX idx_role_perm_role (role_id),
                CONSTRAINT fk_team_role_permissions_role FOREIGN KEY (role_id) REFERENCES team_roles(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->query("
            CREATE TABLE IF NOT EXISTS team_user_roles (
                id INT PRIMARY KEY AUTO_INCREMENT,
                team_id INT NOT NULL,
                user_id INT NOT NULL,
                role_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_team_user_role (team_id, user_id, role_id),
                INDEX idx_team_user_roles_team_user (team_id, user_id),
                CONSTRAINT fk_team_user_roles_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                CONSTRAINT fk_team_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_team_user_roles_role FOREIGN KEY (role_id) REFERENCES team_roles(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Seed built-in roles for every team (idempotent).
        $teams = $db->fetchAll("SELECT id FROM teams");

        $builtin = [
            // Owner is implicit via team_user.role and cannot be assigned manually.
            ['name' => 'Admin', 'slug' => 'admin', 'level' => 80],
            ['name' => 'Manager', 'slug' => 'manager', 'level' => 60],
            ['name' => 'Member', 'slug' => 'member', 'level' => 40],
            ['name' => 'Read-only Member', 'slug' => 'read_only_member', 'level' => 20],
        ];

        foreach ($teams as $t) {
            $teamId = (int)($t['id'] ?? 0);
            if ($teamId <= 0) {
                continue;
            }

            foreach ($builtin as $r) {
                $existing = $db->fetch(
                    "SELECT id FROM team_roles WHERE team_id = ? AND slug = ? LIMIT 1",
                    [$teamId, $r['slug']]
                );
                if ($existing) {
                    continue;
                }
                $db->insert('team_roles', [
                    'team_id' => $teamId,
                    'uuid' => uuid(),
                    'name' => $r['name'],
                    'slug' => $r['slug'],
                    'is_builtin' => 1,
                    'is_locked' => 1,
                    'hierarchy_level' => (int)$r['level'],
                    'created_by_user_id' => null,
                ]);
            }
        }

        // Seed default permissions for built-in roles per team (idempotent).
        // NOTE: Owner/Admin are handled in code (full access); built-in rows below define Manager/Member/Read-only.
        $permissionKeys = [
            // Team
            'team.settings',
            'team.members',
            'team.roles',
            // Core resources
            'projects',
            'environments',
            'applications',
            'files',
            'deployments',
            'logs',
            'databases',
            'services',
            'templates',
            'git_sources',
            'activity',
        ];

        $teams = $db->fetchAll("SELECT id FROM teams");
        foreach ($teams as $t) {
            $teamId = (int)($t['id'] ?? 0);
            if ($teamId <= 0) {
                continue;
            }

            $roles = $db->fetchAll(
                "SELECT id, slug FROM team_roles WHERE team_id = ? AND slug IN ('manager','member','read_only_member')",
                [$teamId]
            );

            $roleIdBySlug = [];
            foreach ($roles as $row) {
                $roleIdBySlug[(string)$row['slug']] = (int)$row['id'];
            }

            $matrix = [
                // Manager: full operational, can manage members; can view roles.
                'manager' => [
                    'team.settings' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'team.members' => ['r' => 1, 'w' => 0, 'x' => 1],
                    'team.roles' => ['r' => 1, 'w' => 0, 'x' => 0],

                    'projects' => ['r' => 1, 'w' => 1, 'x' => 0],
                    'environments' => ['r' => 1, 'w' => 1, 'x' => 0],
                    'applications' => ['r' => 1, 'w' => 1, 'x' => 1],
                    'files' => ['r' => 1, 'w' => 1, 'x' => 0],
                    'deployments' => ['r' => 1, 'w' => 0, 'x' => 1],
                    'logs' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'databases' => ['r' => 1, 'w' => 1, 'x' => 1],
                    'services' => ['r' => 1, 'w' => 1, 'x' => 1],
                    'templates' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'git_sources' => ['r' => 1, 'w' => 1, 'x' => 0],
                    'activity' => ['r' => 1, 'w' => 0, 'x' => 0],
                ],
                // Member: operational, no member/role management.
                'member' => [
                    'team.settings' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'team.members' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'team.roles' => ['r' => 0, 'w' => 0, 'x' => 0],

                    'projects' => ['r' => 1, 'w' => 1, 'x' => 0],
                    'environments' => ['r' => 1, 'w' => 1, 'x' => 0],
                    'applications' => ['r' => 1, 'w' => 1, 'x' => 1],
                    'files' => ['r' => 1, 'w' => 1, 'x' => 0],
                    'deployments' => ['r' => 1, 'w' => 0, 'x' => 1],
                    'logs' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'databases' => ['r' => 1, 'w' => 1, 'x' => 1],
                    'services' => ['r' => 1, 'w' => 1, 'x' => 1],
                    'templates' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'git_sources' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'activity' => ['r' => 1, 'w' => 0, 'x' => 0],
                ],
                // Read-only: browse + logs, no write/execute anywhere.
                'read_only_member' => [
                    'team.settings' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'team.members' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'team.roles' => ['r' => 0, 'w' => 0, 'x' => 0],

                    'projects' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'environments' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'applications' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'files' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'deployments' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'logs' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'databases' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'services' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'templates' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'git_sources' => ['r' => 1, 'w' => 0, 'x' => 0],
                    'activity' => ['r' => 1, 'w' => 0, 'x' => 0],
                ],
            ];

            foreach ($matrix as $slug => $perms) {
                $roleId = (int)($roleIdBySlug[$slug] ?? 0);
                if ($roleId <= 0) {
                    continue;
                }

                foreach ($permissionKeys as $k) {
                    $flags = $perms[$k] ?? ['r' => 0, 'w' => 0, 'x' => 0];

                    $existing = $db->fetch(
                        "SELECT id FROM team_role_permissions WHERE role_id = ? AND perm_key = ? LIMIT 1",
                        [$roleId, $k]
                    );
                    if ($existing) {
                        continue;
                    }

                    $db->insert('team_role_permissions', [
                        'role_id' => $roleId,
                        'perm_key' => $k,
                        'can_read' => (int)$flags['r'],
                        'can_write' => (int)$flags['w'],
                        'can_execute' => (int)$flags['x'],
                    ]);
                }
            }
        }

        // Backfill existing team_user.role to assignments.
        // - owner: implicit, do not create assignment
        // - admin: assign Admin
        // - member: assign Member
        $all = $db->fetchAll("SELECT team_id, user_id, role FROM team_user");
        foreach ($all as $row) {
            $teamId = (int)($row['team_id'] ?? 0);
            $userId = (int)($row['user_id'] ?? 0);
            $role = (string)($row['role'] ?? 'member');
            if ($teamId <= 0 || $userId <= 0) {
                continue;
            }
            if ($role === 'owner') {
                continue;
            }

            $slug = $role === 'admin' ? 'admin' : 'member';
            $r = $db->fetch("SELECT id FROM team_roles WHERE team_id = ? AND slug = ? LIMIT 1", [$teamId, $slug]);
            if (!$r) {
                continue;
            }

            $roleId = (int)$r['id'];
            $exists = $db->fetch(
                "SELECT id FROM team_user_roles WHERE team_id = ? AND user_id = ? AND role_id = ? LIMIT 1",
                [$teamId, $userId, $roleId]
            );
            if ($exists) {
                continue;
            }
            $db->insert('team_user_roles', [
                'team_id' => $teamId,
                'user_id' => $userId,
                'role_id' => $roleId,
            ]);
        }

        // Ensure every non-owner member has at least one role.
        $teams = $db->fetchAll("SELECT id FROM teams");
        foreach ($teams as $t) {
            $teamId = (int)($t['id'] ?? 0);
            if ($teamId <= 0) {
                continue;
            }
            $memberRole = $db->fetch("SELECT id FROM team_roles WHERE team_id = ? AND slug = 'member' LIMIT 1", [$teamId]);
            if (!$memberRole) {
                continue;
            }
            $memberRoleId = (int)$memberRole['id'];

            $users = $db->fetchAll(
                "SELECT tu.user_id FROM team_user tu
                 LEFT JOIN team_user_roles tur ON tur.team_id = tu.team_id AND tur.user_id = tu.user_id
                 WHERE tu.team_id = ? AND tu.role <> 'owner'
                 GROUP BY tu.user_id
                 HAVING COUNT(tur.id) = 0",
                [$teamId]
            );
            foreach ($users as $u) {
                $userId = (int)($u['user_id'] ?? 0);
                if ($userId <= 0) {
                    continue;
                }
                $db->insert('team_user_roles', [
                    'team_id' => $teamId,
                    'user_id' => $userId,
                    'role_id' => $memberRoleId,
                ]);
            }
        }
    },
    'down' => function($db) {
        $db->query("DROP TABLE IF EXISTS team_user_roles");
        $db->query("DROP TABLE IF EXISTS team_role_permissions");
        $db->query("DROP TABLE IF EXISTS team_roles");
    }
];
