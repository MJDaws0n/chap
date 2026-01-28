<?php

namespace Chap\Controllers\ApiV2\Platform;

use Chap\Models\Project;
use Chap\Models\Team;
use Chap\Models\User;

class ProjectsController extends BasePlatformController
{
    public function index(): void
    {
        $key = $this->requirePlatformScope('projects:read');
        if (!$key) return;

        $teamUuid = trim((string)($_GET['filter']['team_id'] ?? ''));
        $team = $teamUuid !== '' ? Team::findByUuid($teamUuid) : null;
        if ($team && !$this->requirePlatformConstraints($key, ['team_id' => (string)$team->uuid])) return;

        $projects = $team ? Project::forTeam((int)$team->id) : Project::all();

        $data = array_map(function(Project $p) {
            $uuid = (string)($p->uuid ?? '');
            $team = $p->team();
            return [
                'id' => $uuid,
                'uuid' => $uuid,
                'team_id' => $team?->uuid ? (string)$team->uuid : null,
                'name' => (string)$p->name,
                'description' => $p->description,
                'created_at' => $p->created_at ? date('c', strtotime((string)$p->created_at)) : null,
                'updated_at' => $p->updated_at ? date('c', strtotime((string)$p->updated_at)) : null,
            ];
        }, $projects);

        $this->ok(['data' => $data]);
    }

    public function show(string $project_id): void
    {
        $key = $this->requirePlatformScope('projects:read');
        if (!$key) return;

        $project = Project::findByUuid($project_id);
        if (!$project) {
            $this->v2Error('not_found', 'Project not found', 404);
            return;
        }

        $team = $project->team();
        if (!$this->requirePlatformConstraints($key, [
            'team_id' => $team?->uuid ? (string)$team->uuid : null,
            'project_id' => (string)$project->uuid,
        ])) return;

        $members = $project->members();

        $this->ok([
            'data' => [
                'id' => (string)$project->uuid,
                'uuid' => (string)$project->uuid,
                'team_id' => $team?->uuid ? (string)$team->uuid : null,
                'name' => (string)$project->name,
                'description' => $project->description,
                'members' => array_map(function($u) {
                    $uuid = (string)($u->uuid ?? '');
                    return [
                        'id' => $uuid,
                        'uuid' => $uuid,
                        'email' => (string)($u->email ?? ''),
                        'username' => (string)($u->username ?? ''),
                        'name' => $u->name,
                        'role' => $u->project_role ?? null,
                    ];
                }, $members),
                'created_at' => $project->created_at ? date('c', strtotime((string)$project->created_at)) : null,
                'updated_at' => $project->updated_at ? date('c', strtotime((string)$project->updated_at)) : null,
            ],
        ]);
    }

