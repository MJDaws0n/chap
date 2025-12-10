<?php

namespace Chap\Controllers;

/**
 * Settings Controller
 */
class SettingsController extends BaseController
{
    /**
     * Show settings page
     */
    public function index(): void
    {
        $this->view('settings/index', [
            'title' => 'Settings',
        ]);
    }

    /**
     * Update settings
     */
    public function update(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/settings');
            return;
        }

        // TODO: Implement settings update logic
        // Settings could include:
        // - Default node for deployments
        // - Notification preferences
        // - API token generation
        // - Two-factor authentication

        flash('success', 'Settings updated');
        $this->redirect('/settings');
    }
}
