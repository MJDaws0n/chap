<?php

namespace Chap\Controllers;

use Chap\Auth\TeamPermissionService;
use Chap\Auth\TeamPermissions;
use Chap\Models\Team;
use Chap\App;

/**
 * Team Role Management
 */
class TeamRoleController extends BaseController
{
    public function index(int $teamId): void
    {
        $team = Team::find($teamId);
        if (!$team) {
            flash('error', 'Team not found');
            $this->redirect('/teams');
            return;
        }

        if (!$this->canAccessTeamId((int)$team->id)) {
            flash('error', 'You do not have access to this team');
            $this->redirect('/teams');
            return;
        }

        $userId = (int)($this->user?->id ?? 0);
        if (!admin_view_all() && !TeamPermissionService::can((int)$team->id, $userId, 'team.roles', 'read')) {
            flash('error', 'Permission denied');
            $this->redirect('/teams/' . (int)$team->id);
            return;
        }

        $roles = $team->roles();

        $this->view('teams/roles/index', [
            'title' => 'Roles',
            'team' => $team,
            'roles' => $roles,
            'canManageRoles' => admin_view_all() || TeamPermissionService::can((int)$team->id, $userId, 'team.roles', 'write'),
            'canDeleteRoles' => admin_view_all() || TeamPermissionService::can((int)$team->id, $userId, 'team.roles', 'execute'),
        ]);
    }

    public function create(int $teamId): void
    {
        $team = Team::find($teamId);
        if (!$team) {
            flash('error', 'Team not found');
            $this->redirect('/teams');
            return;
        }
        if (!$this->canAccessTeamId((int)$team->id)) {
            flash('error', 'You do not have access to this team');
            $this->redirect('/teams');
            return;
        }

        $this->requireTeamPermission('team.roles', 'write', (int)$team->id);

        $this->view('teams/roles/form', [
            'title' => 'Create Role',
            'team' => $team,
            'role' => null,
            'ui' => TeamPermissions::UI,
            'permissions' => TeamPermissionService::normalizePermissionPayload([]),
            'levelOptions' => [
                60 => 'Manager-level',
                40 => 'Member-level',
                20 => 'Read-only-level',
            ],
        ]);
    }

    public function store(int $teamId): void
    {
        $team = Team::find($teamId);
        if (!$team) {
            flash('error', 'Team not found');
            $this->redirect('/teams');
            return;
        }
        if (!$this->canAccessTeamId((int)$team->id)) {
            flash('error', 'You do not have access to this team');
            $this->redirect('/teams');
            return;
        }

        $this->requireTeamPermission('team.roles', 'write', (int)$team->id);

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/teams/' . (int)$team->id . '/roles/create');
            return;
        }

        $name = trim((string)($this->input('name', '')));
        $level = (int)($this->input('hierarchy_level', 40));
        $rawPerms = $this->input('perms', []);
        if (!is_array($rawPerms)) {
            $rawPerms = [];
        }

        if ($name === '') {
            flash('error', 'Role name is required');
            $this->redirect('/teams/' . (int)$team->id . '/roles/create');
            return;
        }
        if (TeamPermissions::isReservedName($name)) {
            flash('error', 'That role name is reserved');
            $this->redirect('/teams/' . (int)$team->id . '/roles/create');
            return;
        }

        $slug = TeamPermissions::normalizeSlug($name);
        if ($slug === '' || TeamPermissions::isReservedSlug($slug)) {
            flash('error', 'That role slug is reserved');
            $this->redirect('/teams/' . (int)$team->id . '/roles/create');
            return;
        }

        $userId = (int)($this->user?->id ?? 0);
        $actorHighest = TeamPermissionService::highestRole((int)$team->id, $userId);
        if ($actorHighest['slug'] !== 'owner' && $actorHighest['slug'] !== 'admin') {
            flash('error', 'Only the team Owner or Admin can create roles');
            $this->redirect('/teams/' . (int)$team->id . '/roles');
            return;
        }

        // Clamp to allowed choices.
        $allowedLevels = [60, 40, 20];
        if (!in_array($level, $allowedLevels, true)) {
            $level = 40;
        }
        // Ensure below actor.
        if ($actorHighest['slug'] !== 'owner' && $level >= (int)$actorHighest['level']) {
            $level = 40;
        }

        $perms = TeamPermissionService::normalizePermissionPayload($rawPerms);
        $actorPerms = TeamPermissionService::effectivePermissions((int)$team->id, $userId);
        // subset enforcement
        $tmpRolePerms = $perms;
        foreach (TeamPermissions::KEYS as $k) {
            $a = $tmpRolePerms[$k];
            $b = $actorPerms[$k];
            if (($a['read'] && !$b['read']) || ($a['write'] && !$b['write']) || ($a['execute'] && !$b['execute'])) {
                flash('error', 'Role permissions cannot exceed your own permissions');
                $this->redirect('/teams/' . (int)$team->id . '/roles/create');
                return;
            }
        }

