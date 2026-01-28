<?php

namespace Chap\Controllers\ApiV2\Platform;

use Chap\App;
use Chap\Models\User;
use Chap\Services\ApiV2\CursorService;

class UsersController extends BasePlatformController
{
    public function index(): void
    {
        $key = $this->requirePlatformScope('users:read');
        if (!$key) return;

        $limit = CursorService::parseLimit($_GET['page']['limit'] ?? null);
        $cursor = CursorService::decodeId($_GET['page']['cursor'] ?? null);

        $db = App::db();
        $params = [];
        $where = '1=1';
        if ($cursor) {
            $where .= ' AND id > ?';
            $params[] = $cursor;
        }

        $rows = $db->fetchAll(
            "SELECT id, uuid, email, username, name, is_admin, email_verified_at, two_factor_enabled, created_at, updated_at\n" .
            "FROM users WHERE {$where} ORDER BY id ASC LIMIT {$limit}",
            $params
        );

        $nextCursor = null;
        if (count($rows) === $limit) {
            $nextCursor = CursorService::encodeId((int)$rows[count($rows) - 1]['id']);
        }

        $data = array_map(function($r) {
            $uuid = (string)($r['uuid'] ?? '');
            return [
                'internal_id' => (int)($r['id'] ?? 0),
                'id' => $uuid,
                'uuid' => $uuid,
                'email' => (string)($r['email'] ?? ''),
                'username' => (string)($r['username'] ?? ''),
                'name' => $r['name'] !== null ? (string)$r['name'] : null,
                'is_admin' => (bool)($r['is_admin'] ?? false),
                'two_factor_enabled' => (bool)($r['two_factor_enabled'] ?? false),
                'email_verified_at' => $r['email_verified_at'] ? date('c', strtotime((string)$r['email_verified_at'])) : null,
                'created_at' => $r['created_at'] ? date('c', strtotime((string)$r['created_at'])) : null,
                'updated_at' => $r['updated_at'] ? date('c', strtotime((string)$r['updated_at'])) : null,
            ];
        }, $rows);

        $this->ok([
            'data' => $data,
            'page' => ['next_cursor' => $nextCursor, 'limit' => $limit],
        ]);
    }

    public function show(string $user_id): void
    {
        $key = $this->requirePlatformScope('users:read');
        if (!$key) return;

        $user = User::findByUuid($user_id);
        if (!$user) {
            $this->v2Error('not_found', 'User not found', 404);
            return;
        }

        if (!$this->requirePlatformConstraints($key, ['user_id' => (string)$user->uuid])) return;

        $this->ok([
            'data' => [
                'id' => (string)$user->uuid,
                'uuid' => (string)$user->uuid,
                'email' => (string)$user->email,
                'username' => (string)$user->username,
                'name' => $user->name,
                'is_admin' => (bool)$user->is_admin,
                'two_factor_enabled' => (bool)$user->two_factor_enabled,
                'email_verified_at' => $user->email_verified_at ? date('c', strtotime((string)$user->email_verified_at)) : null,
                'created_at' => $user->created_at ? date('c', strtotime((string)$user->created_at)) : null,
                'updated_at' => $user->updated_at ? date('c', strtotime((string)$user->updated_at)) : null,
            ],
        ]);
    }

    public function store(): void
    {
        $key = $this->requirePlatformScope('users:write');
        if (!$key) return;

        $data = $this->all();
        $email = trim((string)($data['email'] ?? ''));
        $username = trim((string)($data['username'] ?? ''));
        $name = array_key_exists('name', $data) ? (string)($data['name'] ?? '') : null;
        $isAdmin = (bool)($data['is_admin'] ?? false);

        if ($email === '') {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'email']);
            return;
        }
        if ($username === '') {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'username']);
            return;
        }

        $password = (string)($data['password'] ?? '');
        if ($password === '') {
            $password = bin2hex(random_bytes(8));
        }

        if (User::findByEmail($email)) {
            $this->v2Error('validation_error', 'Email already exists', 422, ['field' => 'email']);
            return;
        }
        if (User::findByUsername($username)) {
            $this->v2Error('validation_error', 'Username already exists', 422, ['field' => 'username']);
            return;
        }

        $user = User::create([
            'email' => $email,
            'username' => $username,
            'name' => $name !== '' ? $name : null,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'is_admin' => $isAdmin ? 1 : 0,
        ]);

        $this->ok([
            'data' => [
                'user_id' => (string)$user->uuid,
                'user' => [
                    'id' => (string)$user->uuid,
                    'uuid' => (string)$user->uuid,
                    'email' => (string)$user->email,
                    'username' => (string)$user->username,
                    'name' => $user->name,
                    'is_admin' => (bool)$user->is_admin,
                ],
                'initial_password' => $password,
            ],
        ], 201);
    }

    public function update(string $user_id): void
    {
        $key = $this->requirePlatformScope('users:write');
        if (!$key) return;

        $user = User::findByUuid($user_id);
        if (!$user) {
            $this->v2Error('not_found', 'User not found', 404);
            return;
        }

        if (!$this->requirePlatformConstraints($key, ['user_id' => (string)$user->uuid])) return;

        $data = $this->all();
        $update = [];

        foreach (['email','username','name'] as $k) {
            if (array_key_exists($k, $data)) {
                $update[$k] = $data[$k];
            }
        }
        if (array_key_exists('is_admin', $data)) {
            $update['is_admin'] = (bool)$data['is_admin'] ? 1 : 0;
        }
        if (array_key_exists('password', $data) && (string)$data['password'] !== '') {
            $update['password_hash'] = password_hash((string)$data['password'], PASSWORD_DEFAULT);
        }

        if (empty($update)) {
            $this->ok(['data' => ['updated' => false]]);
            return;
        }

        if (isset($update['email'])) {
            $existing = User::findByEmail((string)$update['email']);
            if ($existing && (int)$existing->id !== (int)$user->id) {
                $this->v2Error('validation_error', 'Email already exists', 422, ['field' => 'email']);
                return;
            }
        }
        if (isset($update['username'])) {
            $existing = User::findByUsername((string)$update['username']);
            if ($existing && (int)$existing->id !== (int)$user->id) {
                $this->v2Error('validation_error', 'Username already exists', 422, ['field' => 'username']);
                return;
            }
        }

        $user->update($update);
        $this->ok(['data' => ['updated' => true]]);
    }

    public function destroy(string $user_id): void
    {
        $key = $this->requirePlatformScope('users:write');
        if (!$key) return;

        $user = User::findByUuid($user_id);
        if (!$user) {
            $this->v2Error('not_found', 'User not found', 404);
            return;
        }

        if (!$this->requirePlatformConstraints($key, ['user_id' => (string)$user->uuid])) return;

        $user->delete();
        $this->ok(['data' => ['deleted' => true]]);
    }
}
