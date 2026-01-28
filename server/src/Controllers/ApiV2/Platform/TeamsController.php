<?php

namespace Chap\Controllers\ApiV2\Platform;

use Chap\Models\Team;
use Chap\Models\User;

class TeamsController extends BasePlatformController
{
    public function index(): void
    {
        $this->requirePlatformScope('teams:read') ?? null;
        if (!$this->platformKey()) return;

        $teams = Team::all();
        $data = array_map(function(Team $t) {
            $uuid = (string)($t->uuid ?? '');
            return [
                'id' => $uuid,
                'uuid' => $uuid,
                'name' => (string)$t->name,
                'description' => $t->description,
                'personal_team' => (bool)$t->personal_team,
                'created_at' => $t->created_at ? date('c', strtotime((string)$t->created_at)) : null,
                'updated_at' => $t->updated_at ? date('c', strtotime((string)$t->updated_at)) : null,
            ];
        }, $teams);

        $this->ok(['data' => $data]);
    }

    public function show(string $team_id): void
    {
        $key = $this->requirePlatformScope('teams:read');
        if (!$key) return;

        $team = Team::findByUuid($team_id);
        if (!$team) {
            $this->v2Error('not_found', 'Team not found', 404);
            return;
        }

        if (!$this->requirePlatformConstraints($key, ['team_id' => (string)$team->uuid])) return;

        $members = $team->members();
        $this->ok([
            'data' => [
                'id' => (string)$team->uuid,
                'uuid' => (string)$team->uuid,
                'name' => (string)$team->name,
                'description' => $team->description,
                'personal_team' => (bool)$team->personal_team,
                'members' => array_map(function($u) {
                    $uuid = (string)($u->uuid ?? '');
                    return [
                        'id' => $uuid,
                        'uuid' => $uuid,
                        'email' => (string)($u->email ?? ''),
                        'username' => (string)($u->username ?? ''),
                        'name' => $u->name,
                        'role' => $u->role ?? null,
                        'team_roles' => $u->team_role_slugs ?? [],
                    ];
                }, $members),
                'created_at' => $team->created_at ? date('c', strtotime((string)$team->created_at)) : null,
                'updated_at' => $team->updated_at ? date('c', strtotime((string)$team->updated_at)) : null,
            ],
        ]);
    }

    public function store(): void
    {
        $key = $this->requirePlatformScope('teams:write');
        if (!$key) return;

        $data = $this->all();
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'name']);
            return;
        }

        $team = Team::create([
            'name' => $name,
            'description' => array_key_exists('description', $data) ? (string)($data['description'] ?? '') : null,
            'personal_team' => (bool)($data['personal_team'] ?? false) ? 1 : 0,
        ]);

        $ownerUserUuid = trim((string)($data['owner_user_id'] ?? $data['owner_user_uuid'] ?? ''));
        if ($ownerUserUuid !== '') {
            $owner = User::findByUuid($ownerUserUuid);
            if (!$owner) {
                $this->v2Error('validation_error', 'Owner user not found', 422, ['field' => 'owner_user_id']);
                return;
            }
            $team->addMember($owner, 'owner');
        }

        $this->ok([
            'data' => [
                'team_id' => (string)$team->uuid,
                'team' => [
                    'id' => (string)$team->uuid,
                    'uuid' => (string)$team->uuid,
                    'name' => (string)$team->name,
                    'description' => $team->description,
                    'personal_team' => (bool)$team->personal_team,
                ],
            ],
        ], 201);
    }

    public function update(string $team_id): void
    {
        $key = $this->requirePlatformScope('teams:write');
        if (!$key) return;

        $team = Team::findByUuid($team_id);
        if (!$team) {
            $this->v2Error('not_found', 'Team not found', 404);
            return;
        }

        if (!$this->requirePlatformConstraints($key, ['team_id' => (string)$team->uuid])) return;

        $data = $this->all();
        $update = [];
        foreach (['name','description'] as $k) {
            if (array_key_exists($k, $data)) {
                $update[$k] = $data[$k];
            }
        }
        if (array_key_exists('personal_team', $data)) {
            $update['personal_team'] = (bool)$data['personal_team'] ? 1 : 0;
        }

        if (empty($update)) {
            $this->ok(['data' => ['updated' => false]]);
            return;
        }

        $team->update($update);
        $this->ok(['data' => ['updated' => true]]);
    }

    public function destroy(string $team_id): void
    {
        $key = $this->requirePlatformScope('teams:write');
        if (!$key) return;

        $team = Team::findByUuid($team_id);
        if (!$team) {
            $this->v2Error('not_found', 'Team not found', 404);
            return;
        }

        if (!$this->requirePlatformConstraints($key, ['team_id' => (string)$team->uuid])) return;

        $team->delete();
        $this->ok(['data' => ['deleted' => true]]);
    }

    public function addMember(string $team_id): void
    {
        $key = $this->requirePlatformScope('teams:write');
        if (!$key) return;

        $team = Team::findByUuid($team_id);
        if (!$team) {
            $this->v2Error('not_found', 'Team not found', 404);
            return;
        }
        if (!$this->requirePlatformConstraints($key, ['team_id' => (string)$team->uuid])) return;

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

        $ok = $team->addMember($user, $role);
        if (!$ok) {
            $this->v2Error('failed_precondition', 'User is already a member of this team', 409);
            return;
        }

        $this->ok(['data' => ['added' => true]]);
    }

    public function updateMember(string $team_id, string $user_id): void
    {
        $key = $this->requirePlatformScope('teams:write');
        if (!$key) return;

        $team = Team::findByUuid($team_id);
        if (!$team) {
            $this->v2Error('not_found', 'Team not found', 404);
            return;
        }
        if (!$this->requirePlatformConstraints($key, ['team_id' => (string)$team->uuid])) return;

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

        $ok = $team->updateMemberRole($user, $role);
        if (!$ok) {
            $this->v2Error('not_found', 'User is not a member of this team', 404);
            return;
        }

        $this->ok(['data' => ['updated' => true]]);
    }

    public function removeMember(string $team_id, string $user_id): void
    {
        $key = $this->requirePlatformScope('teams:write');
        if (!$key) return;

        $team = Team::findByUuid($team_id);
        if (!$team) {
            $this->v2Error('not_found', 'Team not found', 404);
            return;
        }
        if (!$this->requirePlatformConstraints($key, ['team_id' => (string)$team->uuid])) return;

        $user = User::findByUuid($user_id);
        if (!$user) {
            $this->v2Error('not_found', 'User not found', 404);
            return;
        }

        $ok = $team->removeMember($user);
        if (!$ok) {
            $this->v2Error('not_found', 'User is not a member of this team', 404);
            return;
        }

        $this->ok(['data' => ['removed' => true]]);
    }
}
