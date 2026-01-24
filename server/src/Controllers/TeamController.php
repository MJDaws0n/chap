<?php

namespace Chap\Controllers;

use Chap\Auth\TeamPermissionService;
use Chap\Auth\TeamPermissions;
use Chap\Auth\TeamRoleSeeder;
use Chap\Models\Team;
use Chap\Models\User;
use Chap\Services\ApplicationCleanupService;
use Chap\Services\NotificationService;
use Chap\Services\ResourceHierarchy;
use Chap\Services\NodeAccess;
use Chap\Services\ResourceAllocator;
use Chap\Services\LimitCascadeService;

/**
 * Team Controller
 */
class TeamController extends BaseController
{
    /**
     * List all teams
     */
    public function index(): void
    {
        $user = $this->user;
        $teams = admin_view_all() ? Team::all() : ($user?->teams() ?? []);

        $this->view('teams/index', [
            'title' => 'Teams',
            'teams' => $teams,
        ]);
    }

    /**
     * Show create team form
     */
    public function create(): void
    {
        $this->view('teams/create', [
            'title' => 'Create Team',
        ]);
    }

    /**
     * Store new team
     */
    public function store(): void
    {
        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/teams/create');
            return;
        }

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            flash('error', 'Team name is required');
            redirect('/teams/create');
            return;
        }

        $user = $this->user;
        
        $team = Team::create([
            'name' => $name,
            'description' => $description,
            'personal_team' => 0
        ]);

        TeamRoleSeeder::ensureBuiltins((int)$team->id);

        // Add user as owner
        $team->addMember($user->id, 'owner');

        // Make the newly created team the current team
        $user->switchTeam($team);

        flash('success', 'Team created successfully');
        redirect('/teams/' . $team->id);
    }

    /**
     * Show team details
     */
    public function show(int $id): void
    {
        $team = Team::find($id);
        
        if (!$team) {
            redirect('/teams');
            return;
        }

        $user = $this->user;
        
        // Check if user is member
        if (!$team->hasMember($user->id) && !admin_view_all()) {
            flash('error', 'You are not a member of this team');
            redirect('/teams');
            return;
        }

        $userId = (int)($user?->id ?? 0);
        $canManageMembers = admin_view_all() || TeamPermissionService::can((int)$team->id, $userId, 'team.members', 'execute');
        $canViewMembers = $canManageMembers || admin_view_all() || TeamPermissionService::can((int)$team->id, $userId, 'team.members', 'read');
        $canViewRoles = admin_view_all() || TeamPermissionService::can((int)$team->id, $userId, 'team.roles', 'read');
        $canManageRoles = admin_view_all() || TeamPermissionService::can((int)$team->id, $userId, 'team.roles', 'write');

        $members = $canViewMembers ? $team->members() : [];

        $roles = $team->roles();
        $builtinBase = array_values(array_filter($roles, function($r) {
            $slug = (string)($r['slug'] ?? '');
            return in_array($slug, ['admin', 'manager', 'member', 'read_only_member'], true);
        }));
        $customRoles = array_values(array_filter($roles, function($r) {
            return empty($r['is_builtin']) && empty($r['is_locked']);
        }));

        $this->view('teams/show', [
            'title' => $team->name,
            'team' => $team,
            'members' => $members,
            'isOwner' => $team->isOwner($user->id),
            'isAdmin' => $team->isAdmin($user->id),
            'canViewMembers' => $canViewMembers,
            'canManageMembers' => $canManageMembers,
            'canViewRoles' => $canViewRoles,
            'canManageRoles' => $canManageRoles,
            'builtinBaseRoles' => $builtinBase,
            'customRoles' => $customRoles,
        ]);
    }

    /**
     * Show edit form
     */
    public function edit(int $id): void
    {
        $team = Team::find($id);
        
        if (!$team) {
            redirect('/teams');
            return;
        }

        $user = $this->user;

        if (!admin_view_all()) {
            $this->requireTeamPermission('team.settings', 'write', (int)$team->id);
        }

        $allowedNodeIds = $user->allowedNodeIdsForTeam((int)$team->id);
        $teamNodes = $team->nodes();
        $availableNodes = array_values(array_filter($teamNodes, fn($n) => in_array((int)$n->id, $allowedNodeIds, true)));

        $owner = $team->owner();
        $ownerMax = $owner ? ResourceHierarchy::userMax($owner) : null;

        $this->view('teams/edit', [
            'title' => 'Edit Team',
            'team' => $team,
            'availableNodes' => $availableNodes,
            'ownerMax' => $ownerMax,
        ]);
    }

    /**
     * Update team
     */
    public function update(int $id): void
    {
        $team = Team::find($id);
        
        if (!$team) {
            redirect('/teams');
            return;
        }

        $user = $this->user;

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/teams/' . $id . '/edit');
            return;
        }
        
        if (!admin_view_all()) {
            $this->requireTeamPermission('team.settings', 'write', (int)$team->id);
        }

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $oldLimits = ResourceHierarchy::teamConfigured($team);

        $cpuMillicoresLimit = ResourceHierarchy::parseCpuMillicores((string)($_POST['cpu_limit_cores'] ?? '-1'));
        $ramMbLimit = ResourceHierarchy::parseMb((string)($_POST['ram_mb_limit'] ?? '-1'));
        $storageMbLimit = ResourceHierarchy::parseMb((string)($_POST['storage_mb_limit'] ?? '-1'));
        $portLimit = ResourceHierarchy::parseIntOrAuto((string)($_POST['port_limit'] ?? '-1'));
        $bandwidthLimit = ResourceHierarchy::parseIntOrAuto((string)($_POST['bandwidth_mbps_limit'] ?? '-1'));
        $pidsLimit = ResourceHierarchy::parseIntOrAuto((string)($_POST['pids_limit'] ?? '-1'));

        $newLimits = [
            'cpu_millicores' => $cpuMillicoresLimit,
            'ram_mb' => $ramMbLimit,
            'storage_mb' => $storageMbLimit,
            'ports' => $portLimit,
            'bandwidth_mbps' => $bandwidthLimit,
            'pids' => $pidsLimit,
        ];

        $restrictNodes = !empty($_POST['restrict_nodes']);
        $nodeIds = $_POST['allowed_node_ids'] ?? [];
        if (!is_array($nodeIds)) {
            $nodeIds = [];
        }
        $nodeIds = array_values(array_unique(array_map('intval', $nodeIds)));

        if (empty($name)) {
            flash('error', 'Team name is required');
            redirect('/teams/' . $id . '/edit');
            return;
        }

        // Validate team limits against owner max (teams are allocated from the team owner's user max).
        $owner = $team->owner();
        $errors = [];
        if ($owner) {
            $parent = ResourceHierarchy::userMax($owner);
            $ownedTeams = ResourceHierarchy::teamsOwnedByUser((int)$owner->id);

            $maps = [
                'cpu_millicores' => [],
                'ram_mb' => [],
                'storage_mb' => [],
                'ports' => [],
                'bandwidth_mbps' => [],
                'pids' => [],
            ];

            foreach ($ownedTeams as $t) {
                $isCurrent = (int)$t->id === (int)$team->id;
                $maps['cpu_millicores'][(int)$t->id] = $isCurrent ? $cpuMillicoresLimit : (int)$t->cpu_millicores_limit;
                $maps['ram_mb'][(int)$t->id] = $isCurrent ? $ramMbLimit : (int)$t->ram_mb_limit;
                $maps['storage_mb'][(int)$t->id] = $isCurrent ? $storageMbLimit : (int)$t->storage_mb_limit;
                $maps['ports'][(int)$t->id] = $isCurrent ? $portLimit : (int)$t->port_limit;
                $maps['bandwidth_mbps'][(int)$t->id] = $isCurrent ? $bandwidthLimit : (int)$t->bandwidth_mbps_limit;
                $maps['pids'][(int)$t->id] = $isCurrent ? $pidsLimit : (int)$t->pids_limit;
            }

            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['cpu_millicores'], $maps['cpu_millicores'])) {
                $errors['cpu_limit_cores'] = 'CPU allocations exceed the team owner\'s user maximum';
            }
            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['ram_mb'], $maps['ram_mb'])) {
                $errors['ram_mb_limit'] = 'RAM allocations exceed the team owner\'s user maximum';
            }
            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['storage_mb'], $maps['storage_mb'])) {
                $errors['storage_mb_limit'] = 'Storage allocations exceed the team owner\'s user maximum';
            }
            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['ports'], $maps['ports'])) {
                $errors['port_limit'] = 'Port allocations exceed the team owner\'s user maximum';
            }
            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['bandwidth_mbps'], $maps['bandwidth_mbps'])) {
                $errors['bandwidth_mbps_limit'] = 'Bandwidth allocations exceed the team owner\'s user maximum';
            }
            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['pids'], $maps['pids'])) {
                $errors['pids_limit'] = 'PID allocations exceed the team owner\'s user maximum';
            }
        }

        if ($restrictNodes) {
            $allowedForEditor = $user->allowedNodeIdsForTeam((int)$team->id);
            $bad = array_diff($nodeIds, $allowedForEditor);
            if (!empty($bad)) {
                $errors['allowed_node_ids'] = 'You cannot grant access to nodes you do not have access to';
            }
        }

        if (!empty($errors)) {
            $_SESSION['_errors'] = $errors;
            $_SESSION['_old_input'] = $_POST;
            redirect('/teams/' . $id . '/edit');
            return;
        }

        $team->update([
            'name' => $name,
            'description' => $description,
            'cpu_millicores_limit' => $cpuMillicoresLimit,
            'ram_mb_limit' => $ramMbLimit,
            'storage_mb_limit' => $storageMbLimit,
            'port_limit' => $portLimit,
            'bandwidth_mbps_limit' => $bandwidthLimit,
            'pids_limit' => $pidsLimit,
            'allowed_node_ids' => $restrictNodes ? NodeAccess::encodeNodeIds($nodeIds) : null,
        ]);

        // If limits were reduced, enforce hierarchy + redeploy descendant applications for security.
        if (LimitCascadeService::anyReduction($oldLimits, $newLimits)) {
            $enforced = LimitCascadeService::enforceUnderTeam($team);
            $apps = LimitCascadeService::applicationIdsForTeam((int)$team->id);
            $redeploy = LimitCascadeService::redeployApplications($apps, $this->user, 'limits');

            $details = [];
            if (($enforced['changed_fields'] ?? 0) > 0) {
                $details[] = 'auto-adjusted child limits';
            }
            $details[] = 'redeploy started: ' . ($redeploy['started'] ?? 0);
            flash('info', 'Limits reduced: ' . implode(', ', $details));
        }

        flash('success', 'Team updated successfully');
        redirect('/teams/' . $id);
    }

    /**
     * Delete team
     */
    public function destroy(int $id): void
    {
        $team = Team::find($id);
        
        if (!$team) {
            redirect('/teams');
            return;
        }

        $user = $this->user;

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/teams/' . $id);
            return;
        }
        
        if (!$team->isOwner($user->id) && !admin_view_all()) {
            flash('error', 'Only the team owner can delete this team');
            redirect('/teams/' . $id);
            return;
        }

        if ($team->personal_team) {
            flash('error', 'Cannot delete personal team');
            redirect('/teams/' . $id);
            return;
        }

        // Ensure applications are stopped and removed on their nodes before
        // we delete the team (DB cascades alone won't notify nodes).
        ApplicationCleanupService::deleteAllForTeam($team);

        $team->delete();

        flash('success', 'Team deleted successfully');
        redirect('/teams');
    }

    /**
     * Switch current team
     */
    public function switch(int $id): void
    {
        $team = Team::find($id);
        
        if (!$team) {
            redirect('/teams');
            return;
        }

        $user = $this->user;

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/teams');
            return;
        }
        
        if (!$team->hasMember($user->id) && !admin_view_all()) {
            flash('error', 'You are not a member of this team');
            redirect('/teams');
            return;
        }

        // Persist (DB) and/or activate immediately (session)
        // - Regular users: persists to DB + session.
        // - Admin "view all" mode: session-only when not a member.
        if (!$user->switchTeam($team)) {
            flash('error', 'Unable to switch team');
            redirect('/teams');
            return;
        }

        flash('success', 'Switched to ' . $team->name);
        redirect('/dashboard');
    }

    /**
     * Add team member
     */
    public function addMember(int $id): void
    {
        $team = Team::find($id);
        
        if (!$team) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Team not found'], 404);
            }
            flash('error', 'Team not found');
            $this->redirect('/teams');
            return;
        }

        $user = $this->user;

        $userId = (int)($user?->id ?? 0);
        if (!admin_view_all() && !TeamPermissionService::can((int)$team->id, $userId, 'team.members', 'execute')) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Permission denied'], 403);
            }
            flash('error', 'Permission denied');
            $this->redirect('/teams/' . $id);
            return;
        }

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/teams/' . $id);
            return;
        }

        $account = trim($_POST['account'] ?? ($_POST['email'] ?? ''));
        $baseRoleSlug = (string)($_POST['base_role'] ?? 'member');
        $baseRoleSlug = TeamPermissions::normalizeSlug($baseRoleSlug);
        $allowedBase = ['admin', 'manager', 'member', 'read_only_member'];
        if (!in_array($baseRoleSlug, $allowedBase, true)) {
            flash('error', 'Invalid role');
            $this->redirect('/teams/' . $id);
            return;
        }

        $customRoleIds = $_POST['custom_role_ids'] ?? [];
        if (!is_array($customRoleIds)) {
            $customRoleIds = [];
        }
        $customRoleIds = array_values(array_unique(array_map('intval', $customRoleIds)));

        if ($account === '') {
            flash('error', 'Account is required');
            $this->redirect('/teams/' . $id);
            return;
        }

        // Find by email or username ("account")
        $newUser = null;
        if (str_contains($account, '@')) {
            $newUser = User::findByEmail($account);
        }
        if (!$newUser) {
            $newUser = User::findByUsername($account);
        }
        if (!$newUser && !str_contains($account, '@')) {
            // last try: maybe user typed email without @ check? (unlikely, but harmless)
            $newUser = User::findByEmail($account);
        }
        
        if (!$newUser) {
            flash('error', 'User not found');
            redirect('/teams/' . $id);
            return;
        }

        if ($team->hasMember($newUser->id)) {
            flash('error', 'User is already a member');
            redirect('/teams/' . $id);
            return;
        }

        // Persist membership (legacy role remains owner/admin/member).
        $legacyRole = ($baseRoleSlug === 'admin') ? 'admin' : 'member';
        $team->addMember($newUser->id, $legacyRole);

        // Resolve role IDs and assign (multi-role).
        $db = \Chap\App::db();
        $baseRole = $db->fetch(
            "SELECT id FROM team_roles WHERE team_id = ? AND slug = ? LIMIT 1",
            [(int)$team->id, $baseRoleSlug]
        );
        if (!$baseRole) {
            flash('error', 'Role not found');
            $this->redirect('/teams/' . $id);
            return;
        }

        $roleIds = array_merge([(int)$baseRole['id']], $customRoleIds);
        try {
            TeamPermissionService::setUserRoles((int)$team->id, $userId, (int)$newUser->id, $roleIds);
        } catch (\Throwable $e) {
            $team->removeMember((int)$newUser->id);
            flash('error', $e->getMessage());
            $this->redirect('/teams/' . $id);
            return;
        }

        try {
            NotificationService::notifyTeamMemberAdded($team, $newUser, $user);
        } catch (\Throwable $e) {
            // best-effort
        }

        flash('success', 'Member added successfully');
        redirect('/teams/' . $id);
    }

    /**
     * Remove team member
     */
    public function removeMember(int $id, int $userId): void
    {
        $team = Team::find($id);
        
        if (!$team) {
            redirect('/teams');
            return;
        }

        $currentUser = $this->user;

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/teams/' . $id);
            return;
        }
        
        $actorId = (int)($currentUser?->id ?? 0);
        if (!admin_view_all() && !TeamPermissionService::can((int)$team->id, $actorId, 'team.members', 'execute')) {
            flash('error', 'Permission denied');
            redirect('/teams/' . $id);
            return;
        }

        if (!admin_view_all() && !TeamPermissionService::canManageUser((int)$team->id, $actorId, (int)$userId)) {
            flash('error', 'You cannot manage this user');
            redirect('/teams/' . $id);
            return;
        }

        if ($team->isOwner($userId)) {
            flash('error', 'Cannot remove team owner');
            redirect('/teams/' . $id);
            return;
        }

        $targetUser = User::find((int)$userId);
        $targetSettings = null;
        if ($targetUser) {
            $targetSettings = NotificationService::getUserNotifications((int)$team->id, (int)$targetUser->id);
        }

        $team->removeMember($userId);

        if ($targetUser) {
            try {
                NotificationService::notifyTeamMemberRemoved($team, $targetUser, $currentUser, $targetSettings);
            } catch (\Throwable $e) {
                // best-effort
            }
        }

        flash('success', 'Member removed successfully');
        redirect('/teams/' . $id);
    }

    /**
     * Update team member role/settings
     */
    public function updateMember(int $id, int $userId): void
    {
        $team = Team::find($id);

        if (!$team) {
            flash('error', 'Team not found');
            $this->redirect('/teams');
            return;
        }

        $currentUser = $this->user;
        $actorId = (int)($currentUser?->id ?? 0);
        if (!admin_view_all() && !TeamPermissionService::can((int)$team->id, $actorId, 'team.members', 'execute')) {
            flash('error', 'Permission denied');
            $this->redirect('/teams/' . $id);
            return;
        }

        if (!admin_view_all() && !TeamPermissionService::canManageUser((int)$team->id, $actorId, (int)$userId)) {
            flash('error', 'You cannot manage this user');
            $this->redirect('/teams/' . $id);
            return;
        }

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/teams/' . $id);
            return;
        }

        if ($team->isOwner($userId)) {
            flash('error', 'Cannot change owner settings here');
            $this->redirect('/teams/' . $id);
            return;
        }

        $baseRoleSlug = (string)($_POST['base_role'] ?? 'member');
        $baseRoleSlug = TeamPermissions::normalizeSlug($baseRoleSlug);
        $allowedBase = ['admin', 'manager', 'member', 'read_only_member'];
        if (!in_array($baseRoleSlug, $allowedBase, true)) {
            flash('error', 'Invalid role');
            $this->redirect('/teams/' . $id);
            return;
        }

        $customRoleIds = $_POST['custom_role_ids'] ?? [];
        if (!is_array($customRoleIds)) {
            $customRoleIds = [];
        }
        $customRoleIds = array_values(array_unique(array_map('intval', $customRoleIds)));

        $db = \Chap\App::db();
        $baseRole = $db->fetch(
            "SELECT id FROM team_roles WHERE team_id = ? AND slug = ? LIMIT 1",
            [(int)$team->id, $baseRoleSlug]
        );
        if (!$baseRole) {
            flash('error', 'Role not found');
            $this->redirect('/teams/' . $id);
            return;
        }

        $roleIds = array_merge([(int)$baseRole['id']], $customRoleIds);
        try {
            TeamPermissionService::setUserRoles((int)$team->id, $actorId, (int)$userId, $roleIds);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            $this->redirect('/teams/' . $id);
            return;
        }

        flash('success', 'Member updated');
        $this->redirect('/teams/' . $id);
    }
}
