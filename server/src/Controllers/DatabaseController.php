<?php

namespace Chap\Controllers;

use Chap\Models\Environment;
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
        // TODO: Fetch database by UUID
        // TODO: Check team access
        // TODO: Render view

        $this->view('databases/show', [
            'title' => 'Database Details',
        ]);
    }

    /**
     * Show edit form
     */
    public function edit(string $id): void
    {
        // TODO: Implement
        $this->view('databases/edit', [
            'title' => 'Edit Database',
        ]);
    }

    /**
     * Update database
     */
    public function update(string $id): void
    {
        // TODO: Implement
        flash('success', 'Database updated');
        $this->redirect("/databases/{$id}");
    }

    /**
     * Delete database
     */
    public function destroy(string $id): void
    {
        // TODO: Implement
        flash('success', 'Database deleted');
        $this->redirect('/projects');
    }

    /**
     * Start database
     */
    public function start(string $id): void
    {
        // TODO: Queue start task
        flash('success', 'Database starting');
        $this->redirect("/databases/{$id}");
    }

    /**
     * Stop database
     */
    public function stop(string $id): void
    {
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
        return $project && $project->team_id === $team->id;
    }
}
