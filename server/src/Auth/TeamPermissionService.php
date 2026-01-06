<?php

namespace Chap\Auth;

use Chap\App;

/**
 * Team role + permission evaluation.
 *
 * Effective permissions are the union of all assigned roles.
 * Owner is implicit (team_user.role='owner') and always has full access.
 */
final class TeamPermissionService
{
    /** @var array<int, array<int, array<string, array{read:bool, write:bool, execute:bool}>>> */
    private static array $permCache = [];
    /** @var array<int, array<int, array{level:int, slug:string}>> */
    private static array $highestRoleCache = [];
    /** @var array<int, array<int, string[]>> */
    private static array $roleSlugsCache = [];

    private function __construct() {}

    public static function clearCache(?int $teamId = null, ?int $userId = null): void
    {
        if ($teamId === null) {
            self::$permCache = [];
            self::$highestRoleCache = [];
            self::$roleSlugsCache = [];
            return;
        }

        if ($userId === null) {
            unset(self::$permCache[$teamId], self::$highestRoleCache[$teamId], self::$roleSlugsCache[$teamId]);
            return;
        }

        unset(self::$permCache[$teamId][$userId], self::$highestRoleCache[$teamId][$userId], self::$roleSlugsCache[$teamId][$userId]);
    }

    public static function can(int $teamId, int $userId, string $permKey, string $action): bool
    {
        if (admin_view_all()) {
            return true;
        }

        $action = strtolower($action);
        if (!in_array($action, ['read', 'write', 'execute'], true)) {
            return false;
        }

        if (!in_array($permKey, TeamPermissions::KEYS, true)) {
            return false;
        }

        $perms = self::effectivePermissions($teamId, $userId);
        $flags = $perms[$permKey] ?? ['read' => false, 'write' => false, 'execute' => false];
        return (bool)($flags[$action] ?? false);
    }

    /** @return array<string, array{read:bool, write:bool, execute:bool}> */
    public static function effectivePermissions(int $teamId, int $userId): array
    {
        if (isset(self::$permCache[$teamId][$userId])) {
            return self::$permCache[$teamId][$userId];
        }

        // Default: nothing.
        $effective = [];
        foreach (TeamPermissions::KEYS as $k) {
            $effective[$k] = ['read' => false, 'write' => false, 'execute' => false];
        }

        $db = App::db();

        // Verify membership and check implicit owner.
        $tu = $db->fetch(
            "SELECT role FROM team_user WHERE team_id = ? AND user_id = ? LIMIT 1",
            [$teamId, $userId]
        );
        if (!$tu) {
            self::$permCache[$teamId][$userId] = $effective;
            return $effective;
        }

        $legacyRole = (string)($tu['role'] ?? 'member');
        if ($legacyRole === 'owner') {
            // Owner: full access.
            foreach (TeamPermissions::KEYS as $k) {
                $effective[$k] = ['read' => true, 'write' => true, 'execute' => true];
            }
            self::$permCache[$teamId][$userId] = $effective;
            return $effective;
        }

        $assigned = $db->fetchAll(
            "SELECT tr.slug, tr.is_builtin, tr.hierarchy_level,
                    rp.perm_key, rp.can_read, rp.can_write, rp.can_execute
             FROM team_user_roles tur
             JOIN team_roles tr ON tr.id = tur.role_id
             LEFT JOIN team_role_permissions rp ON rp.role_id = tr.id
             WHERE tur.team_id = ? AND tur.user_id = ?",
            [$teamId, $userId]
        );

        $hasAdmin = false;
        foreach ($assigned as $row) {
            if (($row['slug'] ?? null) === 'admin') {
                $hasAdmin = true;
                break;
            }
        }

        if ($hasAdmin) {
            // Admin: full access (except team deletion is handled elsewhere).
            foreach (TeamPermissions::KEYS as $k) {
                $effective[$k] = ['read' => true, 'write' => true, 'execute' => true];
            }
            self::$permCache[$teamId][$userId] = $effective;
            return $effective;
        }

        // Union permissions from non-admin roles.
        foreach ($assigned as $row) {
            $k = (string)($row['perm_key'] ?? '');
            if ($k === '' || !isset($effective[$k])) {
                continue;
            }
            if (!empty($row['can_read'])) {
                $effective[$k]['read'] = true;
            }
            if (!empty($row['can_write'])) {
                $effective[$k]['write'] = true;
            }
            if (!empty($row['can_execute'])) {
                $effective[$k]['execute'] = true;
            }
        }

        self::$permCache[$teamId][$userId] = $effective;
        return $effective;
    }

