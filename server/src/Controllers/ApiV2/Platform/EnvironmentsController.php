<?php

namespace Chap\Controllers\ApiV2\Platform;

use Chap\Models\Environment;
use Chap\Models\Project;

class EnvironmentsController extends BasePlatformController
{
    public function index(): void
    {
        $key = $this->requirePlatformScope('environments:read');
        if (!$key) return;

        $projectUuid = trim((string)($_GET['filter']['project_id'] ?? ''));
        $project = $projectUuid !== '' ? Project::findByUuid($projectUuid) : null;

        if ($project && !$this->requirePlatformConstraints($key, ['project_id' => (string)$project->uuid])) return;

        $envs = $project ? Environment::forProject((int)$project->id) : Environment::all();

        $data = array_map(function(Environment $e) {
            $uuid = (string)($e->uuid ?? '');
            $project = $e->project();
            $team = $project?->team();
            return [
                'id' => $uuid,
                'uuid' => $uuid,
                'team_id' => $team?->uuid ? (string)$team->uuid : null,
                'project_id' => $project?->uuid ? (string)$project->uuid : null,
                'name' => (string)$e->name,
                'description' => $e->description,
                'created_at' => $e->created_at ? date('c', strtotime((string)$e->created_at)) : null,
                'updated_at' => $e->updated_at ? date('c', strtotime((string)$e->updated_at)) : null,
            ];
        }, $envs);

        $this->ok(['data' => $data]);
    }

    public function show(string $environment_id): void
    {
        $key = $this->requirePlatformScope('environments:read');
        if (!$key) return;

        $env = Environment::findByUuid($environment_id);
        if (!$env) {
            $this->v2Error('not_found', 'Environment not found', 404);
            return;
        }

        $project = $env->project();
        $team = $project?->team();
        if (!$this->requirePlatformConstraints($key, [
            'team_id' => $team?->uuid ? (string)$team->uuid : null,
            'project_id' => $project?->uuid ? (string)$project->uuid : null,
            'environment_id' => (string)$env->uuid,
        ])) return;

        $this->ok([
            'data' => [
                'id' => (string)$env->uuid,
                'uuid' => (string)$env->uuid,
                'team_id' => $team?->uuid ? (string)$team->uuid : null,
                'project_id' => $project?->uuid ? (string)$project->uuid : null,
                'name' => (string)$env->name,
                'description' => $env->description,
                'created_at' => $env->created_at ? date('c', strtotime((string)$env->created_at)) : null,
                'updated_at' => $env->updated_at ? date('c', strtotime((string)$env->updated_at)) : null,
            ],
        ]);
    }

    public function store(): void
    {
        $key = $this->requirePlatformScope('environments:write');
        if (!$key) return;

        $data = $this->all();
        $projectUuid = trim((string)($data['project_id'] ?? $data['project_uuid'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        if ($projectUuid === '') {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'project_id']);
            return;
        }
        if ($name === '') {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'name']);
            return;
        }

        $project = Project::findByUuid($projectUuid);
        if (!$project) {
            $this->v2Error('validation_error', 'Project not found', 422, ['field' => 'project_id']);
            return;
        }

        $team = $project->team();
        if (!$this->requirePlatformConstraints($key, [
            'team_id' => $team?->uuid ? (string)$team->uuid : null,
            'project_id' => (string)$project->uuid,
        ])) return;

        $env = Environment::create([
            'project_id' => (int)$project->id,
            'name' => $name,
            'description' => array_key_exists('description', $data) ? (string)($data['description'] ?? '') : null,
        ]);

        $this->ok([
            'data' => [
                'environment_id' => (string)$env->uuid,
                'environment' => [
                    'id' => (string)$env->uuid,
                    'uuid' => (string)$env->uuid,
                    'project_id' => (string)$project->uuid,
                    'team_id' => $team?->uuid ? (string)$team->uuid : null,
                    'name' => (string)$env->name,
                    'description' => $env->description,
                ],
            ],
        ], 201);
    }

    public function update(string $environment_id): void
    {
        $key = $this->requirePlatformScope('environments:write');
        if (!$key) return;

        $env = Environment::findByUuid($environment_id);
        if (!$env) {
            $this->v2Error('not_found', 'Environment not found', 404);
            return;
        }

        $project = $env->project();
        $team = $project?->team();
        if (!$this->requirePlatformConstraints($key, [
            'team_id' => $team?->uuid ? (string)$team->uuid : null,
            'project_id' => $project?->uuid ? (string)$project->uuid : null,
            'environment_id' => (string)$env->uuid,
        ])) return;

        $data = $this->all();
        $update = [];
        foreach (['name','description'] as $k) {
            if (array_key_exists($k, $data)) {
                $update[$k] = $data[$k];
            }
        }

        if (empty($update)) {
            $this->ok(['data' => ['updated' => false]]);
            return;
        }

        $env->update($update);
        $this->ok(['data' => ['updated' => true]]);
    }

    public function destroy(string $environment_id): void
    {
        $key = $this->requirePlatformScope('environments:write');
        if (!$key) return;

        $env = Environment::findByUuid($environment_id);
        if (!$env) {
            $this->v2Error('not_found', 'Environment not found', 404);
            return;
        }

        $project = $env->project();
        $team = $project?->team();
        if (!$this->requirePlatformConstraints($key, [
            'team_id' => $team?->uuid ? (string)$team->uuid : null,
            'project_id' => $project?->uuid ? (string)$project->uuid : null,
            'environment_id' => (string)$env->uuid,
        ])) return;

        $env->delete();
        $this->ok(['data' => ['deleted' => true]]);
    }
}
