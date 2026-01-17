<?php

namespace Chap\Controllers\Api;

use Chap\Models\Environment;
use Chap\Models\Project;
use Chap\Services\ApplicationCleanupService;

/**
 * API Environment Controller
 */
class EnvironmentController extends BaseApiController
{
    /**
     * List environments for a project
     */
    public function index(string $projectId): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('environments', 'read', (int) $team->id);
        $project = Project::findByUuid($projectId);

        if (!$project || $project->team_id !== $team->id) {
            $this->notFound('Project not found');
            return;
        }

        $environments = $project->environments();

        $this->success([
            'environments' => array_map(fn($e) => $e->toArray(), $environments),
        ]);
    }

    /**
     * Create environment
     */
    public function store(string $projectId): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('environments', 'write', (int) $team->id);
        $project = Project::findByUuid($projectId);

        if (!$project || $project->team_id !== $team->id) {
            $this->notFound('Project not found');
            return;
        }

        $data = $this->all();

        if (empty($data['name'])) {
            $this->validationError(['name' => 'Name is required']);
            return;
        }

        $environment = Environment::create([
            'uuid' => uuid(),
            'project_id' => $project->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        $this->success(['environment' => $environment->toArray()], 201);
    }

    /**
     * Show environment
     */
    public function show(string $id): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('environments', 'read', (int) $team->id);
        $environment = Environment::findByUuid($id);

        if (!$environment) {
            $this->notFound('Environment not found');
            return;
        }

        $project = $environment->project();
        if (!$project || $project->team_id !== $team->id) {
            $this->notFound('Environment not found');
            return;
        }

        $this->success([
            'environment' => $environment->toArray(),
            'applications' => array_map(fn($a) => $a->toArray(), $environment->applications()),
        ]);
    }

    /**
     * Update environment
     */
    public function update(string $id): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('environments', 'write', (int) $team->id);
        $environment = Environment::findByUuid($id);

        if (!$environment) {
            $this->notFound('Environment not found');
            return;
        }

        $project = $environment->project();
        if (!$project || $project->team_id !== $team->id) {
            $this->notFound('Environment not found');
            return;
        }

        $data = $this->all();

        $environment->update([
            'name' => $data['name'] ?? $environment->name,
            'description' => $data['description'] ?? $environment->description,
        ]);

        $this->success(['environment' => $environment->toArray()]);
    }

    /**
     * Delete environment
     */
    public function destroy(string $id): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('environments', 'write', (int) $team->id);
        $environment = Environment::findByUuid($id);

        if (!$environment) {
            $this->notFound('Environment not found');
            return;
        }

        $project = $environment->project();
        if (!$project || $project->team_id !== $team->id) {
            $this->notFound('Environment not found');
            return;
        }

    // Ensure applications are stopped and removed on their nodes before
    // we delete the environment (DB cascades alone won't notify nodes).
    ApplicationCleanupService::deleteAllForEnvironment($environment);
    $environment->delete();

        $this->success(['message' => 'Environment deleted']);
    }
}
