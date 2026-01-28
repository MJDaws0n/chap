<?php

namespace Chap\Controllers\ApiV2;

use Chap\App;
use Chap\Auth\TeamPermissionService;
use Chap\Models\Environment;
use Chap\Models\Node;
use Chap\Models\Project;
use Chap\Models\Team;
use Chap\Services\ApiV2\ApiTokenService;
use Chap\Services\ApiV2\CursorService;

class ApplicationsController extends BaseApiV2Controller
{
	public function index(): void
	{
		try {
		$token = $this->apiToken();
		if (!$token) {
			$this->v2Error('unauthorized', 'Unauthorized', 401);
			return;
		}
		if (!ApiTokenService::scopeAllows($token->scopesList(), 'applications:read')) {
			$this->v2Error('forbidden', 'Token lacks scope: applications:read', 403);
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
		if (!admin_view_all() && ($userId <= 0 || !TeamPermissionService::can((int)$team->id, $userId, 'applications', 'read'))) {
			$this->v2Error('forbidden', 'Permission denied', 403);
			return;
		}

		$constraints = $token->constraintsMap();

		$filterProjectUuid = $_GET['filter']['project_id'] ?? null;
		$filterEnvUuid = $_GET['filter']['environment_id'] ?? null;
		$filterAppUuid = $_GET['filter']['application_id'] ?? null;
		$filterNodeUuid = $_GET['filter']['node_id'] ?? null;

		$projectUuid = $this->resolveFilterAndConstraint($filterProjectUuid, $constraints['project_id'] ?? null);
		$envUuid = $this->resolveFilterAndConstraint($filterEnvUuid, $constraints['environment_id'] ?? null);
		$appUuid = $this->resolveFilterAndConstraint($filterAppUuid, $constraints['application_id'] ?? null);
		$nodeUuid = $this->resolveFilterAndConstraint($filterNodeUuid, $constraints['node_id'] ?? null);

		$project = null;
		if ($projectUuid !== null) {
			$project = Project::findByUuid($projectUuid);
			if (!$project || (int)$project->team_id !== (int)$team->id) {
				$this->v2Error('not_found', 'Project not found', 404);
				return;
			}
		}

		$environment = null;
		if ($envUuid !== null) {
			$environment = Environment::findByUuid($envUuid);
			if (!$environment) {
				$this->v2Error('not_found', 'Environment not found', 404);
				return;
			}
			$envProject = $environment->project();
			if (!$envProject || (int)$envProject->team_id !== (int)$team->id) {
				$this->v2Error('forbidden', 'Token constraints forbid this environment', 403);
				return;
			}
			if ($project && (int)$environment->project_id !== (int)$project->id) {
				$this->v2Error('forbidden', 'Environment does not belong to project', 403);
				return;
			}
		}

		$node = null;
		if ($nodeUuid !== null) {
			$node = Node::findByUuid($nodeUuid);
			if (!$node) {
				$this->v2Error('not_found', 'Node not found', 404);
				return;
			}
		}

		$limit = CursorService::parseLimit($_GET['page']['limit'] ?? null);
		$cursor = CursorService::decodeId($_GET['page']['cursor'] ?? null);

		$db = App::db();
		$params = [(int)$team->id];
		$where = 'p.team_id = ?';

		if ($cursor) {
			$where .= ' AND a.id > ?';
			$params[] = $cursor;
		}
		if ($project) {
			$where .= ' AND p.id = ?';
			$params[] = (int)$project->id;
		}
		if ($environment) {
			$where .= ' AND e.id = ?';
			$params[] = (int)$environment->id;
		}
		if ($appUuid !== null) {
			$where .= ' AND a.uuid = ?';
			$params[] = $appUuid;
		}
		if ($node) {
			$where .= ' AND a.node_id = ?';
			$params[] = (int)$node->id;
		}

		$rows = $db->fetchAll(
			"SELECT
				a.id AS internal_id,
				a.uuid AS uuid,
				a.name AS name,
				a.status AS status,
				e.uuid AS environment_uuid,
				p.uuid AS project_uuid,
				t.uuid AS team_uuid,
				n.uuid AS node_uuid
			 FROM applications a
			 JOIN environments e ON e.id = a.environment_id
			 JOIN projects p ON p.id = e.project_id
			 JOIN teams t ON t.id = p.team_id
			 LEFT JOIN nodes n ON n.id = a.node_id
			 WHERE {$where}
			 ORDER BY a.id ASC
			 LIMIT {$limit}",
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
				'status' => (string)($r['status'] ?? ''),
				'team_id' => (string)($r['team_uuid'] ?? ''),
				'project_id' => (string)($r['project_uuid'] ?? ''),
				'environment_id' => (string)($r['environment_uuid'] ?? ''),
				'node_id' => $r['node_uuid'] ? (string)$r['node_uuid'] : null,
			];
		}, $rows);

		$this->ok([
			'data' => $data,
			'page' => ['next_cursor' => $nextCursor, 'limit' => $limit],
		]);
		} catch (\RuntimeException $e) {
			if ($e->getMessage() === 'constraint_mismatch') {
				// Error already emitted.
				return;
			}
			throw $e;
		}
	}

	public function show(string $application_id): void
	{
		$token = $this->apiToken();
		if (!$token) {
			$this->v2Error('unauthorized', 'Unauthorized', 401);
			return;
		}
		if (!ApiTokenService::scopeAllows($token->scopesList(), 'applications:read')) {
			$this->v2Error('forbidden', 'Token lacks scope: applications:read', 403);
			return;
		}

		$app = \Chap\Models\Application::findByUuid($application_id);
		if (!$app) {
			$this->v2Error('not_found', 'Application not found', 404);
			return;
		}

		$env = $app->environment();
		$project = $env?->project();
		$team = $project ? \Chap\Models\Team::find((int)$project->team_id) : null;
		if (!$env || !$project || !$team) {
			$this->v2Error('not_found', 'Application not found', 404);
			return;
		}

		if (!ApiTokenService::constraintsAllow($token->constraintsMap(), [
			'team_id' => (string)$team->uuid,
			'project_id' => (string)$project->uuid,
			'environment_id' => (string)$env->uuid,
			'application_id' => (string)$app->uuid,
		])) {
			$this->v2Error('forbidden', 'Token constraints forbid this application', 403);
			return;
		}

		$this->ok([
			'data' => [
				'id' => (string)$app->uuid,
				'uuid' => (string)$app->uuid,
				'name' => (string)$app->name,
				'status' => (string)$app->status,
				'team_id' => (string)$team->uuid,
				'project_id' => (string)$project->uuid,
				'environment_id' => (string)$env->uuid,
				'node_id' => $app->node()?->uuid ? (string)$app->node()?->uuid : null,
				'build_pack' => (string)($app->build_pack ?? ''),
				'git_repository' => $app->git_repository,
				'git_branch' => $app->git_branch,
				'template_slug' => $app->template_slug,
			],
		]);
	}

	/**
	 * POST /api/v2/environments/{environment_id}/applications
	 */
	public function store(string $environment_id): void
	{
		$token = $this->apiToken();
		if (!$token) {
			$this->v2Error('unauthorized', 'Unauthorized', 401);
			return;
		}
		if (!ApiTokenService::scopeAllows($token->scopesList(), 'applications:write')) {
			$this->v2Error('forbidden', 'Token lacks scope: applications:write', 403);
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
			if ($userId <= 0 || !TeamPermissionService::can((int)$team->id, $userId, 'applications', 'write')) {
				$this->v2Error('forbidden', 'Permission denied', 403);
				return;
			}
		}

		$data = $this->all();
		$name = trim((string)($data['name'] ?? ''));
		$nodeUuid = trim((string)($data['node_id'] ?? $data['node_uuid'] ?? ''));
		if ($name === '') {
			$this->v2Error('validation_error', 'Validation error', 422, ['field' => 'name']);
			return;
		}
		if ($nodeUuid === '') {
			$this->v2Error('validation_error', 'Validation error', 422, ['field' => 'node_id']);
			return;
		}
		$node = Node::findByUuid($nodeUuid);
		if (!$node) {
			$this->v2Error('validation_error', 'Validation error', 422, ['field' => 'node_id']);
			return;
		}

		$envVars = null;
		if (array_key_exists('environment_variables', $data)) {
			if (is_array($data['environment_variables'])) {
				$envVars = json_encode($data['environment_variables']);
			} elseif (is_string($data['environment_variables'])) {
				$envVars = $data['environment_variables'];
			}
		}

		$app = \Chap\Models\Application::create([
			'environment_id' => (int)$env->id,
			'node_id' => (int)$node->id,
			'name' => $name,
			'description' => $data['description'] ?? null,
			'git_repository' => $data['git_repository'] ?? null,
			'git_branch' => (string)($data['git_branch'] ?? 'main'),
			'build_pack' => (string)($data['build_pack'] ?? 'docker-compose'),
			'dockerfile_path' => (string)($data['dockerfile_path'] ?? 'Dockerfile'),
			'docker_compose_path' => (string)($data['docker_compose_path'] ?? 'docker-compose.yml'),
			'build_context' => (string)($data['build_context'] ?? '.'),
			'environment_variables' => $envVars,
			'status' => 'stopped',
		]);

		$this->ok([
			'data' => [
				'application_id' => (string)$app->uuid,
				'application' => [
					'id' => (string)$app->uuid,
					'uuid' => (string)$app->uuid,
					'name' => (string)$app->name,
					'team_id' => (string)$team->uuid,
					'project_id' => (string)$project->uuid,
					'environment_id' => (string)$env->uuid,
					'node_id' => (string)$node->uuid,
				],
			],
		], 201);
	}

	/**
	 * PATCH /api/v2/applications/{application_id}
	 */
	public function update(string $application_id): void
	{
		$token = $this->apiToken();
		if (!$token) {
			$this->v2Error('unauthorized', 'Unauthorized', 401);
			return;
		}
		if (!ApiTokenService::scopeAllows($token->scopesList(), 'applications:write')) {
			$this->v2Error('forbidden', 'Token lacks scope: applications:write', 403);
			return;
		}

		$app = \Chap\Models\Application::findByUuid($application_id);
		if (!$app) {
			$this->v2Error('not_found', 'Application not found', 404);
			return;
		}

		$data = $this->all();
		$update = [];
		foreach (['name','description','git_repository','git_branch','build_pack','dockerfile_path','docker_compose_path','build_context','domains'] as $k) {
			if (array_key_exists($k, $data)) {
				$update[$k] = $data[$k];
			}
		}
		if (array_key_exists('environment_variables', $data)) {
			if (is_array($data['environment_variables'])) {
				$update['environment_variables'] = json_encode($data['environment_variables']);
			} elseif (is_string($data['environment_variables'])) {
				$update['environment_variables'] = $data['environment_variables'];
			}
		}
		if (array_key_exists('node_id', $data) || array_key_exists('node_uuid', $data)) {
			$nodeUuid = trim((string)($data['node_id'] ?? $data['node_uuid'] ?? ''));
			$node = $nodeUuid !== '' ? Node::findByUuid($nodeUuid) : null;
			if (!$node) {
				$this->v2Error('validation_error', 'Validation error', 422, ['field' => 'node_id']);
				return;
			}
			$update['node_id'] = (int)$node->id;
		}

		if (empty($update)) {
			$this->ok(['data' => ['updated' => false]]);
			return;
		}

		$app->update($update);
		$this->ok(['data' => ['updated' => true]]);
	}

	private function resolveFilterAndConstraint(mixed $filterValue, mixed $constraintValue): ?string
	{
		$f = trim((string)($filterValue ?? ''));
		$c = trim((string)($constraintValue ?? ''));

		if ($f !== '' && $c !== '' && $f !== $c) {
			$this->v2Error('forbidden', 'Token constraints forbid this request', 403);
			throw new \RuntimeException('constraint_mismatch');
		}

		$final = $f !== '' ? $f : ($c !== '' ? $c : '');
		return $final !== '' ? $final : null;
	}
}
