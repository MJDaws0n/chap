<?php

namespace Chap\Controllers;

use Chap\Models\Team;
use Chap\Models\User;

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
        $teams = $user?->teams() ?? [];

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
        if (!$team->hasMember($user->id)) {
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
        
        if (!$team->isOwner($user->id) && !$team->isAdmin($user->id)) {
            flash('error', 'You do not have permission to edit this team');
            redirect('/teams/' . $id);
            return;
        }

        $this->view('teams/edit', [
            'title' => 'Edit Team',
            'team' => $team,
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
        
        if (!$team->isOwner($user->id) && !$team->isAdmin($user->id)) {
            flash('error', 'You do not have permission to edit this team');
            redirect('/teams/' . $id);
            return;
        }

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            flash('error', 'Team name is required');
            redirect('/teams/' . $id . '/edit');
            return;
        }

        $team->update([
            'name' => $name,
            'description' => $description
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
        
        if (!$team->isOwner($user->id)) {
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
        
        if (!$team->hasMember($user->id)) {
            flash('error', 'You are not a member of this team');
            redirect('/teams');
            return;
        }

        // Persist (DB) and activate immediately (session)
        $user->update(['current_team_id' => $team->id]);
        $user->switchTeam($team);

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
        
        if (!$team->isOwner($user->id) && !$team->isAdmin($user->id)) {
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
        
        if (!$team->isOwner($currentUser->id) && !$team->isAdmin($currentUser->id)) {
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
        if (!$team->isOwner($currentUser->id) && !$team->isAdmin($currentUser->id)) {
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
