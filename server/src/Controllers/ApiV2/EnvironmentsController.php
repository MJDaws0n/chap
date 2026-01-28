<?php

namespace Chap\Controllers\ApiV2;

use Chap\App;
use Chap\Auth\TeamPermissionService;
use Chap\Models\Environment;
use Chap\Models\Project;
use Chap\Models\Team;
use Chap\Services\ApiV2\ApiTokenService;
use Chap\Services\ApiV2\CursorService;

class EnvironmentsController extends BaseApiV2Controller
{
    public function index(): void
    {
        $token = $this->apiToken();
        if (!$token) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        if (!ApiTokenService::scopeAllows($token->scopesList(), 'environments:read')) {
            $this->v2Error('forbidden', 'Token lacks scope: environments:read', 403);
            return;
        }

        $teamUuid = $_GET['filter']['team_id'] ?? ($_SERVER['HTTP_X_TEAM_ID'] ?? null);
        $team = $teamUuid ? Team::findByUuid((string)$teamUuid) : $this->user?->currentTeam();
        if (!$team) {
            $this->v2Error('invalid_request', 'No team selected', 400);
            return;
        }
        if (!ApiTokenService::constraintsAllow($token->constraintsMap(), ['team_id' => (string)$team->uuid])) {
            $this->v2Error('forbidden', 'Token constraints forbid this team', 403);
            return;
        }

        $userId = (int)($this->user?->id ?? 0);
        if (!(bool)($this->user?->is_admin ?? false)) {
            if ($userId <= 0 || !TeamPermissionService::can((int)$team->id, $userId, 'environments', 'read')) {
                $this->v2Error('forbidden', 'Permission denied', 403);
                return;
            }
        }

        $projectUuid = trim((string)($_GET['filter']['project_id'] ?? ''));
        $project = null;
        if ($projectUuid !== '') {
            $project = Project::findByUuid($projectUuid);
            if (!$project || (int)$project->team_id !== (int)$team->id) {
                $this->v2Error('not_found', 'Project not found', 404);
                return;
            }
            if (!ApiTokenService::constraintsAllow($token->constraintsMap(), ['project_id' => (string)$project->uuid])) {
                $this->v2Error('forbidden', 'Token constraints forbid this project', 403);
                return;
            }
        }

        $limit = CursorService::parseLimit($_GET['page']['limit'] ?? null);
        $cursor = CursorService::decodeId($_GET['page']['cursor'] ?? null);

        $db = App::db();
        $params = [(int)$team->id];
        $where = 'p.team_id = ?';
        if ($cursor) {
            $where .= ' AND e.id > ?';
            $params[] = $cursor;
        }
        if ($project) {
            $where .= ' AND p.id = ?';
            $params[] = (int)$project->id;
        }

        $rows = $db->fetchAll(
            "SELECT e.id AS internal_id, e.uuid AS uuid, e.name AS name, e.project_id AS project_internal_id, p.uuid AS project_uuid, t.uuid AS team_uuid\n" .
            "FROM environments e\n" .
            "JOIN projects p ON p.id = e.project_id\n" .
            "JOIN teams t ON t.id = p.team_id\n" .
            "WHERE {$where}\n" .
            "ORDER BY e.id ASC\n" .
            "LIMIT {$limit}",
            $params
        );

        $nextCursor = null;
        if (count($rows) === $limit) {
            $nextCursor = CursorService::encodeId((int)$rows[count($rows) - 1]['internal_id']);
        }

        $data = array_map(function($r) {
            $uuid = (string)($r['uuid'] ?? '');
            return [
                'internal_id' => (int)($r['internal_id'] ?? 0),
                'id' => $uuid,
                'uuid' => $uuid,
                'name' => (string)($r['name'] ?? ''),
                'team_id' => (string)($r['team_uuid'] ?? ''),
                'project_id' => (string)($r['project_uuid'] ?? ''),
            ];
        }, $rows);

        $this->ok([
            'data' => $data,
            'page' => ['next_cursor' => $nextCursor, 'limit' => $limit],
        ]);
    }

    public function show(string $environment_id): void
    {
        $token = $this->apiToken();
        if (!$token) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        if (!ApiTokenService::scopeAllows($token->scopesList(), 'environments:read')) {
            $this->v2Error('forbidden', 'Token lacks scope: environments:read', 403);
            return;
        }

        $env = Environment::findByUuid($environment_id);
        if (!$env) {
            $this->v2Error('not_found', 'Environment not found', 404);
            return;
        }

        $project = $env->project();
        $team = $project ? Team::find((int)$project->team_id) : null;
        if (!$project || !$team) {
            $this->v2Error('not_found', 'Environment not found', 404);
            return;
        }

        if (!ApiTokenService::constraintsAllow($token->constraintsMap(), [
            'team_id' => (string)$team->uuid,
            'project_id' => (string)$project->uuid,
            'environment_id' => (string)$env->uuid,
        ])) {
            $this->v2Error('forbidden', 'Token constraints forbid this environment', 403);
            return;
        }

        $userId = (int)($this->user?->id ?? 0);
        if (!(bool)($this->user?->is_admin ?? false)) {
            if ($userId <= 0 || !TeamPermissionService::can((int)$team->id, $userId, 'environments', 'read')) {
                $this->v2Error('forbidden', 'Permission denied', 403);
                return;
            }
        }

        $this->ok([
            'data' => [
                'id' => (string)$env->uuid,
                'uuid' => (string)$env->uuid,
                'name' => (string)($env->name ?? ''),
                'team_id' => (string)$team->uuid,
                'project_id' => (string)$project->uuid,
            ],
        ]);
    }
}