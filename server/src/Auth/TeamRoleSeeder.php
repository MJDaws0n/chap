<?php

namespace Chap\Auth;

use Chap\App;

/**
 * Ensures built-in team roles exist for a given team.
 *
 * This is needed for teams created after the migration ran.
 */
final class TeamRoleSeeder
{
    private function __construct() {}

    public static function ensureBuiltins(int $teamId): void
    {
        $teamId = (int)$teamId;
        if ($teamId <= 0) {
            return;
        }

        $db = App::db();

        $builtin = [
            ['name' => 'Admin', 'slug' => 'admin', 'level' => 80],
            ['name' => 'Manager', 'slug' => 'manager', 'level' => 60],
            ['name' => 'Member', 'slug' => 'member', 'level' => 40],
            ['name' => 'Read-only Member', 'slug' => 'read_only_member', 'level' => 20],
        ];

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

        $roles = $db->fetchAll(
            "SELECT id, slug FROM team_roles WHERE team_id = ? AND slug IN ('manager','member','read_only_member')",
            [$teamId]
        );
        $roleIdBySlug = [];
        foreach ($roles as $row) {
            $roleIdBySlug[(string)$row['slug']] = (int)$row['id'];
        }

        $matrix = [
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
                'templates' => ['r' => 1, 'w' => 0, 'x' => 0],
                'git_sources' => ['r' => 1, 'w' => 1, 'x' => 0],
                'activity' => ['r' => 1, 'w' => 0, 'x' => 0],
            ],
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
                'templates' => ['r' => 1, 'w' => 0, 'x' => 0],
                'git_sources' => ['r' => 1, 'w' => 0, 'x' => 0],
                'activity' => ['r' => 1, 'w' => 0, 'x' => 0],
            ],
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
            foreach (TeamPermissions::KEYS as $k) {
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
}
