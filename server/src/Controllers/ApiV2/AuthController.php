<?php

namespace Chap\Controllers\ApiV2;

use Chap\App;
use Chap\Models\ApiToken;
use Chap\Models\User;
use Chap\Security\TwoFactorService;
use Chap\Services\ApiV2\ApiTokenService;
use Chap\Services\ApiV2\CursorService;

class AuthController extends BaseApiV2Controller
{
    /**
     * POST /api/v2/auth/session
     */
    public function session(): void
    {
        $data = $this->all();

        $email = (string)($data['email'] ?? '');
        $password = (string)($data['password'] ?? '');
        $totp = (string)($data['totp'] ?? ($data['mfa_code'] ?? ''));

        if ($email === '' || $password === '') {
            $this->v2Error('invalid_request', 'Email and password required', 400);
            return;
        }

        $user = User::findByEmail($email);
        if (!$user || !$user->verifyPassword($password)) {
            $this->v2Error('unauthorized', 'Invalid credentials', 401);
            return;
        }

        if ((bool)$user->two_factor_enabled) {
            if ($totp === '') {
                $this->v2Error('mfa_required', 'MFA required', 401, ['mfa_required' => true]);
                return;
            }
            if (!TwoFactorService::verifyCode((string)$user->two_factor_secret, $totp)) {
                $this->v2Error('mfa_invalid', 'Invalid MFA code', 401, ['mfa_required' => true]);
                return;
            }
        }

        $rawToken = bin2hex(random_bytes(32));
        $hash = ApiTokenService::hashToken($rawToken);

        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        // Session tokens are intentionally broad; clients should mint scoped PATs for automation.
        $scopes = ['*'];
        $constraints = null;

        $token = ApiToken::create([
            'uuid' => uuid(),
            'user_id' => $user->id,
            'name' => 'session',
            'type' => 'session',
            'token_hash' => $hash,
            'scopes' => json_encode($scopes),
            'constraints' => $constraints,
            'expires_at' => $expiresAt,
        ]);

        $this->ok([
            'access_token' => $rawToken,
            'token_type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }

    /**
     * POST /api/v2/auth/tokens
     */
    public function createToken(): void
    {
        $callerToken = $this->apiToken();
        if (!$callerToken) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }

        $idempotencyKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/api/v2/auth/tokens';
        if ($idempotencyKey !== '') {
            $found = ApiTokenService::findIdempotencyResponse((int)$callerToken->id, $idempotencyKey, 'POST', $path);
            if ($found) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code($found['status_code']);
                echo $found['response_body'];
                return;
            }
        }

        // Require a basic settings scope (or wildcard).
        if (!ApiTokenService::scopeAllows($callerToken->scopesList(), 'settings:write')) {
            $this->v2Error('forbidden', 'Token lacks scope: settings:write', 403);
            return;
        }

        $data = $this->all();
        $name = trim((string)($data['name'] ?? ''));
        $scopes = $data['scopes'] ?? [];
        $constraints = $data['constraints'] ?? null;
        $expiresAt = $data['expires_at'] ?? null;

        if ($name === '') {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'name']);
            return;
        }
        if (!is_array($scopes) || empty($scopes)) {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'scopes']);
            return;
        }

        // Validate requested scopes are within caller scope.
        $callerScopes = $callerToken->scopesList();
        foreach ($scopes as $s) {
            $ss = trim((string)$s);
            if ($ss === '') {
                $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'scopes']);
                return;
            }
            if (!ApiTokenService::scopeAllows($callerScopes, $ss)) {
                $this->v2Error('forbidden', 'Requested scope not allowed by caller token', 403, ['scope' => $ss]);
                return;
            }
        }

        if ($constraints !== null && !is_array($constraints)) {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'constraints']);
            return;
        }

        $expiresDb = null;
        if ($expiresAt) {
            $ts = strtotime((string)$expiresAt);
            if ($ts === false) {
                $this->v2Error('validation_error', 'Invalid expires_at (RFC3339)', 422, ['field' => 'expires_at']);
                return;
            }
            $expiresDb = date('Y-m-d H:i:s', $ts);
        }

        $rawToken = bin2hex(random_bytes(32));
        $hash = ApiTokenService::hashToken($rawToken);

        $created = ApiToken::create([
            'uuid' => uuid(),
            'user_id' => (int)$this->user?->id,
            'name' => $name,
            'type' => 'pat',
            'token_hash' => $hash,
            'scopes' => json_encode(array_values($scopes)),
            'constraints' => $constraints ? json_encode($constraints) : null,
            'expires_at' => $expiresDb,
        ]);

        $resp = json_encode([
            'token_id' => $created->uuid,
            'token' => $rawToken,
            'created_at' => $created->created_at,
        ]);

        if ($idempotencyKey !== '') {
            ApiTokenService::rememberIdempotencyResponse((int)$callerToken->id, $idempotencyKey, 'POST', $path, 201, $resp);
        }

        header('Content-Type: application/json; charset=utf-8');
        http_response_code(201);
        echo $resp;
    }

    /**
     * GET /api/v2/auth/tokens
     */
    public function listTokens(): void
    {
        $callerToken = $this->apiToken();
        if (!$callerToken) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }

        if (!ApiTokenService::scopeAllows($callerToken->scopesList(), 'settings:read')) {
            $this->v2Error('forbidden', 'Token lacks scope: settings:read', 403);
            return;
        }

        $limit = CursorService::parseLimit($_GET['page']['limit'] ?? null);
        $cursor = CursorService::decodeId($_GET['page']['cursor'] ?? null);

        $db = App::db();
        $params = [(int)$this->user?->id];
        $where = "user_id = ? AND type = 'pat' AND revoked_at IS NULL";
        if ($cursor) {
            $where .= " AND id > ?";
            $params[] = $cursor;
        }

        $rows = $db->fetchAll(
            "SELECT id, uuid, name, type, scopes, constraints, last_used_at, expires_at, created_at, updated_at FROM api_tokens WHERE {$where} ORDER BY id ASC LIMIT {$limit}",
            $params
        );

        $nextCursor = null;
        if (count($rows) === $limit) {
            $last = $rows[count($rows) - 1];
            $nextCursor = CursorService::encodeId((int)$last['id']);
        }

        $data = array_map(function($r) {
            return [
                'token_id' => (string)$r['uuid'],
                'name' => (string)($r['name'] ?? ''),
                'scopes' => json_decode((string)($r['scopes'] ?? '[]'), true) ?: [],
                'constraints' => json_decode((string)($r['constraints'] ?? 'null'), true),
                'last_used_at' => $r['last_used_at'] ? date('c', strtotime($r['last_used_at'])) : null,
                'expires_at' => $r['expires_at'] ? date('c', strtotime($r['expires_at'])) : null,
                'created_at' => $r['created_at'] ? date('c', strtotime($r['created_at'])) : null,
                'updated_at' => $r['updated_at'] ? date('c', strtotime($r['updated_at'])) : null,
            ];
        }, $rows);

        $this->ok([
            'data' => $data,
            'page' => ['next_cursor' => $nextCursor, 'limit' => $limit],
        ]);
    }

    /**
     * DELETE /api/v2/auth/tokens/{token_id}
     */
    public function revokeToken(string $token_id): void
    {
        $callerToken = $this->apiToken();
        if (!$callerToken) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        if (!ApiTokenService::scopeAllows($callerToken->scopesList(), 'settings:write')) {
            $this->v2Error('forbidden', 'Token lacks scope: settings:write', 403);
            return;
        }

        $db = App::db();
        $row = $db->fetch(
            "SELECT id, uuid FROM api_tokens WHERE uuid = ? AND user_id = ? AND type = 'pat' LIMIT 1",
            [$token_id, (int)$this->user?->id]
        );
        if (!$row) {
            $this->v2Error('not_found', 'Token not found', 404);
            return;
        }

        $db->update('api_tokens', ['revoked_at' => date('Y-m-d H:i:s')], 'id = ?', [(int)$row['id']]);
        $this->ok(['data' => ['revoked' => true]]);
    }
}