    /** @return string[] */
    public static function roleSlugs(int $teamId, int $userId): array
    {
        if (isset(self::$roleSlugsCache[$teamId][$userId])) {
            return self::$roleSlugsCache[$teamId][$userId];
        }

        $db = App::db();
        $tu = $db->fetch(
            "SELECT role FROM team_user WHERE team_id = ? AND user_id = ? LIMIT 1",
            [$teamId, $userId]
        );
        if (!$tu) {
            self::$roleSlugsCache[$teamId][$userId] = [];
            return [];
        }

        $legacyRole = (string)($tu['role'] ?? 'member');
        if ($legacyRole === 'owner') {
            self::$roleSlugsCache[$teamId][$userId] = ['owner'];
            return ['owner'];
        }

        $rows = $db->fetchAll(
            "SELECT tr.slug FROM team_user_roles tur
             JOIN team_roles tr ON tr.id = tur.role_id
             WHERE tur.team_id = ? AND tur.user_id = ?",
            [$teamId, $userId]
        );
        $slugs = array_values(array_unique(array_map(static fn($r) => (string)($r['slug'] ?? ''), $rows)));
        $slugs = array_values(array_filter($slugs, static fn($s) => $s !== ''));

        self::$roleSlugsCache[$teamId][$userId] = $slugs;
        return $slugs;
    }

    /** @return array{level:int, slug:string} */
    public static function highestRole(int $teamId, int $userId): array
    {
        if (isset(self::$highestRoleCache[$teamId][$userId])) {
            return self::$highestRoleCache[$teamId][$userId];
        }

        $db = App::db();
        $tu = $db->fetch(
            "SELECT role FROM team_user WHERE team_id = ? AND user_id = ? LIMIT 1",
            [$teamId, $userId]
        );
        if (!$tu) {
            self::$highestRoleCache[$teamId][$userId] = ['level' => 0, 'slug' => 'none'];
            return ['level' => 0, 'slug' => 'none'];
        }

        $legacyRole = (string)($tu['role'] ?? 'member');
        if ($legacyRole === 'owner') {
            self::$highestRoleCache[$teamId][$userId] = ['level' => TeamPermissions::BUILTIN_LEVELS['owner'], 'slug' => 'owner'];
            return ['level' => TeamPermissions::BUILTIN_LEVELS['owner'], 'slug' => 'owner'];
        }

        $rows = $db->fetchAll(
            "SELECT tr.slug, tr.hierarchy_level
             FROM team_user_roles tur
             JOIN team_roles tr ON tr.id = tur.role_id
             WHERE tur.team_id = ? AND tur.user_id = ?",
            [$teamId, $userId]
        );

        $bestLevel = 0;
        $bestSlug = 'none';
        foreach ($rows as $r) {
            $lvl = (int)($r['hierarchy_level'] ?? 0);
            if ($lvl > $bestLevel) {
                $bestLevel = $lvl;
                $bestSlug = (string)($r['slug'] ?? '');
            }
        }

        // Backwards compatibility: treat legacy admin as admin even if backfill didn't run.
        if ($bestLevel === 0 && $legacyRole === 'admin') {
            $bestLevel = TeamPermissions::BUILTIN_LEVELS['admin'];
            $bestSlug = 'admin';
        }

        if ($bestLevel === 0) {
            $bestLevel = TeamPermissions::BUILTIN_LEVELS['member'];
            $bestSlug = 'member';
        }

        self::$highestRoleCache[$teamId][$userId] = ['level' => $bestLevel, 'slug' => $bestSlug];
        return ['level' => $bestLevel, 'slug' => $bestSlug];
    }

