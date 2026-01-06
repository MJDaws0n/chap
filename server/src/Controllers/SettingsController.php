<?php

namespace Chap\Controllers;

use Chap\Auth\TeamPermissionService;

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
        $team = $this->currentTeam();
        $this->requireTeamPermission('team.settings', 'read', (int) $team->id);

        $userId = (int) ($this->user?->id ?? 0);
        $canWriteSettings = admin_view_all() || TeamPermissionService::can((int) $team->id, $userId, 'team.settings', 'write');

        $this->view('settings/index', [
            'title' => 'Settings',
            'canWriteSettings' => $canWriteSettings,
        ]);
    }

    /**
     * Update settings
     */
    public function update(): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('team.settings', 'write', (int) $team->id);

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
