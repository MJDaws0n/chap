<?php

namespace Chap\Controllers;

use Chap\Models\Project;
use Chap\Models\ActivityLog;
use Chap\Models\Node;
use Chap\Services\ApplicationCleanupService;
use Chap\Services\NodeAccess;
use Chap\Services\ResourceAllocator;
use Chap\Services\ResourceHierarchy;
use Chap\Services\LimitCascadeService;

/**
 * Project Controller
 */
class ProjectController extends BaseController
{
    /**
     * List projects
     */
    public function index(): void
    {
        if (admin_view_all()) {
            $projects = Project::all();
        } else {
            $team = $this->currentTeam();
            $this->requireTeamPermission('projects', 'read', (int)$team->id);
            $projects = Project::forTeam($team->id);
        }

        if ($this->isApiRequest()) {
            $this->json([
                'projects' => array_map(fn($p) => $p->toArray(), $projects)
            ]);
        } else {
            $this->view('projects/index', [
                'title' => 'Projects',
                'projects' => $projects,
            ]);
        }
    }

    /**
     * Show create form
     */
    public function create(): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('projects', 'write', (int)$team->id);
        $this->view('projects/create', [
            'title' => 'New Project'
        ]);
    }

    /**
     * Store new project
     */
    public function store(): void
    {
        $team = $this->currentTeam();
        $this->requireTeamPermission('projects', 'write', (int)$team->id);

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/projects/create');
        }

        $data = $this->all();

        // Validate
        if (empty($data['name'])) {
            if ($this->isApiRequest()) {
                $this->json(['errors' => ['name' => 'Name is required']], 422);
            } else {
                flash('error', 'Project name is required');
                $this->redirect('/projects/create');
            }
        }

        $project = Project::create([
            'team_id' => $team->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        // Create default environment
        $project->createDefaultEnvironment();

        ActivityLog::log('project.created', 'Project', $project->id, ['name' => $project->name]);

        if ($this->isApiRequest()) {
            $this->json(['project' => $project->toArray()], 201);
        } else {
            flash('success', 'Project created');
            $this->redirect('/projects/' . $project->uuid);
        }
    }

    /**
     * Show project
     */
    public function show(string $uuid): void
    {
        $project = Project::findByUuid($uuid);

        if (!$project || !$this->canAccessTeamId($project->team_id)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Project not found'], 404);
            } else {
                flash('error', 'Project not found');
                $this->redirect('/projects');
            }
        }

        $environments = $project->environments();

        $this->requireTeamPermission('projects', 'read', (int)$project->team_id);

        if ($this->isApiRequest()) {
            $this->json([
                'project' => $project->toArray(),
                'environments' => array_map(fn($e) => $e->toArray(), $environments),
            ]);
        } else {
            $this->view('projects/show', [
                'title' => $project->name,
                'project' => $project,
                'environments' => $environments,
            ]);
        }
    }

    /**
     * Show edit form
     */
    public function edit(string $uuid): void
    {
        $project = Project::findByUuid($uuid);
    $this->requireTeamPermission('projects', 'write', (int)$project->team_id);

        if (!$project || !$this->canAccessTeamId($project->team_id)) {
            flash('error', 'Project not found');
            $this->redirect('/projects');
        }

        $team = $project->team();
        $availableNodes = [];
        $parentEffective = null;
        if ($team && $this->user) {
            $allowedNodeIds = NodeAccess::allowedNodeIds($this->user, $team);
            $teamNodes = Node::forTeam((int)$team->id);
            $availableNodes = array_values(array_filter($teamNodes, fn($n) => in_array((int)$n->id, $allowedNodeIds, true)));
            $parentEffective = ResourceHierarchy::effectiveTeamLimits($team);
        }

        $this->view('projects/edit', [
            'title' => 'Edit Project',
            'project' => $project,
            'availableNodes' => $availableNodes,
            'parentEffective' => $parentEffective,
        ]);
    }

    /**
     * Update project
     */
    public function update(string $uuid): void
    {
        $project = Project::findByUuid($uuid);

        if (!$project || !$this->canAccessTeamId($project->team_id)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Project not found'], 404);
            } else {
                flash('error', 'Project not found');
                $this->redirect('/projects');
            }
            return;
        }

        $this->requireTeamPermission('projects', 'write', (int)$project->team_id);

        if (!$this->isApiRequest() && !verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid request');
            $this->redirect('/projects/' . $uuid . '/edit');
        }

        $data = $this->all();

        $oldLimits = ResourceHierarchy::projectConfigured($project);

        $cpuMillicoresLimit = ResourceHierarchy::parseCpuMillicores((string)($data['cpu_limit_cores'] ?? '-1'));
        $ramMbLimit = ResourceHierarchy::parseMb((string)($data['ram_mb_limit'] ?? '-1'));
        $storageMbLimit = ResourceHierarchy::parseMb((string)($data['storage_mb_limit'] ?? '-1'));
        $portLimit = ResourceHierarchy::parseIntOrAuto((string)($data['port_limit'] ?? '-1'));
        $bandwidthLimit = ResourceHierarchy::parseIntOrAuto((string)($data['bandwidth_mbps_limit'] ?? '-1'));
        $pidsLimit = ResourceHierarchy::parseIntOrAuto((string)($data['pids_limit'] ?? '-1'));

        $newLimits = [
            'cpu_millicores' => $cpuMillicoresLimit,
            'ram_mb' => $ramMbLimit,
            'storage_mb' => $storageMbLimit,
            'ports' => $portLimit,
            'bandwidth_mbps' => $bandwidthLimit,
            'pids' => $pidsLimit,
        ];

        $restrictNodes = !empty($data['restrict_nodes']);
        $nodeIds = $data['allowed_node_ids'] ?? [];
        if (!is_array($nodeIds)) {
            $nodeIds = [];
        }
        $nodeIds = array_values(array_unique(array_map('intval', $nodeIds)));

        $errors = [];
        $team = $project->team();
        if ($team) {
            $parent = ResourceHierarchy::effectiveTeamLimits($team);
            $siblings = Project::forTeam((int)$team->id);

            $maps = [
                'cpu_millicores' => [],
                'ram_mb' => [],
                'storage_mb' => [],
                'ports' => [],
                'bandwidth_mbps' => [],
                'pids' => [],
            ];
            foreach ($siblings as $p) {
                $isCurrent = (int)$p->id === (int)$project->id;
                $maps['cpu_millicores'][(int)$p->id] = $isCurrent ? $cpuMillicoresLimit : (int)$p->cpu_millicores_limit;
                $maps['ram_mb'][(int)$p->id] = $isCurrent ? $ramMbLimit : (int)$p->ram_mb_limit;
                $maps['storage_mb'][(int)$p->id] = $isCurrent ? $storageMbLimit : (int)$p->storage_mb_limit;
                $maps['ports'][(int)$p->id] = $isCurrent ? $portLimit : (int)$p->port_limit;
                $maps['bandwidth_mbps'][(int)$p->id] = $isCurrent ? $bandwidthLimit : (int)$p->bandwidth_mbps_limit;
                $maps['pids'][(int)$p->id] = $isCurrent ? $pidsLimit : (int)$p->pids_limit;
            }

            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['cpu_millicores'], $maps['cpu_millicores'])) {
                $errors['cpu_limit_cores'] = 'CPU allocations exceed the team\'s remaining limit';
            }
            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['ram_mb'], $maps['ram_mb'])) {
                $errors['ram_mb_limit'] = 'RAM allocations exceed the team\'s remaining limit';
            }
            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['storage_mb'], $maps['storage_mb'])) {
                $errors['storage_mb_limit'] = 'Storage allocations exceed the team\'s remaining limit';
            }
            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['ports'], $maps['ports'])) {
                $errors['port_limit'] = 'Port allocations exceed the team\'s remaining limit';
            }
            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['bandwidth_mbps'], $maps['bandwidth_mbps'])) {
                $errors['bandwidth_mbps_limit'] = 'Bandwidth allocations exceed the team\'s remaining limit';
            }
            if (!ResourceAllocator::validateDoesNotOverallocate((int)$parent['pids'], $maps['pids'])) {
                $errors['pids_limit'] = 'PID allocations exceed the team\'s remaining limit';
            }

            if ($restrictNodes && $this->user) {
                $allowedForEditor = NodeAccess::allowedNodeIds($this->user, $team);
                $bad = array_diff($nodeIds, $allowedForEditor);
                if (!empty($bad)) {
                    $errors['allowed_node_ids'] = 'You cannot grant access to nodes you do not have access to';
                }
            }
        }

        if (!empty($errors)) {
            $_SESSION['_errors'] = $errors;
            $_SESSION['_old_input'] = $data;
            $this->redirect('/projects/' . $uuid . '/edit');
        }

        $project->update([
            'name' => $data['name'] ?? $project->name,
            'description' => $data['description'] ?? $project->description,
            'cpu_millicores_limit' => $cpuMillicoresLimit,
            'ram_mb_limit' => $ramMbLimit,
            'storage_mb_limit' => $storageMbLimit,
            'port_limit' => $portLimit,
            'bandwidth_mbps_limit' => $bandwidthLimit,
            'pids_limit' => $pidsLimit,
            'allowed_node_ids' => $restrictNodes ? NodeAccess::encodeNodeIds($nodeIds) : null,
        ]);

        if (LimitCascadeService::anyReduction($oldLimits, $newLimits)) {
            $enforced = LimitCascadeService::enforceUnderProject($project);
            $apps = LimitCascadeService::applicationIdsForProject((int)$project->id);
            $redeploy = LimitCascadeService::redeployApplications($apps, $this->user, 'limits');

            $details = [];
            if (($enforced['changed_fields'] ?? 0) > 0) {
                $details[] = 'auto-adjusted child limits';
            }
            $details[] = 'redeploy started: ' . ($redeploy['started'] ?? 0);
            flash('info', 'Limits reduced: ' . implode(', ', $details));
        }

        if ($this->isApiRequest()) {
            $this->json(['project' => $project->toArray()]);
        } else {
            flash('success', 'Project updated');
            $this->redirect('/projects/' . $uuid);
        }
    }

    /**
     * Delete project
     */
    public function destroy(string $uuid): void
    {
        $project = Project::findByUuid($uuid);

        if (!$project || !$this->canAccessTeamId($project->team_id)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Project not found'], 404);
            } else {
                flash('error', 'Project not found');
                $this->redirect('/projects');
            }
        }

        $this->requireTeamPermission('projects', 'write', (int)$project->team_id);

        // Ensure applications are stopped and removed on their nodes before
        // we delete the project (DB cascades alone won't notify nodes).
        ApplicationCleanupService::deleteAllForProject($project);

        $projectName = $project->name;
        $project->delete();

        ActivityLog::log('project.deleted', 'Project', null, ['name' => $projectName]);

        if ($this->isApiRequest()) {
            $this->json(['message' => 'Project deleted']);
        } else {
            flash('success', 'Project deleted');
            $this->redirect('/projects');
        }
    }
}
