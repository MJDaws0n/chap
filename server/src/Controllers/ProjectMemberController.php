<?php

namespace Chap\Controllers;

use Chap\Models\Project;
use Chap\Models\User;

/**
 * Project member management (roles + per-user settings)
 */
class ProjectMemberController extends BaseController
{
    public function add(string $uuid): void
    {
        $team = $this->currentTeam();
        $project = Project::findByUuid($uuid);

        if (!$project || $project->team_id !== $team->id) {
            flash('error', 'Project not found');
            $this->redirect('/projects');
            return;
        }

        $this->requireTeamAdmin();

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/projects/' . $uuid . '/edit');
            return;
        }

        $account = trim($_POST['account'] ?? '');
        $role = $_POST['role'] ?? 'member';

        $allowedRoles = ['admin', 'member', 'viewer'];
        if (!in_array($role, $allowedRoles, true)) {
            flash('error', 'Invalid role');
            $this->redirect('/projects/' . $uuid . '/edit');
            return;
        }

        if ($account === '') {
            flash('error', 'Account is required');
            $this->redirect('/projects/' . $uuid . '/edit');
            return;
        }

        $user = null;
        if (str_contains($account, '@')) {
            $user = User::findByEmail($account);
        }
        if (!$user) {
            $user = User::findByUsername($account);
        }
        if (!$user && !str_contains($account, '@')) {
            $user = User::findByEmail($account);
        }

        if (!$user) {
            flash('error', 'User not found');
            $this->redirect('/projects/' . $uuid . '/edit');
            return;
        }

        // must be in current team
        if (!$team->hasMember($user->id)) {
            flash('error', 'User is not a member of this team');
            $this->redirect('/projects/' . $uuid . '/edit');
            return;
        }

        if ($project->hasMember($user->id)) {
            flash('error', 'User is already a member of this project');
            $this->redirect('/projects/' . $uuid . '/edit');
            return;
        }

        $project->addMember($user->id, $role);

        flash('success', 'Project member added');
        $this->redirect('/projects/' . $uuid . '/edit');
    }

    public function update(string $uuid, int $userId): void
    {
        $team = $this->currentTeam();
        $project = Project::findByUuid($uuid);

        if (!$project || $project->team_id !== $team->id) {
            flash('error', 'Project not found');
            $this->redirect('/projects');
            return;
        }

        $this->requireTeamAdmin();

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/projects/' . $uuid . '/edit');
            return;
        }

        $role = $_POST['role'] ?? null;

        if ($role !== null) {
            $allowedRoles = ['admin', 'member', 'viewer'];
            if (!in_array($role, $allowedRoles, true)) {
                flash('error', 'Invalid role');
                $this->redirect('/projects/' . $uuid . '/edit');
                return;
            }
            $project->updateMemberRole($userId, $role);
        }

        flash('success', 'Project member updated');
        $this->redirect('/projects/' . $uuid . '/edit');
    }

    public function remove(string $uuid, int $userId): void
    {
        $team = $this->currentTeam();
        $project = Project::findByUuid($uuid);

        if (!$project || $project->team_id !== $team->id) {
            flash('error', 'Project not found');
            $this->redirect('/projects');
            return;
        }

        $this->requireTeamAdmin();

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/projects/' . $uuid . '/edit');
            return;
        }

        $project->removeMember($userId);

        flash('success', 'Project member removed');
        $this->redirect('/projects/' . $uuid . '/edit');
    }

}
