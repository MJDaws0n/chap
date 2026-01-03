<?php

namespace Chap\Controllers\Admin;

use Chap\Controllers\BaseController;
use Chap\Auth\AuthManager;
use Chap\Models\User;
use Chap\Models\ActivityLog;

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

        $this->view('admin/users/edit', [
            'title' => 'Edit User',
            'currentPage' => 'admin-users',
            'editUser' => $user,
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
            ];
            $this->redirect('/admin/users/' . $user->id . '/edit');
        }

        $update = [
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'is_admin' => $isAdmin,
        ];

        if ($newPassword !== '') {
            $update['password_hash'] = AuthManager::hashPassword($newPassword);
        }

        $user->update($update);

        ActivityLog::log('admin.user.updated', 'User', $user->id, [
            'email' => $user->email,
            'username' => $user->username,
            'is_admin' => (bool)$user->is_admin,
        ]);

        flash('success', 'User updated');
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
