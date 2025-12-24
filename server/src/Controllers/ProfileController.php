<?php

namespace Chap\Controllers;

/**
 * Profile Controller
 */
class ProfileController extends BaseController
{
    /**
     * Show profile page
     */
    public function index(): void
    {
        $this->view('profile/index', [
            'title' => 'Profile',
        ]);
    }

    /**
     * Update profile
     */
    public function update(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/profile');
            return;
        }

        $data = $this->all();

        // Update name
        if (!empty($data['name'])) {
            $this->user->update(['name' => $data['name']]);
        }

        // Update email
        if (!empty($data['email']) && $data['email'] !== $this->user->email) {
            // Check if email is already taken
            $existing = \Chap\Models\User::findByEmail($data['email']);
            if ($existing) {
                flash('error', 'Email already in use');
                $this->redirect('/profile');
                return;
            }
            $this->user->update([
                'email' => $data['email'],
                'email_verified_at' => null, // Require re-verification
            ]);
        }

        flash('success', 'Profile updated');
        $this->redirect('/profile');
    }

    /**
     * Update password
     */
    public function updatePassword(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/profile');
            return;
        }

        $data = $this->all();

        // Validate current password
        if (!$this->user->verifyPassword($data['current_password'] ?? '')) {
            flash('error', 'Current password is incorrect');
            $this->redirect('/profile');
            return;
        }

        // Validate new password
        if (empty($data['password']) || strlen($data['password']) < 8) {
            flash('error', 'New password must be at least 8 characters');
            $this->redirect('/profile');
            return;
        }

        if ($data['password'] !== ($data['password_confirmation'] ?? '')) {
            flash('error', 'Password confirmation does not match');
            $this->redirect('/profile');
            return;
        }

        // Update password
        $this->user->update([
            'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT)
        ]);

        flash('success', 'Password updated');
        $this->redirect('/profile');
    }

    /**
     * Delete account
     */
    public function destroy(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/profile');
            return;
        }

        // Delete all user's personal teams and associated data
        $personalTeam = $this->user->personalTeam();
        if ($personalTeam) {
            // TODO: Delete all team resources (projects, apps, etc.)
            $personalTeam->delete();
        }

        // Delete user
        $this->user->delete();

        // Clear session
        session_destroy();

        $this->redirect('/');
    }
}