        $db = App::db();
        $existing = $db->fetch(
            "SELECT id FROM team_roles WHERE team_id = ? AND (slug = ? OR name = ?) LIMIT 1",
            [(int)$team->id, $slug, $name]
        );
        if ($existing) {
            flash('error', 'A role with that name already exists');
            $this->redirect('/teams/' . (int)$team->id . '/roles/create');
            return;
        }

        $db->beginTransaction();
        try {
            $roleId = $db->insert('team_roles', [
                'team_id' => (int)$team->id,
                'uuid' => uuid(),
                'name' => $name,
                'slug' => $slug,
                'is_builtin' => 0,
                'is_locked' => 0,
                'hierarchy_level' => $level,
                'created_by_user_id' => $userId > 0 ? $userId : null,
            ]);

            foreach ($perms as $k => $flags) {
                $db->insert('team_role_permissions', [
                    'role_id' => $roleId,
                    'perm_key' => $k,
                    'can_read' => (int)!empty($flags['read']),
                    'can_write' => (int)!empty($flags['write']),
                    'can_execute' => (int)!empty($flags['execute']),
                ]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        flash('success', 'Role created');
        $this->redirect('/teams/' . (int)$team->id . '/roles');
    }

    public function edit(int $teamId, int $roleId): void
    {
        $team = Team::find($teamId);
        if (!$team) {
            flash('error', 'Team not found');
            $this->redirect('/teams');
            return;
        }
        if (!$this->canAccessTeamId((int)$team->id)) {
            flash('error', 'You do not have access to this team');
            $this->redirect('/teams');
            return;
        }

        $this->requireTeamPermission('team.roles', 'write', (int)$team->id);

        $db = App::db();
        $role = $db->fetch("SELECT * FROM team_roles WHERE id = ? AND team_id = ? LIMIT 1", [$roleId, (int)$team->id]);
        if (!$role) {
            flash('error', 'Role not found');
            $this->redirect('/teams/' . (int)$team->id . '/roles');
            return;
        }
        if (!empty($role['is_builtin']) || !empty($role['is_locked'])) {
            flash('error', 'Built-in roles cannot be edited');
            $this->redirect('/teams/' . (int)$team->id . '/roles');
            return;
        }

        $rows = $db->fetchAll("SELECT perm_key, can_read, can_write, can_execute FROM team_role_permissions WHERE role_id = ?", [$roleId]);
        $permPayload = [];
        foreach ($rows as $r) {
            $permPayload[(string)$r['perm_key']] = [
                'read' => !empty($r['can_read']),
                'write' => !empty($r['can_write']),
                'execute' => !empty($r['can_execute']),
            ];
        }

        $this->view('teams/roles/form', [
            'title' => 'Edit Role',
            'team' => $team,
            'role' => $role,
            'ui' => TeamPermissions::UI,
            'permissions' => TeamPermissionService::normalizePermissionPayload($permPayload),
            'levelOptions' => [
                60 => 'Manager-level',
                40 => 'Member-level',
                20 => 'Read-only-level',
            ],
        ]);
    }

    public function update(int $teamId, int $roleId): void
    {
        $team = Team::find($teamId);
        if (!$team) {
            flash('error', 'Team not found');
            $this->redirect('/teams');
            return;
        }
        if (!$this->canAccessTeamId((int)$team->id)) {
            flash('error', 'You do not have access to this team');
            $this->redirect('/teams');
            return;
        }

        $this->requireTeamPermission('team.roles', 'write', (int)$team->id);

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/teams/' . (int)$team->id . '/roles');
            return;
        }

        $db = App::db();
        $role = $db->fetch("SELECT * FROM team_roles WHERE id = ? AND team_id = ? LIMIT 1", [$roleId, (int)$team->id]);
        if (!$role) {
            flash('error', 'Role not found');
            $this->redirect('/teams/' . (int)$team->id . '/roles');
            return;
        }
        if (!empty($role['is_builtin']) || !empty($role['is_locked'])) {
            flash('error', 'Built-in roles cannot be edited');
            $this->redirect('/teams/' . (int)$team->id . '/roles');
            return;
        }

        $name = trim((string)($this->input('name', '')));
        $level = (int)($this->input('hierarchy_level', (int)($role['hierarchy_level'] ?? 40)));
        $rawPerms = $this->input('perms', []);
        if (!is_array($rawPerms)) {
            $rawPerms = [];
        }

        if ($name === '') {
            flash('error', 'Role name is required');
            $this->redirect('/teams/' . (int)$team->id . '/roles/' . (int)$roleId . '/edit');
            return;
        }
        if (TeamPermissions::isReservedName($name)) {
            flash('error', 'That role name is reserved');
            $this->redirect('/teams/' . (int)$team->id . '/roles/' . (int)$roleId . '/edit');
            return;
        }

        $slug = TeamPermissions::normalizeSlug($name);
        if ($slug === '' || TeamPermissions::isReservedSlug($slug)) {
            flash('error', 'That role slug is reserved');
            $this->redirect('/teams/' . (int)$team->id . '/roles/' . (int)$roleId . '/edit');
            return;
        }

        $userId = (int)($this->user?->id ?? 0);
        $actorHighest = TeamPermissionService::highestRole((int)$team->id, $userId);
        if ($actorHighest['slug'] !== 'owner' && $actorHighest['slug'] !== 'admin') {
            flash('error', 'Only the team Owner or Admin can edit roles');
            $this->redirect('/teams/' . (int)$team->id . '/roles');
            return;
        }

        $allowedLevels = [60, 40, 20];
        if (!in_array($level, $allowedLevels, true)) {
            $level = (int)($role['hierarchy_level'] ?? 40);
        }
        if ($actorHighest['slug'] !== 'owner' && $level >= (int)$actorHighest['level']) {
            $level = (int)($role['hierarchy_level'] ?? 40);
        }

        $perms = TeamPermissionService::normalizePermissionPayload($rawPerms);
        $actorPerms = TeamPermissionService::effectivePermissions((int)$team->id, $userId);
        foreach (TeamPermissions::KEYS as $k) {
            $a = $perms[$k];
            $b = $actorPerms[$k];
            if (($a['read'] && !$b['read']) || ($a['write'] && !$b['write']) || ($a['execute'] && !$b['execute'])) {
                flash('error', 'Role permissions cannot exceed your own permissions');
                $this->redirect('/teams/' . (int)$team->id . '/roles/' . (int)$roleId . '/edit');
                return;
            }
        }

        $existing = $db->fetch(
            "SELECT id FROM team_roles WHERE team_id = ? AND id <> ? AND (slug = ? OR name = ?) LIMIT 1",
            [(int)$team->id, (int)$roleId, $slug, $name]
        );
        if ($existing) {
            flash('error', 'A role with that name already exists');
            $this->redirect('/teams/' . (int)$team->id . '/roles/' . (int)$roleId . '/edit');
            return;
        }

        $db->beginTransaction();
        try {
            $db->update('team_roles', [
                'name' => $name,
                'slug' => $slug,
                'hierarchy_level' => $level,
            ], 'id = ? AND team_id = ?', [(int)$roleId, (int)$team->id]);

            $db->delete('team_role_permissions', 'role_id = ?', [(int)$roleId]);
            foreach ($perms as $k => $flags) {
                $db->insert('team_role_permissions', [
                    'role_id' => (int)$roleId,
                    'perm_key' => $k,
                    'can_read' => (int)!empty($flags['read']),
                    'can_write' => (int)!empty($flags['write']),
                    'can_execute' => (int)!empty($flags['execute']),
                ]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        flash('success', 'Role updated');
        $this->redirect('/teams/' . (int)$team->id . '/roles');
    }

    public function destroy(int $teamId, int $roleId): void
    {
        $team = Team::find($teamId);
        if (!$team) {
            flash('error', 'Team not found');
            $this->redirect('/teams');
            return;
        }
        if (!$this->canAccessTeamId((int)$team->id)) {
            flash('error', 'You do not have access to this team');
            $this->redirect('/teams');
            return;
        }

        $this->requireTeamPermission('team.roles', 'execute', (int)$team->id);

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/teams/' . (int)$team->id . '/roles');
            return;
        }

        $db = App::db();
        $role = $db->fetch("SELECT * FROM team_roles WHERE id = ? AND team_id = ? LIMIT 1", [$roleId, (int)$team->id]);
        if (!$role) {
            flash('error', 'Role not found');
            $this->redirect('/teams/' . (int)$team->id . '/roles');
            return;
        }
        if (!empty($role['is_builtin']) || !empty($role['is_locked']) || TeamPermissions::isReservedSlug((string)($role['slug'] ?? ''))) {
            flash('error', 'Built-in roles cannot be deleted');
            $this->redirect('/teams/' . (int)$team->id . '/roles');
            return;
        }

        $db->delete('team_roles', 'id = ? AND team_id = ?', [(int)$roleId, (int)$team->id]);
        flash('success', 'Role deleted');
        $this->redirect('/teams/' . (int)$team->id . '/roles');
    }
}