    public function store(): void
    {
        $key = $this->requirePlatformScope('projects:write');
        if (!$key) return;

        $data = $this->all();
        $teamUuid = trim((string)($data['team_id'] ?? $data['team_uuid'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));

        if ($teamUuid === '') {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'team_id']);
            return;
        }
        if ($name === '') {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'name']);
            return;
        }

        $team = Team::findByUuid($teamUuid);
        if (!$team) {
            $this->v2Error('validation_error', 'Team not found', 422, ['field' => 'team_id']);
            return;
        }

        if (!$this->requirePlatformConstraints($key, ['team_id' => (string)$team->uuid])) return;

        $project = Project::create([
            'team_id' => (int)$team->id,
            'name' => $name,
            'description' => array_key_exists('description', $data) ? (string)($data['description'] ?? '') : null,
        ]);

        // Optional: seed a default environment
        if (!array_key_exists('create_default_environment', $data) || (bool)$data['create_default_environment'] === true) {
            $project->createDefaultEnvironment();
        }

        // Optional: add a member
        $ownerUserUuid = trim((string)($data['owner_user_id'] ?? $data['owner_user_uuid'] ?? ''));
        if ($ownerUserUuid !== '') {
            $owner = User::findByUuid($ownerUserUuid);
            if ($owner) {
                $project->addMember($owner, 'owner');
            }
        }

        $this->ok([
            'data' => [
                'project_id' => (string)$project->uuid,
                'project' => [
                    'id' => (string)$project->uuid,
                    'uuid' => (string)$project->uuid,
                    'team_id' => (string)$team->uuid,
                    'name' => (string)$project->name,
                    'description' => $project->description,
                ],
            ],
        ], 201);
    }

    public function update(string $project_id): void
    {
        $key = $this->requirePlatformScope('projects:write');
        if (!$key) return;

        $project = Project::findByUuid($project_id);
        if (!$project) {
            $this->v2Error('not_found', 'Project not found', 404);
            return;
        }

        $team = $project->team();
        if (!$this->requirePlatformConstraints($key, [
            'team_id' => $team?->uuid ? (string)$team->uuid : null,
            'project_id' => (string)$project->uuid,
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

        $project->update($update);
        $this->ok(['data' => ['updated' => true]]);
    }

    public function destroy(string $project_id): void
    {
        $key = $this->requirePlatformScope('projects:write');
        if (!$key) return;

        $project = Project::findByUuid($project_id);
        if (!$project) {
            $this->v2Error('not_found', 'Project not found', 404);
            return;
        }

        $team = $project->team();
        if (!$this->requirePlatformConstraints($key, [
            'team_id' => $team?->uuid ? (string)$team->uuid : null,
            'project_id' => (string)$project->uuid,
        ])) return;

        $project->delete();
        $this->ok(['data' => ['deleted' => true]]);
    }

    public function addMember(string $project_id): void
    {
        $key = $this->requirePlatformScope('projects:write');
        if (!$key) return;

        $project = Project::findByUuid($project_id);
        if (!$project) {
            $this->v2Error('not_found', 'Project not found', 404);
            return;
        }

        $team = $project->team();
        if (!$this->requirePlatformConstraints($key, [
            'team_id' => $team?->uuid ? (string)$team->uuid : null,
            'project_id' => (string)$project->uuid,
        ])) return;

        $data = $this->all();
        $userUuid = trim((string)($data['user_id'] ?? $data['user_uuid'] ?? ''));
        if ($userUuid === '') {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'user_id']);
            return;
        }
        $role = trim((string)($data['role'] ?? 'member'));
        if ($role === '') $role = 'member';

        $user = User::findByUuid($userUuid);
        if (!$user) {
            $this->v2Error('validation_error', 'User not found', 422, ['field' => 'user_id']);
            return;
        }

        $ok = $project->addMember($user, $role);
        if (!$ok) {
            $this->v2Error('failed_precondition', 'User is already a member of this project', 409);
            return;
        }

        $this->ok(['data' => ['added' => true]]);
    }

    public function updateMember(string $project_id, string $user_id): void
    {
        $key = $this->requirePlatformScope('projects:write');
        if (!$key) return;

        $project = Project::findByUuid($project_id);
        if (!$project) {
            $this->v2Error('not_found', 'Project not found', 404);
            return;
        }

        $team = $project->team();
        if (!$this->requirePlatformConstraints($key, [
            'team_id' => $team?->uuid ? (string)$team->uuid : null,
            'project_id' => (string)$project->uuid,
        ])) return;

        $user = User::findByUuid($user_id);
        if (!$user) {
            $this->v2Error('not_found', 'User not found', 404);
            return;
        }

        $data = $this->all();
        $role = trim((string)($data['role'] ?? ''));
        if ($role === '') {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'role']);
            return;
        }

        $ok = $project->updateMemberRole($user, $role);
        if (!$ok) {
            $this->v2Error('not_found', 'User is not a member of this project', 404);
            return;
        }

        $this->ok(['data' => ['updated' => true]]);
    }

    public function removeMember(string $project_id, string $user_id): void
    {
        $key = $this->requirePlatformScope('projects:write');
        if (!$key) return;

        $project = Project::findByUuid($project_id);
        if (!$project) {
            $this->v2Error('not_found', 'Project not found', 404);
            return;
        }

        $team = $project->team();
        if (!$this->requirePlatformConstraints($key, [
            'team_id' => $team?->uuid ? (string)$team->uuid : null,
            'project_id' => (string)$project->uuid,
        ])) return;

        $user = User::findByUuid($user_id);
        if (!$user) {
            $this->v2Error('not_found', 'User not found', 404);
            return;
        }

        $ok = $project->removeMember($user);
        if (!$ok) {
            $this->v2Error('not_found', 'User is not a member of this project', 404);
            return;
        }

        $this->ok(['data' => ['removed' => true]]);
    }
}
