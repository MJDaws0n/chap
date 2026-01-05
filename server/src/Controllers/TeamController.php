<?php

namespace Chap\Controllers;

use Chap\Models\Team;
use Chap\Models\User;
use Chap\Services\ResourceHierarchy;
use Chap\Services\NodeAccess;
use Chap\Services\ResourceAllocator;

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

        $members = $team->members();

        $this->view('teams/show', [
            'title' => $team->name,
            'team' => $team,
            'members' => $members,
            'isOwner' => $team->isOwner($user->id),
            'isAdmin' => $team->isAdmin($user->id),
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
        
        if (!$team->isOwner($user->id) && !$team->isAdmin($user->id) && !admin_view_all()) {
            flash('error', 'You do not have permission to edit this team');
            redirect('/teams/' . $id);
            return;
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
        
        if (!$team->isOwner($user->id) && !$team->isAdmin($user->id) && !admin_view_all()) {
            flash('error', 'You do not have permission to edit this team');
            redirect('/teams/' . $id);
            return;
        }

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        $cpuMillicoresLimit = ResourceHierarchy::parseCpuMillicores((string)($_POST['cpu_limit_cores'] ?? '-1'));
        $ramMbLimit = ResourceHierarchy::parseMb((string)($_POST['ram_mb_limit'] ?? '-1'));
        $storageMbLimit = ResourceHierarchy::parseMb((string)($_POST['storage_mb_limit'] ?? '-1'));
        $portLimit = ResourceHierarchy::parseIntOrAuto((string)($_POST['port_limit'] ?? '-1'));
        $bandwidthLimit = ResourceHierarchy::parseIntOrAuto((string)($_POST['bandwidth_mbps_limit'] ?? '-1'));
        $pidsLimit = ResourceHierarchy::parseIntOrAuto((string)($_POST['pids_limit'] ?? '-1'));

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
        
        if (!$team->isOwner($user->id) && !$team->isAdmin($user->id) && !admin_view_all()) {
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
        $role = $_POST['role'] ?? 'member';

        $allowedRoles = ['owner', 'admin', 'member'];
        if (!in_array($role, $allowedRoles, true)) {
            flash('error', 'Invalid role');
            $this->redirect('/teams/' . $id);
            return;
        }

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

        $team->addMember($newUser->id, $role);

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
        
        if (!$team->isOwner($currentUser->id) && !$team->isAdmin($currentUser->id) && !admin_view_all()) {
            flash('error', 'Permission denied');
            redirect('/teams/' . $id);
            return;
        }

        if ($team->isOwner($userId)) {
            flash('error', 'Cannot remove team owner');
            redirect('/teams/' . $id);
            return;
        }

        $team->removeMember($userId);

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
        if (!$team->isOwner($currentUser->id) && !$team->isAdmin($currentUser->id) && !admin_view_all()) {
            flash('error', 'Permission denied');
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

        $role = $_POST['role'] ?? null;

        if ($role !== null) {
            $allowedRoles = ['admin', 'member'];
            if (!in_array($role, $allowedRoles, true)) {
                flash('error', 'Invalid role');
                $this->redirect('/teams/' . $id);
                return;
            }
            $team->updateMemberRole($userId, $role);
        }

        flash('success', 'Member updated');
        $this->redirect('/teams/' . $id);
    }
}
