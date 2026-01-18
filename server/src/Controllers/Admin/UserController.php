<?php

namespace Chap\Controllers\Admin;

use Chap\Controllers\BaseController;
use Chap\Auth\AuthManager;
use Chap\Models\User;
use Chap\Models\ActivityLog;
use Chap\Models\Node;
use Chap\App;
use Chap\Services\LimitCascadeService;
use Chap\Services\ResourceHierarchy;

class UserController extends BaseController
{
    public function index(): void
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 25;

        $pagination = User::paginate($page, $perPage);

        $this->view('admin/users/index', [
            'title' => 'Users',
            'currentPage' => 'admin-users',
            'users' => $pagination['data'],
            'pagination' => $pagination,
        ]);
    }

    public function create(): void
    {
        $this->view('admin/users/create', [
            'title' => 'Create User',
            'currentPage' => 'admin-users',
        ]);
    }

    public function store(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/admin/users/create');
        }

        $name = trim((string)$this->input('name', ''));
        $username = trim((string)$this->input('username', ''));
        $email = trim((string)$this->input('email', ''));
        $password = (string)$this->input('password', '');
        $isAdmin = $this->input('is_admin') === 'on';

        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Name is required';
        }

        if ($username === '') {
            $errors['username'] = 'Username is required';
        } elseif (!preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $username)) {
            $errors['username'] = 'Username must be 3-30 characters (letters, numbers, - _)';
        } elseif (User::findByUsername($username)) {
            $errors['username'] = 'Username already taken';
        }

        if ($email === '') {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email address';
        } elseif (User::findByEmail($email)) {
            $errors['email'] = 'Email already registered';
        }

        if ($password === '') {
            $errors['password'] = 'Password is required';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }

        if (!empty($errors)) {
            $_SESSION['_errors'] = $errors;
            $_SESSION['_old_input'] = [
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'is_admin' => $isAdmin ? 'on' : 'off',
            ];
            $this->redirect('/admin/users/create');
        }

        $user = User::create([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password_hash' => AuthManager::hashPassword($password),
            'is_admin' => $isAdmin,
        ]);

        // Keep data model consistent: every user gets a personal team.
        $user->createPersonalTeam();

        ActivityLog::log('admin.user.created', 'User', $user->id, [
            'email' => $user->email,
            'username' => $user->username,
            'is_admin' => (bool)$user->is_admin,
        ]);

        flash('success', 'User created');
        $this->redirect('/admin/users');
    }

    public function edit(string $id): void
    {
        $user = User::find((int)$id);
        if (!$user) {
            flash('error', 'User not found');
            $this->redirect('/admin/users');
        }

        $db = App::db();
        $selectedRows = $db->fetchAll(
            'SELECT node_id FROM user_node_access WHERE user_id = ?',
            [$user->id]
        );
        $selectedNodeIds = array_values(array_map(fn($r) => (int)$r['node_id'], $selectedRows));

        $nodes = Node::all();

        $nodeAccessMode = $user->node_access_mode ?? 'allow_selected';

        $this->view('admin/users/edit', [
            'title' => 'Edit User',
            'currentPage' => 'admin-users',
            'editUser' => $user,
            'nodes' => $nodes,
            'selectedNodeIds' => $selectedNodeIds,
            'nodeAccessMode' => $nodeAccessMode,
        ]);
    }

    public function update(string $id): void
    {
        $user = User::find((int)$id);
        if (!$user) {
            flash('error', 'User not found');
            $this->redirect('/admin/users');
        }

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/admin/users/' . $user->id . '/edit');
        }

        $name = trim((string)$this->input('name', ''));
        $username = trim((string)$this->input('username', ''));
        $email = trim((string)$this->input('email', ''));
        $newPassword = (string)$this->input('password', '');
        $isAdmin = $this->input('is_admin') === 'on';

        $nodeAccessMode = (string)$this->input('node_access_mode', (string)($user->node_access_mode ?? 'allow_selected'));
        if (!in_array($nodeAccessMode, ['allow_selected', 'allow_all_except'], true)) {
            $nodeAccessMode = 'allow_selected';
        }

        $oldTotals = ResourceHierarchy::userMax($user);

        $maxCpuMillicores = (int)$this->input('max_cpu_millicores', (string)$user->max_cpu_millicores);
        $maxRamMb = (int)$this->input('max_ram_mb', (string)$user->max_ram_mb);
        $maxStorageMb = (int)$this->input('max_storage_mb', (string)$user->max_storage_mb);
        $maxPorts = (int)$this->input('max_ports', (string)$user->max_ports);
        $maxBandwidth = (int)$this->input('max_bandwidth_mbps', (string)$user->max_bandwidth_mbps);
        $maxPids = (int)$this->input('max_pids', (string)$user->max_pids);

        $newTotals = [
            'cpu_millicores' => $maxCpuMillicores,
            'ram_mb' => $maxRamMb,
            'storage_mb' => $maxStorageMb,
            'ports' => $maxPorts,
            'bandwidth_mbps' => $maxBandwidth,
            'pids' => $maxPids,
        ];

        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Name is required';
        }

        if ($username === '') {
            $errors['username'] = 'Username is required';
        } elseif (!preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $username)) {
            $errors['username'] = 'Username must be 3-30 characters (letters, numbers, - _)';
        } else {
            $existing = User::findByUsername($username);
            if ($existing && $existing->id !== $user->id) {
                $errors['username'] = 'Username already taken';
            }
        }

        if ($email === '') {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email address';
        } else {
            $existing = User::findByEmail($email);
            if ($existing && $existing->id !== $user->id) {
                $errors['email'] = 'Email already registered';
            }
        }

        if ($newPassword !== '' && strlen($newPassword) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }

        // Validate max limits (-1 means unlimited)
        if ($maxCpuMillicores !== -1 && $maxCpuMillicores <= 0) {
            $errors['max_cpu_millicores'] = 'CPU max must be greater than 0, or -1 for unlimited';
        }
        if ($maxRamMb !== -1 && $maxRamMb <= 0) {
            $errors['max_ram_mb'] = 'RAM max must be greater than 0, or -1 for unlimited';
        }
        if ($maxStorageMb !== -1 && $maxStorageMb <= 0) {
            $errors['max_storage_mb'] = 'Storage max must be greater than 0, or -1 for unlimited';
        }
        if ($maxPorts !== -1 && $maxPorts <= 0) {
            $errors['max_ports'] = 'Port max must be greater than 0, or -1 for unlimited';
        }
        if ($maxBandwidth !== -1 && $maxBandwidth <= 0) {
            $errors['max_bandwidth_mbps'] = 'Bandwidth max must be greater than 0, or -1 for unlimited';
        }
        if ($maxPids !== -1 && $maxPids <= 0) {
            $errors['max_pids'] = 'PIDs max must be greater than 0, or -1 for unlimited';
        }

        // Prevent removing the last admin.
        if (!$isAdmin && $user->is_admin) {
            $adminCount = User::count('is_admin = 1');
            if ($adminCount <= 1) {
                $errors['is_admin'] = 'There must be at least one admin user';
            }
        }

        if (!empty($errors)) {
            $_SESSION['_errors'] = $errors;
            $_SESSION['_old_input'] = [
                'name' => $name,
                'username' => $username,
                'email' => $email,
                'is_admin' => $isAdmin ? 'on' : 'off',
                'max_cpu_millicores' => (string)$maxCpuMillicores,
                'max_ram_mb' => (string)$maxRamMb,
                'max_storage_mb' => (string)$maxStorageMb,
                'max_ports' => (string)$maxPorts,
                'max_bandwidth_mbps' => (string)$maxBandwidth,
                'max_pids' => (string)$maxPids,
            ];
            $this->redirect('/admin/users/' . $user->id . '/edit');
        }

        $update = [
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'is_admin' => $isAdmin,
            'max_cpu_millicores' => $maxCpuMillicores,
            'max_ram_mb' => $maxRamMb,
            'max_storage_mb' => $maxStorageMb,
            'max_ports' => $maxPorts,
            'max_bandwidth_mbps' => $maxBandwidth,
            'max_pids' => $maxPids,
            'node_access_mode' => $nodeAccessMode,
        ];

        if ($newPassword !== '') {
            $update['password_hash'] = AuthManager::hashPassword($newPassword);
        }

        $user->update($update);

        // If max limits were reduced, enforce owned-team hierarchy + redeploy descendant applications.
        if (LimitCascadeService::anyReduction($oldTotals, $newTotals)) {
            $enforced = LimitCascadeService::enforceUnderUser($user);
            $apps = LimitCascadeService::applicationIdsForUserOwnedTeams((int)$user->id);
            $redeploy = LimitCascadeService::redeployApplications($apps, $this->user, 'limits');

            $details = [];
            if (($enforced['changed_fields'] ?? 0) > 0) {
                $details[] = 'auto-adjusted child limits';
            }
            $details[] = 'redeploy started: ' . ($redeploy['started'] ?? 0);
            flash('info', 'User max limits reduced: ' . implode(', ', $details));
        }

        // Node access assignments
        $nodeIds = $this->input('node_access', []);
        if (!is_array($nodeIds)) {
            $nodeIds = [];
        }
        $nodeIds = array_values(array_unique(array_map('intval', $nodeIds)));

        $db = App::db();
        $db->delete('user_node_access', 'user_id = ?', [$user->id]);
        foreach ($nodeIds as $nodeId) {
            if ($nodeId <= 0) {
                continue;
            }
            // Ignore invalid nodes silently.
            if (!Node::find($nodeId)) {
                continue;
            }
            try {
                $db->insert('user_node_access', [
                    'user_id' => $user->id,
                    'node_id' => $nodeId,
                ]);
            } catch (\Throwable) {
                // ignore duplicates
            }
        }

        ActivityLog::log('admin.user.updated', 'User', $user->id, [
            'email' => $user->email,
            'username' => $user->username,
            'is_admin' => (bool)$user->is_admin,
        ]);

        flash('success', 'User updated');
        $this->redirect('/admin/users/' . $user->id . '/edit');
    }

    public function resetMfa(string $id): void
    {
        $user = User::find((int)$id);
        if (!$user) {
            flash('error', 'User not found');
            $this->redirect('/admin/users');
        }

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/admin/users/' . (int)$id . '/edit');
        }

        $user->update([
            'two_factor_secret' => null,
            'two_factor_enabled' => false,
        ]);

        ActivityLog::log('admin.user.mfa_reset', 'User', $user->id, [
            'email' => $user->email,
        ]);

        flash('success', 'User MFA has been reset');
        $this->redirect('/admin/users/' . $user->id . '/edit');
    }

    public function destroy(string $id): void
    {
        $user = User::find((int)$id);
        if (!$user) {
            flash('error', 'User not found');
            $this->redirect('/admin/users');
        }

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/admin/users');
        }

        if ($this->user && $user->id === $this->user->id) {
            flash('error', 'You cannot delete your own account from the admin panel');
            $this->redirect('/admin/users');
        }

        if ($user->is_admin) {
            $adminCount = User::count('is_admin = 1');
            if ($adminCount <= 1) {
                flash('error', 'You cannot delete the last admin user');
                $this->redirect('/admin/users');
            }
        }

        $email = $user->email;
        $username = $user->username;

        try {
            $user->delete();
        } catch (\Throwable $e) {
            flash('error', 'Failed to delete user');
            $this->redirect('/admin/users');
        }

        ActivityLog::log('admin.user.deleted', 'User', null, [
            'email' => $email,
            'username' => $username,
        ]);

        flash('success', 'User deleted');
        $this->redirect('/admin/users');
    }
}