    public static function canManageUser(int $teamId, int $actorUserId, int $targetUserId): bool
    {
        if (admin_view_all()) {
            return true;
        }

        // Owners can manage everyone.
        $actorHighest = self::highestRole($teamId, $actorUserId);
        if ($actorHighest['slug'] === 'owner') {
            return true;
        }

        // Nobody can manage the owner via this system.
        $db = App::db();
        $targetLegacy = $db->fetch(
            "SELECT role FROM team_user WHERE team_id = ? AND user_id = ? LIMIT 1",
            [$teamId, $targetUserId]
        );
        if ($targetLegacy && ($targetLegacy['role'] ?? null) === 'owner') {
            return false;
        }

        $targetHighest = self::highestRole($teamId, $targetUserId);

        // Admins can manage any non-owner user (including other admins).
        if ($actorHighest['slug'] === 'admin') {
            return true;
        }

        // Must be strictly higher.
        return (int)$actorHighest['level'] > (int)$targetHighest['level'];
    }

    public static function canAssignRole(int $teamId, int $actorUserId, int $roleId): bool
    {
        if (admin_view_all()) {
            return true;
        }

        $db = App::db();
        $role = $db->fetch(
            "SELECT slug, hierarchy_level, team_id, is_builtin FROM team_roles WHERE id = ? LIMIT 1",
            [$roleId]
        );
        if (!$role) {
            return false;
        }
        if ((int)$role['team_id'] !== $teamId) {
            return false;
        }

        $slug = (string)($role['slug'] ?? '');
        if ($slug === 'owner') {
            // Owner cannot be manually assigned.
            return false;
        }

        $actorHighest = self::highestRole($teamId, $actorUserId);
        if ($actorHighest['slug'] === 'owner') {
            return true;
        }

        // Admin can assign any non-owner role.
        if ($actorHighest['slug'] === 'admin') {
            return true;
        }

        // Must be below actor's level.
        if ((int)$role['hierarchy_level'] >= (int)$actorHighest['level']) {
            return false;
        }

        // Cannot assign a role that grants permissions actor doesn't have.
        $actorPerms = self::effectivePermissions($teamId, $actorUserId);
        $rolePerms = self::permissionsForRole($roleId);
        return self::isPermissionSubset($rolePerms, $actorPerms);
    }

    /**
     * Assign roles to a user (replaces existing assignments), enforcing hierarchy and permission subset.
     *
     * @param int[] $roleIds
     */
    public static function setUserRoles(int $teamId, int $actorUserId, int $targetUserId, array $roleIds): void
    {
        if (admin_view_all()) {
            self::setUserRolesUnsafe($teamId, $targetUserId, $roleIds);
            return;
        }

        if (!self::can($teamId, $actorUserId, 'team.members', 'execute')) {
            throw new \RuntimeException('Permission denied');
        }

        if (!self::canManageUser($teamId, $actorUserId, $targetUserId)) {
            throw new \RuntimeException('You cannot manage this user');
        }

        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        $roleIds = array_values(array_filter($roleIds, static fn($id) => $id > 0));

        foreach ($roleIds as $rid) {
            if (!self::canAssignRole($teamId, $actorUserId, $rid)) {
                throw new \RuntimeException('You cannot assign one or more selected roles');
            }
        }

        self::setUserRolesUnsafe($teamId, $targetUserId, $roleIds);

        // Keep legacy `team_user.role` aligned for backward compatibility.
        self::syncLegacyTeamUserRole($teamId, $targetUserId);

        self::clearCache($teamId, $targetUserId);
    }

