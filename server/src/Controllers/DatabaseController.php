<?php

namespace Chap\Controllers;

use Chap\Models\Environment;
use Chap\Models\Database;
use Chap\Models\ActivityLog;

/**
 * Database Controller
 * 
 * Manages database services (MySQL, PostgreSQL, Redis, etc.)
 */
class DatabaseController extends BaseController
{
    /**
     * Show create database form
     */
    public function create(string $envId): void
    {
        $team = $this->currentTeam();
        $environment = Environment::findByUuid($envId);

        if (!$environment || !$this->canAccessEnvironment($environment, $team)) {
            flash('error', 'Environment not found');
            $this->redirect('/projects');
            return;
        }

        $teamId = (int) ($environment->project()?->team_id ?? 0);
        $this->requireTeamPermission('databases', 'write', $teamId);

        $this->view('databases/create', [
            'title' => 'New Database',
            'environment' => $environment,
            'databaseTypes' => [
                'mysql' => ['name' => 'MySQL', 'versions' => ['8.0', '5.7']],
                'postgresql' => ['name' => 'PostgreSQL', 'versions' => ['16', '15', '14', '13']],
                'redis' => ['name' => 'Redis', 'versions' => ['7', '6']],
                'mongodb' => ['name' => 'MongoDB', 'versions' => ['7', '6', '5']],
                'mariadb' => ['name' => 'MariaDB', 'versions' => ['11', '10.11', '10.6']],
            ],
        ]);
    }

    /**
     * Store new database
     */
    public function store(string $envId): void
    {
        $team = $this->currentTeam();
        $environment = Environment::findByUuid($envId);

        if (!$environment || !$this->canAccessEnvironment($environment, $team)) {
            flash('error', 'Environment not found');
            $this->redirect('/projects');
            return;
        }

        $teamId = (int) ($environment->project()?->team_id ?? 0);
        $this->requireTeamPermission('databases', 'write', $teamId);

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect("/environments/{$envId}/databases/create");
            return;
        }

        $data = $this->all();

        // TODO: Create Database model instance
        // TODO: Queue deployment task

        flash('success', 'Database creation queued');
        $this->redirect("/environments/{$envId}");
    }

    /**
     * Show database details
     */
    public function show(string $id): void
    {
        $this->currentTeam();
        $database = Database::findByUuid($id) ?? Database::find((int) $id);

        if (!$database) {
            flash('error', 'Database not found');
            $this->redirect('/projects');
            return;
        }

        $project = $database->environment()?->project();
        if (!$project || !$this->canAccessTeamId((int) $project->team_id)) {
            flash('error', 'Database not found');
            $this->redirect('/projects');
            return;
        }

        $this->requireTeamPermission('databases', 'read', (int) $project->team_id);

        $this->view('databases/show', [
            'title' => $database->name !== '' ? $database->name : 'Database',
            'database' => $database,
            'environment' => $database->environment(),
            'project' => $project,
        ]);
    }

    /**
     * Show edit form
     */
    public function edit(string $id): void
    {
        $this->currentTeam();
        $database = Database::findByUuid($id) ?? Database::find((int) $id);

        if (!$database) {
            flash('error', 'Database not found');
            $this->redirect('/projects');
            return;
        }

        $project = $database->environment()?->project();
        if (!$project || !$this->canAccessTeamId((int) $project->team_id)) {
            flash('error', 'Database not found');
            $this->redirect('/projects');
            return;
        }

        $this->requireTeamPermission('databases', 'write', (int) $project->team_id);

        $this->view('databases/edit', [
            'title' => 'Edit Database',
            'database' => $database,
            'environment' => $database->environment(),
            'project' => $project,
        ]);
    }

    /**
     * Update database
     */
    public function update(string $id): void
    {
        $this->currentTeam();
        $database = Database::findByUuid($id) ?? Database::find((int) $id);

        if (!$database) {
            flash('error', 'Database not found');
            $this->redirect('/projects');
            return;
        }

        $project = $database->environment()?->project();
        if (!$project || !$this->canAccessTeamId((int) $project->team_id)) {
            flash('error', 'Database not found');
            $this->redirect('/projects');
            return;
        }

        $this->requireTeamPermission('databases', 'write', (int) $project->team_id);

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect("/databases/{$id}/edit");
            return;
        }

        // TODO: Implement
        flash('success', 'Database updated');
        $this->redirect("/databases/{$id}");
    }

    /**
     * Delete database
     */
    public function destroy(string $id): void
    {
        $this->currentTeam();
        $database = Database::findByUuid($id) ?? Database::find((int) $id);

        if (!$database) {
            flash('error', 'Database not found');
            $this->redirect('/projects');
            return;
        }

        $project = $database->environment()?->project();
        if (!$project || !$this->canAccessTeamId((int) $project->team_id)) {
            flash('error', 'Database not found');
            $this->redirect('/projects');
            return;
        }

        $this->requireTeamPermission('databases', 'write', (int) $project->team_id);

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect("/databases/{$id}");
            return;
        }

        // TODO: Implement
        flash('success', 'Database deleted');
        $this->redirect('/projects');
    }

    /**
     * Start database
     */
    public function start(string $id): void
    {
        $this->currentTeam();
        $database = Database::findByUuid($id) ?? Database::find((int) $id);

        if (!$database) {
            flash('error', 'Database not found');
            $this->redirect('/projects');
            return;
        }

        $project = $database->environment()?->project();
        if (!$project || !$this->canAccessTeamId((int) $project->team_id)) {
            flash('error', 'Database not found');
            $this->redirect('/projects');
            return;
        }

        $this->requireTeamPermission('databases', 'execute', (int) $project->team_id);

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect("/databases/{$id}");
            return;
        }

        // TODO: Queue start task
        flash('success', 'Database starting');
        $this->redirect("/databases/{$id}");
    }

    /**
     * Stop database
     */
    public function stop(string $id): void
    {
        $this->currentTeam();
        $database = Database::findByUuid($id) ?? Database::find((int) $id);

        if (!$database) {
            flash('error', 'Database not found');
            $this->redirect('/projects');
            return;
        }

        $project = $database->environment()?->project();
        if (!$project || !$this->canAccessTeamId((int) $project->team_id)) {
            flash('error', 'Database not found');
            $this->redirect('/projects');
            return;
        }

        $this->requireTeamPermission('databases', 'execute', (int) $project->team_id);

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect("/databases/{$id}");
            return;
        }

        // TODO: Queue stop task
        flash('success', 'Database stopping');
        $this->redirect("/databases/{$id}");
    }

    /**
     * Check if user can access environment
     */
    private function canAccessEnvironment(Environment $environment, $team): bool
    {
        $project = $environment->project();
        return $project && $this->canAccessTeamId((int)$project->team_id);
    }
}
