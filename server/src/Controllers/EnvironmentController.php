<?php

namespace Chap\Controllers;

use Chap\Models\Environment;
use Chap\Models\Project;
use Chap\Models\Node;
use Chap\Services\NodeAccess;
use Chap\Services\ApplicationCleanupService;
use Chap\Services\ResourceAllocator;
use Chap\Services\ResourceHierarchy;

/**
 * Environment Controller
 */
class EnvironmentController extends BaseController
{
    /**
     * Create environment for project
     */
    public function store(string $projectUuid): void
    {
        $this->currentTeam();
        $project = Project::findByUuid($projectUuid);

        if (!$project || !$this->canAccessTeamId($project->team_id)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Project not found'], 404);
            } else {
                flash('error', 'Project not found');
                $this->redirect('/projects');
            }
        }

        $data = $this->all();

        $this->requireTeamPermission('environments', 'write', (int)$project->team_id);

        if (empty($data['name'])) {
            if ($this->isApiRequest()) {
                $this->json(['errors' => ['name' => 'Name is required']], 422);
            } else {
                flash('error', 'Environment name is required');
                $this->redirect('/projects/' . $projectUuid);
            }
        }

        $environment = Environment::create([
            'project_id' => $project->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        if ($this->isApiRequest()) {
            $this->json(['environment' => $environment->toArray()], 201);
        } else {
            flash('success', 'Environment created');
            $this->redirect('/environments/' . $environment->uuid);
        }
    }

    /**
     * Show environment
     */
    public function show(string $uuid): void
    {
        $this->currentTeam();
        $environment = Environment::findByUuid($uuid);

        if (!$environment) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $project = $environment->project();
        if (!$project || !$this->canAccessTeamId($project->team_id)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $this->requireTeamPermission('environments', 'read', (int)$project->team_id);

        $applications = $environment->applications();

        if ($this->isApiRequest()) {
            $this->json([
                'environment' => $environment->toArray(),
                'applications' => array_map(fn($a) => $a->toArray(), $applications),
            ]);
        } else {
            $this->view('environments/show', [
                'title' => $environment->name,
                'environment' => $environment,
                'project' => $project,
                'applications' => $applications,
            ]);
        }
    }

    /**
     * Show edit environment form
     */
    public function edit(string $uuid): void
    {
        $this->currentTeam();
        $environment = Environment::findByUuid($uuid);

        if (!$environment) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $project = $environment->project();
        if (!$project || !$this->canAccessTeamId($project->team_id)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $this->requireTeamPermission('environments', 'write', (int)$project->team_id);

        if ($this->isApiRequest()) {
            $this->json([
                'environment' => $environment->toArray(),
                'project' => $project->toArray(),
            ]);
        } else {
            $availableNodes = [];
            $parentEffective = null;
            if ($this->user) {
                $team = $project->team();
                if ($team) {
                    $allowedNodeIds = NodeAccess::allowedNodeIds($this->user, $team, $project);
                    $teamNodes = Node::forTeam((int)$team->id);
                    $availableNodes = array_values(array_filter($teamNodes, fn($n) => in_array((int)$n->id, $allowedNodeIds, true)));
                }
                $parentEffective = ResourceHierarchy::effectiveProjectLimits($project);
            }

            $this->view('environments/edit', [
                'title' => 'Edit Environment',
                'environment' => $environment,
                'project' => $project,
                'availableNodes' => $availableNodes,
                'parentEffective' => $parentEffective,
            ]);
        }
    }

    /**
     * Update environment
     */
    public function update(string $uuid): void
    {
        $this->currentTeam();
        $environment = Environment::findByUuid($uuid);

        if (!$environment) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $project = $environment->project();
        if (!$project || !$this->canAccessTeamId($project->team_id)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $this->requireTeamPermission('environments', 'write', (int)$project->team_id);

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/environments/' . $uuid . '/edit');
        }

        $data = $this->all();

        $cpuMillicoresLimit = ResourceHierarchy::parseCpuMillicores((string)($data['cpu_limit_cores'] ?? '-1'));
        $ramMbLimit = ResourceHierarchy::parseMb((string)($data['ram_mb_limit'] ?? '-1'));
        $storageMbLimit = ResourceHierarchy::parseMb((string)($data['storage_mb_limit'] ?? '-1'));
        $portLimit = ResourceHierarchy::parseIntOrAuto((string)($data['port_limit'] ?? '-1'));
        $bandwidthLimit = ResourceHierarchy::parseIntOrAuto((string)($data['bandwidth_mbps_limit'] ?? '-1'));
        $pidsLimit = ResourceHierarchy::parseIntOrAuto((string)($data['pids_limit'] ?? '-1'));

        $restrictNodes = !empty($data['restrict_nodes']);
        $nodeIds = $data['allowed_node_ids'] ?? [];
        if (!is_array($nodeIds)) {
            $nodeIds = [];
        }
        $nodeIds = array_values(array_unique(array_map('intval', $nodeIds)));

        $errors = [];
        $parent = ResourceHierarchy::effectiveProjectLimits($project);
        $siblings = Environment::forProject((int)$project->id);

        $maps = [
            'cpu_millicores' => [],
            'ram_mb' => [],
            'storage_mb' => [],
            'ports' => [],
            'bandwidth_mbps' => [],
            'pids' => [],
        ];

        foreach ($siblings as $e) {
            $isCurrent = (int)$e->id === (int)$environment->id;
            $maps['cpu_millicores'][(int)$e->id] = $isCurrent ? $cpuMillicoresLimit : (int)$e->cpu_millicores_limit;
            $maps['ram_mb'][(int)$e->id] = $isCurrent ? $ramMbLimit : (int)$e->ram_mb_limit;
            $maps['storage_mb'][(int)$e->id] = $isCurrent ? $storageMbLimit : (int)$e->storage_mb_limit;
            $maps['ports'][(int)$e->id] = $isCurrent ? $portLimit : (int)$e->port_limit;
            $maps['bandwidth_mbps'][(int)$e->id] = $isCurrent ? $bandwidthLimit : (int)$e->bandwidth_mbps_limit;
            $maps['pids'][(int)$e->id] = $isCurrent ? $pidsLimit : (int)$e->pids_limit;
        }

        if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['cpu_millicores'], $maps['cpu_millicores'])) {
            $errors['cpu_limit_cores'] = 'CPU allocations exceed the project\'s remaining limit';
        }
        if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['ram_mb'], $maps['ram_mb'])) {
            $errors['ram_mb_limit'] = 'RAM allocations exceed the project\'s remaining limit';
        }
        if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['storage_mb'], $maps['storage_mb'])) {
            $errors['storage_mb_limit'] = 'Storage allocations exceed the project\'s remaining limit';
        }
        if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['ports'], $maps['ports'])) {
            $errors['port_limit'] = 'Port allocations exceed the project\'s remaining limit';
        }
        if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['bandwidth_mbps'], $maps['bandwidth_mbps'])) {
            $errors['bandwidth_mbps_limit'] = 'Bandwidth allocations exceed the project\'s remaining limit';
        }
        if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['pids'], $maps['pids'])) {
            $errors['pids_limit'] = 'PID allocations exceed the project\'s remaining limit';
        }

        if ($restrictNodes && $this->user) {
            $team = $project->team();
            if ($team) {
                $allowedForEditor = NodeAccess::allowedNodeIds($this->user, $team, $project);
                $bad = array_diff($nodeIds, $allowedForEditor);
                if (!empty($bad)) {
                    $errors['allowed_node_ids'] = 'You cannot grant access to nodes you do not have access to';
                }
            }
        }

        if (!empty($errors)) {
            $_SESSION['_errors'] = $errors;
            $_SESSION['_old_input'] = $data;
            $this->redirect('/environments/' . $uuid . '/edit');
        }

        $environment->update([
            'name' => $data['name'] ?? $environment->name,
            'description' => $data['description'] ?? $environment->description,
            'cpu_millicores_limit' => $cpuMillicoresLimit,
            'ram_mb_limit' => $ramMbLimit,
            'storage_mb_limit' => $storageMbLimit,
            'port_limit' => $portLimit,
            'bandwidth_mbps_limit' => $bandwidthLimit,
            'pids_limit' => $pidsLimit,
            'allowed_node_ids' => $restrictNodes ? NodeAccess::encodeNodeIds($nodeIds) : null,
        ]);

        if ($this->isApiRequest()) {
            $this->json(['environment' => $environment->toArray()]);
        } else {
            flash('success', 'Environment updated');
            $this->redirect('/environments/' . $uuid);
        }
    }

    /**
     * Delete environment
     */
    public function destroy(string $uuid): void
    {
        $this->currentTeam();
        $environment = Environment::findByUuid($uuid);

        if (!$environment) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $project = $environment->project();
        if (!$project || !$this->canAccessTeamId($project->team_id)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $this->requireTeamPermission('environments', 'write', (int)$project->team_id);

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/environments/' . $uuid);
        }

        $projectUuid = $project->uuid;
            // Ensure applications are stopped and removed on their nodes before
            // we delete the environment (DB cascades alone won't notify nodes).
            ApplicationCleanupService::deleteAllForEnvironment($environment);
        $environment->delete();

        if ($this->isApiRequest()) {
            $this->json(['message' => 'Environment deleted']);
        } else {
            flash('success', 'Environment deleted');
            $this->redirect('/projects/' . $projectUuid);
        }
    }

    /**
     * Show create environment form
     */
    public function create(string $projectUuid): void
    {
        $this->currentTeam();
        $project = Project::findByUuid($projectUuid);

        if (!$project || !$this->canAccessTeamId($project->team_id)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Project not found'], 404);
            } else {
                flash('error', 'Project not found');
                $this->redirect('/projects');
            }
            return;
        }

        $this->requireTeamPermission('environments', 'write', (int)$project->team_id);

        $this->view('environments/create', [
            'title' => 'Create Environment',
            'project' => $project,
        ]);
    }
}