    /** @param int[] $roleIds */
    private static function setUserRolesUnsafe(int $teamId, int $userId, array $roleIds): void
    {
        $db = App::db();
        $db->beginTransaction();
        try {
            $db->delete('team_user_roles', 'team_id = ? AND user_id = ?', [$teamId, $userId]);
            foreach ($roleIds as $rid) {
                $db->insert('team_user_roles', [
                    'team_id' => $teamId,
                    'user_id' => $userId,
                    'role_id' => (int)$rid,
                ]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
    }

    private static function syncLegacyTeamUserRole(int $teamId, int $userId): void
    {
        $db = App::db();
        $tu = $db->fetch(
            "SELECT role FROM team_user WHERE team_id = ? AND user_id = ? LIMIT 1",
            [$teamId, $userId]
        );
        if (!$tu) {
            return;
        }
        if (($tu['role'] ?? null) === 'owner') {
            return;
        }
        $highest = self::highestRole($teamId, $userId);
        $legacy = ($highest['slug'] === 'admin') ? 'admin' : 'member';
        $db->update('team_user', ['role' => $legacy], 'team_id = ? AND user_id = ?', [$teamId, $userId]);
    }

    /**
     * Validate and normalize a custom role permission payload.
     *
     * @param array<string, array{read?:mixed, write?:mixed, execute?:mixed}> $input
     * @return array<string, array{read:bool, write:bool, execute:bool}>
     */
    public static function normalizePermissionPayload(array $input): array
    {
        $out = [];
        foreach (TeamPermissions::KEYS as $k) {
            $out[$k] = ['read' => false, 'write' => false, 'execute' => false];
        }

        foreach ($input as $k => $flags) {
            if (!is_string($k) || !isset($out[$k]) || !is_array($flags)) {
                continue;
            }
            $out[$k]['read'] = !empty($flags['read']);
            $out[$k]['write'] = !empty($flags['write']);
            $out[$k]['execute'] = !empty($flags['execute']);
        }

        // Strip irrelevant actions (keep UI rules consistent, also prevents weird combinations).
        foreach (TeamPermissions::UI as $k => $meta) {
            $allowed = $meta['actions'] ?? [];
            if (!in_array('read', $allowed, true)) {
                $out[$k]['read'] = false;
            }
            if (!in_array('write', $allowed, true)) {
                $out[$k]['write'] = false;
            }
            if (!in_array('execute', $allowed, true)) {
                $out[$k]['execute'] = false;
            }
        }

        return $out;
    }

    /** @return array<string, array{read:bool, write:bool, execute:bool}> */
    public static function permissionsForRole(int $roleId): array
    {
        $effective = [];
        foreach (TeamPermissions::KEYS as $k) {
            $effective[$k] = ['read' => false, 'write' => false, 'execute' => false];
        }

        $db = App::db();
        $role = $db->fetch("SELECT slug FROM team_roles WHERE id = ? LIMIT 1", [$roleId]);
        if (!$role) {
            return $effective;
        }
        $slug = (string)($role['slug'] ?? '');

        if ($slug === 'admin') {
            foreach (TeamPermissions::KEYS as $k) {
                $effective[$k] = ['read' => true, 'write' => true, 'execute' => true];
            }
            return $effective;
        }

        $rows = $db->fetchAll(
            "SELECT perm_key, can_read, can_write, can_execute FROM team_role_permissions WHERE role_id = ?",
            [$roleId]
        );
        foreach ($rows as $row) {
            $k = (string)($row['perm_key'] ?? '');
            if ($k === '' || !isset($effective[$k])) {
                continue;
            }
            $effective[$k] = [
                'read' => !empty($row['can_read']),
                'write' => !empty($row['can_write']),
                'execute' => !empty($row['can_execute']),
            ];
        }
        return $effective;
    }

    /** @param array<string, array{read:bool, write:bool, execute:bool}> $a @param array<string, array{read:bool, write:bool, execute:bool}> $b */
    private static function isPermissionSubset(array $a, array $b): bool
    {
        foreach (TeamPermissions::KEYS as $k) {
            $aa = $a[$k] ?? ['read' => false, 'write' => false, 'execute' => false];
            $bb = $b[$k] ?? ['read' => false, 'write' => false, 'execute' => false];

            if (!empty($aa['read']) && empty($bb['read'])) {
                return false;
            }
            if (!empty($aa['write']) && empty($bb['write'])) {
                return false;
            }
            if (!empty($aa['execute']) && empty($bb['execute'])) {
                return false;
            }
        }
        return true;
    }
}
