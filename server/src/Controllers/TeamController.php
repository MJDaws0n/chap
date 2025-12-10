<?php

namespace Chap\Controllers;

use Chap\Auth\AuthManager;
use Chap\Models\Team;
use Chap\Models\User;
use Chap\View\View;

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
        $user = AuthManager::user();
        $teams = $user->teams();
        
        echo View::render('teams/index', [
            'title' => 'Teams',
            'currentPage' => 'teams',
            'user' => $user->toArray(),
            'teams' => $teams,
            'currentTeam' => $user->currentTeam()?->toArray()
        ]);
    }

    /**
     * Show create team form
     */
    public function create(): void
    {
        $user = AuthManager::user();
        
        echo View::render('teams/create', [
            'title' => 'Create Team',
            'currentPage' => 'teams',
            'user' => $user->toArray(),
            'currentTeam' => $user->currentTeam()?->toArray()
        ]);
    }

    /**
     * Store new team
     */
    public function store(): void
    {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            flash('error', 'Team name is required');
            redirect('/teams/create');
            return;
        }

        $user = AuthManager::user();
        
        $team = Team::create([
            'name' => $name,
            'description' => $description,
            'personal_team' => false
        ]);

        // Add user as owner
        $team->addMember($user->id, 'owner');

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

        $user = AuthManager::user();
        
        // Check if user is member
        if (!$team->hasMember($user->id)) {
            flash('error', 'You are not a member of this team');
            redirect('/teams');
            return;
        }

        $members = $team->members();
        
        echo View::render('teams/show', [
            'title' => $team->name,
            'currentPage' => 'teams',
            'user' => $user->toArray(),
            'currentTeam' => $user->currentTeam()?->toArray(),
            'team' => $team->toArray(),
            'members' => $members,
            'isOwner' => $team->isOwner($user->id)
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

        $user = AuthManager::user();
        
        if (!$team->isOwner($user->id) && !$team->isAdmin($user->id)) {
            flash('error', 'You do not have permission to edit this team');
            redirect('/teams/' . $id);
            return;
        }

        echo View::render('teams/edit', [
            'title' => 'Edit Team',
            'currentPage' => 'teams',
            'user' => $user->toArray(),
            'currentTeam' => $user->currentTeam()?->toArray(),
            'team' => $team->toArray()
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

        $user = AuthManager::user();
        
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

        $user = AuthManager::user();
        
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

        $user = AuthManager::user();
        
        if (!$team->hasMember($user->id)) {
            flash('error', 'You are not a member of this team');
            redirect('/teams');
            return;
        }

        $user->update(['current_team_id' => $team->id]);

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
            json(['error' => 'Team not found'], 404);
            return;
        }

        $user = AuthManager::user();
        
        if (!$team->isOwner($user->id) && !$team->isAdmin($user->id)) {
            json(['error' => 'Permission denied'], 403);
            return;
        }

        $email = trim($_POST['email'] ?? '');
        $role = $_POST['role'] ?? 'member';

        $newUser = User::findByEmail($email);
        
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

        $currentUser = AuthManager::user();
        
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
}
