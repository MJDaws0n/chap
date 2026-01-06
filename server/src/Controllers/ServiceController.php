<?php

namespace Chap\Controllers;

use Chap\Models\Environment;
use Chap\Models\Template;
use Chap\Models\Service;
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

        $teamId = (int) ($environment->project()?->team_id ?? 0);
        $this->requireTeamPermission('services', 'write', $teamId);

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

        $teamId = (int) ($environment->project()?->team_id ?? 0);
        $this->requireTeamPermission('services', 'write', $teamId);

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
        $this->currentTeam();
        $service = Service::findByUuid($id) ?? Service::find((int) $id);

        if (!$service) {
            flash('error', 'Service not found');
            $this->redirect('/projects');
            return;
        }

        $project = $service->environment()?->project();
        if (!$project || !$this->canAccessTeamId((int) $project->team_id)) {
            flash('error', 'Service not found');
            $this->redirect('/projects');
            return;
        }

        $this->requireTeamPermission('services', 'read', (int) $project->team_id);

        $this->view('services/show', [
            'title' => $service->name !== '' ? $service->name : 'Service',
            'service' => $service,
            'environment' => $service->environment(),
            'project' => $project,
        ]);
    }

    /**
     * Show edit form
     */
    public function edit(string $id): void
    {
        $this->currentTeam();
        $service = Service::findByUuid($id) ?? Service::find((int) $id);

        if (!$service) {
            flash('error', 'Service not found');
            $this->redirect('/projects');
            return;
        }

        $project = $service->environment()?->project();
        if (!$project || !$this->canAccessTeamId((int) $project->team_id)) {
            flash('error', 'Service not found');
            $this->redirect('/projects');
            return;
        }

        $this->requireTeamPermission('services', 'write', (int) $project->team_id);

        $this->view('services/edit', [
            'title' => 'Edit Service',
            'service' => $service,
            'environment' => $service->environment(),
            'project' => $project,
        ]);
    }

    /**
     * Update service
     */
    public function update(string $id): void
    {
        $this->currentTeam();
        $service = Service::findByUuid($id) ?? Service::find((int) $id);

        if (!$service) {
            flash('error', 'Service not found');
            $this->redirect('/projects');
            return;
        }

        $project = $service->environment()?->project();
        if (!$project || !$this->canAccessTeamId((int) $project->team_id)) {
            flash('error', 'Service not found');
            $this->redirect('/projects');
            return;
        }

        $this->requireTeamPermission('services', 'write', (int) $project->team_id);

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect("/services/{$id}/edit");
            return;
        }

        // TODO: Implement
        flash('success', 'Service updated');
        $this->redirect("/services/{$id}");
    }

    /**
     * Delete service
     */
    public function destroy(string $id): void
    {
        $this->currentTeam();
        $service = Service::findByUuid($id) ?? Service::find((int) $id);

        if (!$service) {
            flash('error', 'Service not found');
            $this->redirect('/projects');
            return;
        }

        $project = $service->environment()?->project();
        if (!$project || !$this->canAccessTeamId((int) $project->team_id)) {
            flash('error', 'Service not found');
            $this->redirect('/projects');
            return;
        }

        $this->requireTeamPermission('services', 'write', (int) $project->team_id);

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect("/services/{$id}");
            return;
        }

        // TODO: Implement
        flash('success', 'Service deleted');
        $this->redirect('/projects');
    }

    /**
     * Start service
     */
    public function start(string $id): void
    {
        $this->currentTeam();
        $service = Service::findByUuid($id) ?? Service::find((int) $id);

        if (!$service) {
            flash('error', 'Service not found');
            $this->redirect('/projects');
            return;
        }

        $project = $service->environment()?->project();
        if (!$project || !$this->canAccessTeamId((int) $project->team_id)) {
            flash('error', 'Service not found');
            $this->redirect('/projects');
            return;
        }

        $this->requireTeamPermission('services', 'execute', (int) $project->team_id);

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect("/services/{$id}");
            return;
        }

        // TODO: Queue start task
        flash('success', 'Service starting');
        $this->redirect("/services/{$id}");
    }

    /**
     * Stop service
     */
    public function stop(string $id): void
    {
        $this->currentTeam();
        $service = Service::findByUuid($id) ?? Service::find((int) $id);

        if (!$service) {
            flash('error', 'Service not found');
            $this->redirect('/projects');
            return;
        }

        $project = $service->environment()?->project();
        if (!$project || !$this->canAccessTeamId((int) $project->team_id)) {
            flash('error', 'Service not found');
            $this->redirect('/projects');
            return;
        }

        $this->requireTeamPermission('services', 'execute', (int) $project->team_id);

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect("/services/{$id}");
            return;
        }

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
        return $project && $this->canAccessTeamId((int)$project->team_id);
    }
}
