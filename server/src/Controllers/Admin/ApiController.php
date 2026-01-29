<?php

namespace Chap\Controllers\Admin;

use Chap\App;
use Chap\Controllers\BaseController;
use Chap\Services\ApiV2\ApiTokenService;
use Chap\Models\PlatformApiKey;

class ApiController extends BaseController
{
    public function index(): void
    {
        $tokens = [];

        $db = App::db();
        $rows = $db->fetchAll(
            "SELECT uuid, name, scopes, constraints, last_used_at, expires_at, created_at FROM platform_api_keys WHERE revoked_at IS NULL ORDER BY id DESC",
            []
        );

        $tokens = array_map(function ($r) {
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

        $justCreatedToken = $_SESSION['_new_platform_api_key'] ?? null;
        unset($_SESSION['_new_platform_api_key']);

        $availableScopes = [
            '*',
            'teams:read','teams:write',
            'projects:read','projects:write',
            'environments:read','environments:write',
            'applications:read','applications:write','applications:deploy',
            'deployments:read','deployments:write','deployments:cancel','deployments:rollback',
            'logs:read','logs:stream',
            'templates:read','templates:deploy',
            'services:read','services:write',
            'databases:read','databases:write',
            'nodes:read','nodes:session:mint',
            'files:read','files:write','files:delete',
            'exec:run',
            'volumes:read','volumes:write',
            'activity:read',
            'webhooks:read','webhooks:write',
            'git_sources:read','git_sources:write',
            'settings:read','settings:write',
        ];

        $this->view('admin/api/index', [
            'title' => 'Platform API',
            'currentPage' => 'admin-api',
            'apiTokens' => $tokens,
            'newApiToken' => $justCreatedToken,
            'availableScopes' => $availableScopes,
        ]);
    }

    public function createToken(): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/admin/api');
            return;
        }

        $name = trim((string)$this->input('name', ''));
        $scopesRaw = $this->input('scopes', []);
        $expiresAtRaw = trim((string)$this->input('expires_at', ''));
        $expiresIn = trim((string)$this->input('expires_in', ''));
        $constraintMode = (string)$this->input('constraint_mode', 'none');

        $scopes = is_array($scopesRaw) ? array_values(array_filter(array_map('strval', $scopesRaw))) : [];
        if ($name === '' || empty($scopes)) {
            flash('error', 'Name and at least one scope are required');
            $this->redirect('/admin/api');
            return;
        }

        $expiresDb = null;
        if ($expiresIn !== '' && $expiresIn !== 'never') {
            $map = [
                '6h' => 6 * 3600,
                '1d' => 24 * 3600,
                '7d' => 7 * 24 * 3600,
                '30d' => 30 * 24 * 3600,
                '60d' => 60 * 24 * 3600,
                '90d' => 90 * 24 * 3600,
                '1y' => 365 * 24 * 3600,
            ];
            if (!isset($map[$expiresIn])) {
                flash('error', 'Invalid expiry selection');
                $this->redirect('/admin/api');
                return;
            }
            $expiresDb = date('Y-m-d H:i:s', time() + (int)$map[$expiresIn]);
        } elseif ($expiresIn === '' && $expiresAtRaw !== '') {
            // Back-compat: accept explicit RFC3339-ish timestamp.
            $ts = strtotime($expiresAtRaw);
            if ($ts === false) {
                flash('error', 'Invalid expiry time');
                $this->redirect('/admin/api');
                return;
            }
            $expiresDb = date('Y-m-d H:i:s', $ts);
        }

        $constraints = null;
        if ($constraintMode === 'current_team') {
            $team = $this->currentTeam();
            $constraints = ['team_id' => (string)$team->uuid];
        }

        $rawToken = bin2hex(random_bytes(32));
        $hash = ApiTokenService::hashToken($rawToken);

        $created = PlatformApiKey::create([
            'uuid' => uuid(),
            'name' => $name,
            'token_hash' => $hash,
            'scopes' => json_encode($scopes),
            'constraints' => $constraints ? json_encode($constraints) : null,
            'expires_at' => $expiresDb,
            'created_by_user_id' => (int)($this->user?->id ?? 0),
        ]);

        $_SESSION['_new_platform_api_key'] = [
            'token_id' => $created->uuid,
            'token' => $rawToken,
        ];

        flash('success', 'Platform API key created. Copy it now — it will not be shown again.');
        $this->redirect('/admin/api');
    }

    public function revokeToken(string $tokenId): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/admin/api');
            return;
        }

        $db = App::db();
        $row = $db->fetch(
            "SELECT id FROM platform_api_keys WHERE uuid = ? LIMIT 1",
            [$tokenId]
        );

        if (!$row) {
            flash('error', 'Token not found');
            $this->redirect('/admin/api');
            return;
        }

        $db->update('platform_api_keys', ['revoked_at' => date('Y-m-d H:i:s')], 'id = ?', [(int)$row['id']]);
        flash('success', 'Platform API key revoked');
        $this->redirect('/admin/api');
    }
}
