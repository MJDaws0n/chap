<?php

namespace Chap\Controllers\ApiV2\Platform;

use Chap\App;
use Chap\Models\Application;
use Chap\Models\Deployment;
use Chap\Models\Team;
use Chap\Services\DeploymentService;
use Chap\Services\ChapScript\ChapScriptPreDeploy;
use Chap\Services\ApiV2\CursorService;
use Chap\Services\ApiV2\ApiTokenService;

class DeploymentsController extends BasePlatformController
{
    /**
     * GET /api/v2/platform/deployments
     * Optional filters: filter[team_id], filter[project_id], filter[environment_id], filter[application_id], filter[deployment_id], filter[node_id]
     */
    public function index(): void
    {
        $key = $this->requirePlatformScope('deployments:read');
        if (!$key) return;

        $filterTeamUuid = trim((string)($_GET['filter']['team_id'] ?? ''));
        $filterProjectUuid = trim((string)($_GET['filter']['project_id'] ?? ''));
        $filterEnvUuid = trim((string)($_GET['filter']['environment_id'] ?? ''));
        $filterAppUuid = trim((string)($_GET['filter']['application_id'] ?? ''));
        $filterDeploymentUuid = trim((string)($_GET['filter']['deployment_id'] ?? ''));
        $filterNodeUuid = trim((string)($_GET['filter']['node_id'] ?? ''));

        $limit = CursorService::parseLimit($_GET['page']['limit'] ?? null);
        $cursor = CursorService::decodeId($_GET['page']['cursor'] ?? null);

        $db = App::db();
        $params = [];
        $where = '1=1';

        if ($filterTeamUuid !== '') {
            $where .= ' AND t.uuid = ?';
            $params[] = $filterTeamUuid;
        }
        if ($filterProjectUuid !== '') {
            $where .= ' AND p.uuid = ?';
            $params[] = $filterProjectUuid;
        }
        if ($filterEnvUuid !== '') {
            $where .= ' AND e.uuid = ?';
            $params[] = $filterEnvUuid;
        }
        if ($filterAppUuid !== '') {
            $where .= ' AND a.uuid = ?';
            $params[] = $filterAppUuid;
        }
        if ($filterDeploymentUuid !== '') {
            $where .= ' AND d.uuid = ?';
            $params[] = $filterDeploymentUuid;
        }
        if ($filterNodeUuid !== '') {
            $where .= ' AND n.uuid = ?';
            $params[] = $filterNodeUuid;
        }
        if ($cursor) {
            $where .= ' AND d.id > ?';
            $params[] = $cursor;
        }

        $rows = $db->fetchAll(
            "SELECT d.id, d.uuid, d.status, d.git_commit_sha, d.git_commit_message, d.started_at, d.finished_at, d.error_message, d.created_at,\n" .
            "a.uuid AS application_uuid, e.uuid AS environment_uuid, p.uuid AS project_uuid, t.uuid AS team_uuid, n.uuid AS node_uuid\n" .
            "FROM deployments d\n" .
            "JOIN applications a ON a.id = d.application_id\n" .
            "JOIN environments e ON e.id = a.environment_id\n" .
            "JOIN projects p ON p.id = e.project_id\n" .
            "JOIN teams t ON t.id = p.team_id\n" .
            "LEFT JOIN nodes n ON n.id = d.node_id\n" .
            "WHERE {$where}\n" .
            "ORDER BY d.id ASC\n" .
            "LIMIT {$limit}",
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
                'status' => (string)($r['status'] ?? ''),
                'application_id' => (string)($r['application_uuid'] ?? ''),
                'team_id' => (string)($r['team_uuid'] ?? ''),
                'project_id' => (string)($r['project_uuid'] ?? ''),
                'environment_id' => (string)($r['environment_uuid'] ?? ''),
                'node_id' => $r['node_uuid'] ? (string)$r['node_uuid'] : null,
                'commit_sha' => $r['git_commit_sha'] ? (string)$r['git_commit_sha'] : null,
                'commit_message' => $r['git_commit_message'] ? (string)$r['git_commit_message'] : null,
                'started_at' => $r['started_at'] ? date('c', strtotime((string)$r['started_at'])) : null,
                'finished_at' => $r['finished_at'] ? date('c', strtotime((string)$r['finished_at'])) : null,
                'error_message' => $r['error_message'] ? (string)$r['error_message'] : null,
                'created_at' => $r['created_at'] ? date('c', strtotime((string)$r['created_at'])) : null,
            ];
        }, $rows);

        $this->ok([
            'data' => $data,
            'page' => ['next_cursor' => $nextCursor, 'limit' => $limit],
        ]);
    }

    /**
     * POST /api/v2/platform/applications/{application_id}/deployments
     */
    public function createForApplication(string $application_id): void
    {
        $key = $this->requirePlatformScope('applications:deploy');
        if (!$key) return;

        $app = Application::findByUuid($application_id);
        if (!$app) {
            $this->v2Error('not_found', 'Application not found', 404);
            return;
        }

        $project = $app->environment()?->project();
        $team = $project ? Team::find((int)$project->team_id) : null;
        if (!$project || !$team) {
            $this->v2Error('not_found', 'Application not found', 404);
            return;
        }

        if (!$this->requirePlatformConstraints($key, [
            'team_id' => (string)$team->uuid,
            'project_id' => (string)$project->uuid,
            'environment_id' => (string)($app->environment()?->uuid ?? ''),
            'application_id' => (string)$app->uuid,
            'node_id' => $app->node()?->uuid ? (string)$app->node()?->uuid : null,
        ])) return;

        if ((string)($app->status ?? '') === 'deploying') {
            $this->v2Error('failed_precondition', 'Application is already deploying', 409);
            return;
        }

        $data = $this->all();
        $commitSha = isset($data['commit_sha']) ? trim((string)$data['commit_sha']) : null;
        if ($commitSha === '') $commitSha = null;

        try {
            $pre = ChapScriptPreDeploy::run($app, [
                'commit_sha' => $commitSha,
                'triggered_by' => 'platform',
                'triggered_by_name' => 'platform',
                'user_id' => 0,
            ]);

            if (($pre['status'] ?? '') === 'waiting') {
                $run = $pre['run'] ?? null;
                $this->v2Error('action_required', 'Template script requires input', 409, [
                    'script_run' => $run ? ['uuid' => $run->uuid, 'status' => $run->status] : null,
                    'prompt' => $pre['prompt'] ?? null,
                ]);
                return;
            }

            if (($pre['status'] ?? '') === 'stopped') {
                $this->v2Error('validation_error', (string)($pre['message'] ?? 'Deployment blocked by template script'), 422);
                return;
            }
        } catch (\Throwable $e) {
            $this->v2Error('validation_error', $e->getMessage(), 422);
            return;
        }

        try {
            $deployment = DeploymentService::create($app, $commitSha, [
                'triggered_by' => 'platform',
                'triggered_by_name' => 'platform',
            ]);
        } catch (\Throwable $e) {
            $this->v2Error('validation_error', $e->getMessage(), 422);
            return;
        }

        $this->ok([
            'data' => [
                'deployment_id' => (string)$deployment->uuid,
                'deployment' => [
                    'id' => (string)$deployment->uuid,
                    'uuid' => (string)$deployment->uuid,
                    'status' => (string)$deployment->status,
                    'commit_sha' => $deployment->git_commit_sha ?? $deployment->commit_sha,
                    'created_at' => $deployment->created_at ? date('c', strtotime((string)$deployment->created_at)) : null,
                ],
            ],
        ], 201);
    }

    public function show(string $deployment_id): void
    {
        $key = $this->requirePlatformScope('deployments:read');
        if (!$key) return;

        $deployment = Deployment::findByUuid($deployment_id);
        if (!$deployment) {
            $this->v2Error('not_found', 'Deployment not found', 404);
            return;
        }

        $app = $deployment->application();
        $project = $app?->environment()?->project();
        $team = $project ? Team::find((int)$project->team_id) : null;
        if (!$app || !$project || !$team) {
            $this->v2Error('not_found', 'Deployment not found', 404);
            return;
        }

        if (!$this->requirePlatformConstraints($key, [
            'team_id' => (string)$team->uuid,
            'project_id' => (string)$project->uuid,
            'environment_id' => (string)($app->environment()?->uuid ?? ''),
            'application_id' => (string)$app->uuid,
            'deployment_id' => (string)$deployment->uuid,
            'node_id' => $app->node()?->uuid ? (string)$app->node()?->uuid : null,
        ])) return;

        $this->ok([
            'data' => [
                'id' => (string)$deployment->uuid,
                'uuid' => (string)$deployment->uuid,
                'status' => (string)$deployment->status,
                'application_id' => (string)$app->uuid,
                'commit_sha' => $deployment->git_commit_sha ?? $deployment->commit_sha,
                'commit_message' => $deployment->git_commit_message ?? $deployment->commit_message,
                'error_message' => $deployment->error_message,
                'started_at' => $deployment->started_at ? date('c', strtotime((string)$deployment->started_at)) : null,
                'finished_at' => $deployment->finished_at ? date('c', strtotime((string)$deployment->finished_at)) : null,
                'created_at' => $deployment->created_at ? date('c', strtotime((string)$deployment->created_at)) : null,
            ],
        ]);
    }

    public function logs(string $deployment_id): void
    {
        $key = $this->requirePlatformScope('deployments:read');
        if (!$key) return;

        $deployment = Deployment::findByUuid($deployment_id);
        if (!$deployment) {
            $this->v2Error('not_found', 'Deployment not found', 404);
            return;
        }

        $app = $deployment->application();
        $project = $app?->environment()?->project();
        $team = $project ? Team::find((int)$project->team_id) : null;
        if (!$app || !$project || !$team) {
            $this->v2Error('not_found', 'Deployment not found', 404);
            return;
        }

        if (!$this->requirePlatformConstraints($key, [
            'team_id' => (string)$team->uuid,
            'project_id' => (string)$project->uuid,
            'environment_id' => (string)($app->environment()?->uuid ?? ''),
            'application_id' => (string)$app->uuid,
            'deployment_id' => (string)$deployment->uuid,
            'node_id' => $app->node()?->uuid ? (string)$app->node()?->uuid : null,
        ])) return;

        $this->ok([
            'data' => [
                'status' => (string)$deployment->status,
                'logs' => $deployment->logsArray(),
            ],
        ]);
    }

    public function cancel(string $deployment_id): void
    {
        $key = $this->platformKey();
        if (!$key) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        $scopes = $key->scopesList();
        if (!ApiTokenService::scopeAllows($scopes, 'deployments:write') && !ApiTokenService::scopeAllows($scopes, 'deployments:cancel')) {
            $this->v2Error('forbidden', 'Token lacks scope: deployments:write (or deployments:cancel)', 403);
            return;
        }

        $deployment = Deployment::findByUuid($deployment_id);
        if (!$deployment) {
            $this->v2Error('not_found', 'Deployment not found', 404);
            return;
        }

        $app = $deployment->application();
        $project = $app?->environment()?->project();
        $team = $project ? Team::find((int)$project->team_id) : null;
        if (!$app || !$project || !$team) {
            $this->v2Error('not_found', 'Deployment not found', 404);
            return;
        }

        if (!$this->requirePlatformConstraints($key, [
            'team_id' => (string)$team->uuid,
            'project_id' => (string)$project->uuid,
            'environment_id' => (string)($app->environment()?->uuid ?? ''),
            'application_id' => (string)$app->uuid,
            'deployment_id' => (string)$deployment->uuid,
            'node_id' => $app->node()?->uuid ? (string)$app->node()?->uuid : null,
        ])) return;

        if (!$deployment->canBeCancelled()) {
            $this->v2Error('failed_precondition', 'Deployment cannot be cancelled', 409);
            return;
        }

        DeploymentService::cancel($deployment);
        $this->ok(['data' => ['cancelled' => true]]);
    }

    public function rollback(string $deployment_id): void
    {
        $key = $this->platformKey();
        if (!$key) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        $scopes = $key->scopesList();
        if (!ApiTokenService::scopeAllows($scopes, 'deployments:write') && !ApiTokenService::scopeAllows($scopes, 'deployments:rollback')) {
            $this->v2Error('forbidden', 'Token lacks scope: deployments:write (or deployments:rollback)', 403);
            return;
        }

        $deployment = Deployment::findByUuid($deployment_id);
        if (!$deployment) {
            $this->v2Error('not_found', 'Deployment not found', 404);
            return;
        }

        $app = $deployment->application();
        $project = $app?->environment()?->project();
        $team = $project ? Team::find((int)$project->team_id) : null;
        if (!$app || !$project || !$team) {
            $this->v2Error('not_found', 'Deployment not found', 404);
            return;
        }

        if (!$this->requirePlatformConstraints($key, [
            'team_id' => (string)$team->uuid,
            'project_id' => (string)$project->uuid,
            'environment_id' => (string)($app->environment()?->uuid ?? ''),
            'application_id' => (string)$app->uuid,
            'deployment_id' => (string)$deployment->uuid,
            'node_id' => $app->node()?->uuid ? (string)$app->node()?->uuid : null,
        ])) return;

        if (!in_array((string)$deployment->status, ['running', 'failed'], true)) {
            $this->v2Error('failed_precondition', 'Cannot rollback to this deployment', 409);
            return;
        }

        $new = DeploymentService::rollback($deployment, [
            'triggered_by' => 'rollback',
            'triggered_by_name' => 'platform',
        ]);

        $this->ok([
            'data' => [
                'deployment_id' => (string)$new->uuid,
                'deployment' => [
                    'id' => (string)$new->uuid,
                    'uuid' => (string)$new->uuid,
                    'status' => (string)$new->status,
                    'commit_sha' => $new->git_commit_sha ?? $new->commit_sha,
                ],
            ],
        ], 201);
    }
}
