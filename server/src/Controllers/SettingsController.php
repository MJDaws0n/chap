<?php

namespace Chap\Controllers;

use Chap\Auth\TeamPermissionService;
use Chap\App;
use Chap\Models\ApiToken;
use Chap\Services\ApiV2\ApiTokenService;

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

        $tokens = [];
        if ($userId > 0) {
            $db = App::db();
            $rows = $db->fetchAll(
                "SELECT uuid, name, scopes, constraints, last_used_at, expires_at, created_at FROM api_tokens WHERE user_id = ? AND type = 'pat' AND revoked_at IS NULL ORDER BY id DESC",
                [$userId]
            );

            $tokens = array_map(function($r) {
                return [
                    'token_id' => (string)$r['uuid'],
                    'name' => (string)($r['name'] ?? ''),
                    'scopes' => json_decode((string)($r['scopes'] ?? '[]'), true) ?: [],
                    'constraints' => json_decode((string)($r['constraints'] ?? 'null'), true),
                    'last_used_at' => $r['last_used_at'] ? date('c', strtotime($r['last_used_at'])) : null,
                    'expires_at' => $r['expires_at'] ? date('c', strtotime($r['expires_at'])) : null,
                    'created_at' => $r['created_at'] ? date('c', strtotime($r['created_at'])) : null,
                ];
            }, $rows);
        }

        $justCreatedToken = $_SESSION['_new_api_token'] ?? null;
        unset($_SESSION['_new_api_token']);

        $this->view('settings/index', [
            'title' => 'Settings',
            'canWriteSettings' => $canWriteSettings,
            'apiTokens' => $tokens,
            'newApiToken' => $justCreatedToken,
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

    /**
     * Create a new Personal Access Token (PAT) from the Settings tab.
     */
    public function createApiToken(): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('team.settings', 'write', (int) $team->id);

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/settings');
            return;
        }

        $name = trim((string)$this->input('name', ''));
        $scopesRaw = $this->input('scopes', []);
        $expiresAtRaw = trim((string)$this->input('expires_at', ''));

        $scopes = is_array($scopesRaw) ? array_values(array_filter(array_map('strval', $scopesRaw))) : [];
        if ($name === '' || empty($scopes)) {
            flash('error', 'Name and at least one scope are required');
            $this->redirect('/settings');
            return;
        }

        $expiresDb = null;
        if ($expiresAtRaw !== '') {
            $ts = strtotime($expiresAtRaw);
            if ($ts === false) {
                flash('error', 'Invalid expiry time');
                $this->redirect('/settings');
                return;
            }
            $expiresDb = date('Y-m-d H:i:s', $ts);
        }

        // Default constraints to the current team.
        $constraints = [
            'team_id' => (string)$team->uuid,
        ];

        $rawToken = bin2hex(random_bytes(32));
        $hash = ApiTokenService::hashToken($rawToken);

        $created = ApiToken::create([
            'uuid' => uuid(),
            'user_id' => (int)($this->user?->id ?? 0),
            'name' => $name,
            'type' => 'pat',
            'token_hash' => $hash,
            'scopes' => json_encode($scopes),
            'constraints' => json_encode($constraints),
            'expires_at' => $expiresDb,
        ]);

        $_SESSION['_new_api_token'] = [
            'token_id' => $created->uuid,
            'token' => $rawToken,
        ];

        flash('success', 'API token created. Copy it now — it will not be shown again.');
        $this->redirect('/settings');
    }

    /**
     * Revoke an existing PAT from the Settings tab.
     */
    public function revokeApiToken(string $tokenId): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('team.settings', 'write', (int) $team->id);

        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/settings');
            return;
        }

        $db = App::db();
        $row = $db->fetch(
            "SELECT id FROM api_tokens WHERE uuid = ? AND user_id = ? AND type = 'pat' LIMIT 1",
            [$tokenId, (int)($this->user?->id ?? 0)]
        );
        if (!$row) {
            flash('error', 'Token not found');
            $this->redirect('/settings');
            return;
        }

        $db->update('api_tokens', ['revoked_at' => date('Y-m-d H:i:s')], 'id = ?', [(int)$row['id']]);
        flash('success', 'Token revoked');
        $this->redirect('/settings');
    }
}
