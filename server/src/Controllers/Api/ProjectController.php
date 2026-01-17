<?php

namespace Chap\Controllers\Api;

use Chap\Models\Project;
use Chap\Services\ApplicationCleanupService;

/**
 * API Project Controller
 */
class ProjectController extends BaseApiController
{
    /**
     * List projects
     */
    public function index(): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('projects', 'read', (int) $team->id);
        $projects = Project::forTeam($team->id);

        $this->success([
            'projects' => array_map(fn($p) => $p->toArray(), $projects),
        ]);
    }

    /**
     * Create project
     */
    public function store(): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('projects', 'write', (int) $team->id);
        $data = $this->all();

        if (empty($data['name'])) {
            $this->validationError(['name' => 'Name is required']);
            return;
        }

        $project = Project::create([
            'uuid' => uuid(),
            'team_id' => $team->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        // Create default environment
        $project->createDefaultEnvironment();

        $this->success(['project' => $project->toArray()], 201);
    }

    /**
     * Show project
     */
    public function show(string $id): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('projects', 'read', (int) $team->id);
        $project = Project::findByUuid($id);

        if (!$project || $project->team_id !== $team->id) {
            $this->notFound('Project not found');
            return;
        }

        $this->success([
            'project' => $project->toArray(),
            'environments' => array_map(fn($e) => $e->toArray(), $project->environments()),
        ]);
    }

    /**
     * Update project
     */
    public function update(string $id): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('projects', 'write', (int) $team->id);
        $project = Project::findByUuid($id);

        if (!$project || $project->team_id !== $team->id) {
            $this->notFound('Project not found');
            return;
        }

        $data = $this->all();

        $project->update([
            'name' => $data['name'] ?? $project->name,
            'description' => $data['description'] ?? $project->description,
        ]);

        $this->success(['project' => $project->toArray()]);
    }

    /**
     * Delete project
     */
    public function destroy(string $id): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('projects', 'write', (int) $team->id);
        $project = Project::findByUuid($id);

        if (!$project || $project->team_id !== $team->id) {
            $this->notFound('Project not found');
            return;
        }

    ApplicationCleanupService::deleteAllForProject($project);
    $project->delete();

        $this->success(['message' => 'Project deleted']);
    }
}
