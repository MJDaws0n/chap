<?php

namespace Chap\Controllers\Api;

use Chap\Models\Application;
use Chap\Models\Environment;
use Chap\Services\DeploymentService;

/**
 * API Application Controller
 */
class ApplicationController extends BaseApiController
{
    /**
     * List applications for an environment
     */
    public function index(string $envId): void
    {
        $team = $this->currentTeam();
        $environment = Environment::findByUuid($envId);

        if (!$environment || !$this->canAccessEnvironment($environment, $team)) {
            $this->notFound('Environment not found');
            return;
        }

        $applications = $environment->applications();

        $this->success([
            'applications' => array_map(fn($a) => $a->toArray(), $applications),
        ]);
    }

    /**
     * Create application
     */
    public function store(string $envId): void
    {
        $team = $this->currentTeam();
        $environment = Environment::findByUuid($envId);

        if (!$environment || !$this->canAccessEnvironment($environment, $team)) {
            $this->notFound('Environment not found');
            return;
        }

        $data = $this->all();

        if (empty($data['name'])) {
            $this->validationError(['name' => 'Name is required']);
            return;
        }

        $application = Application::create([
            'uuid' => uuid(),
            'environment_id' => $environment->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'git_repository' => $data['git_repository'] ?? null,
            'git_branch' => $data['git_branch'] ?? 'main',
            'build_pack' => $data['build_pack'] ?? 'dockerfile',
            'port' => $data['port'] ?? null,
            'status' => 'stopped',
        ]);

        $this->success(['application' => $application->toArray()], 201);
    }

    /**
     * Show application
     */
    public function show(string $id): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($id);

        if (!$application) {
            $this->notFound('Application not found');
            return;
        }

        $environment = $application->environment();
        if (!$environment || !$this->canAccessEnvironment($environment, $team)) {
            $this->notFound('Application not found');
            return;
        }

        $this->success([
            'application' => $application->toArray(),
            'deployments' => array_map(fn($d) => $d->toArray(), array_slice($application->deployments(), 0, 10)),
        ]);
    }

    /**
     * Update application
     */
    public function update(string $id): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($id);

        if (!$application) {
            $this->notFound('Application not found');
            return;
        }

        $environment = $application->environment();
        if (!$environment || !$this->canAccessEnvironment($environment, $team)) {
            $this->notFound('Application not found');
            return;
        }

        $data = $this->all();

        $application->update([
            'name' => $data['name'] ?? $application->name,
            'description' => $data['description'] ?? $application->description,
            'git_repository' => $data['git_repository'] ?? $application->git_repository,
            'git_branch' => $data['git_branch'] ?? $application->git_branch,
            'build_pack' => $data['build_pack'] ?? $application->build_pack,
            'port' => $data['port'] ?? $application->port,
        ]);

        $this->success(['application' => $application->toArray()]);
    }

    /**
     * Delete application
     */
    public function destroy(string $id): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($id);

        if (!$application) {
            $this->notFound('Application not found');
            return;
        }

        $environment = $application->environment();
        if (!$environment || !$this->canAccessEnvironment($environment, $team)) {
            $this->notFound('Application not found');
            return;
        }

        $application->delete();

        $this->success(['message' => 'Application deleted']);
    }

    /**
     * Deploy application
     */
    public function deploy(string $id): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($id);

        if (!$application) {
            $this->notFound('Application not found');
            return;
        }

        $environment = $application->environment();
        if (!$environment || !$this->canAccessEnvironment($environment, $team)) {
            $this->notFound('Application not found');
            return;
        }

        if (!$application->node_id) {
            $this->error('No node assigned to application', 400);
            return;
        }

        try {
            $deployment = DeploymentService::create($application, null, [
                'triggered_by' => $this->user ? 'user' : 'api',
                'triggered_by_name' => $this->user?->displayName(),
            ]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage(), 422);
            return;
        }

        $this->success(['deployment' => $deployment->toArray()], 201);
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
