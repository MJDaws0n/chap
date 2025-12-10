<?php

namespace Chap\Controllers;

use Chap\Models\Environment;
use Chap\Models\Template;
use Chap\Models\ActivityLog;

/**
 * Service Controller
 * 
 * Manages one-click services (pre-configured applications from templates)
 */
class ServiceController extends BaseController
{
    /**
     * Show create service form
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

        // Get available templates
        $templates = Template::all();

        $this->view('services/create', [
            'title' => 'New Service',
            'environment' => $environment,
            'templates' => $templates,
        ]);
    }

    /**
     * Store new service
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
            $this->redirect("/environments/{$envId}/services/create");
            return;
        }

        $data = $this->all();

        // TODO: Create Service model instance
        // TODO: Apply template configuration
        // TODO: Queue deployment task

        flash('success', 'Service creation queued');
        $this->redirect("/environments/{$envId}");
    }

    /**
     * Show service details
     */
    public function show(string $id): void
    {
        // TODO: Fetch service by UUID
        // TODO: Check team access
        
        $this->view('services/show', [
            'title' => 'Service Details',
        ]);
    }

    /**
     * Show edit form
     */
    public function edit(string $id): void
    {
        // TODO: Implement
        $this->view('services/edit', [
            'title' => 'Edit Service',
        ]);
    }

    /**
     * Update service
     */
    public function update(string $id): void
    {
        // TODO: Implement
        flash('success', 'Service updated');
        $this->redirect("/services/{$id}");
    }

    /**
     * Delete service
     */
    public function destroy(string $id): void
    {
        // TODO: Implement
        flash('success', 'Service deleted');
        $this->redirect('/projects');
    }

    /**
     * Start service
     */
    public function start(string $id): void
    {
        // TODO: Queue start task
        flash('success', 'Service starting');
        $this->redirect("/services/{$id}");
    }

    /**
     * Stop service
     */
    public function stop(string $id): void
    {
        // TODO: Queue stop task
        flash('success', 'Service stopping');
        $this->redirect("/services/{$id}");
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
