<?php

namespace Chap\Controllers;

use Chap\Models\Application;
use Chap\Models\Environment;
use Chap\Models\Node;
use Chap\Models\Deployment;
use Chap\Models\ActivityLog;
use Chap\Services\DeploymentService;

/**
 * Application Controller
 */
class ApplicationController extends BaseController
{
    /**
     * Create application form
     */
    public function create(string $envUuid): void
    {
        $team = $this->currentTeam();
        $environment = Environment::findByUuid($envUuid);

        if (!$environment) {
            flash('error', 'Environment not found');
            $this->redirect('/projects');
        }

        $project = $environment->project();
        if (!$project || $project->team_id !== $team->id) {
            flash('error', 'Environment not found');
            $this->redirect('/projects');
        }

        $nodes = Node::onlineForTeam($team->id);

        $this->view('applications/create', [
            'title' => 'New Application',
            'environment' => $environment,
            'project' => $project,
            'nodes' => $nodes,
        ]);
    }

    /**
     * Store new application
     */
    public function store(string $envUuid): void
    {
        $team = $this->currentTeam();
        $environment = Environment::findByUuid($envUuid);

        if (!$environment) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $project = $environment->project();
        if (!$project || $project->team_id !== $team->id) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
        }

        $data = $this->all();

        // Validate
        $errors = [];
        if (empty($data['name'])) {
            $errors['name'] = 'Name is required';
        }
        if (empty($data['node_uuid'])) {
            $errors['node_uuid'] = 'Node selection is required';
        } else {
            $node = Node::findByUuid($data['node_uuid']);
            if (!$node || $node->team_id !== $team->id) {
                $errors['node_uuid'] = 'Invalid node';
            }
        }

        if (!empty($errors)) {
            if ($this->isApiRequest()) {
                $this->json(['errors' => $errors], 422);
            } else {
                $_SESSION['_errors'] = $errors;
                $_SESSION['_old_input'] = $data;
                $this->redirect('/environments/' . $envUuid . '/applications/create');
            }
        }

        // Parse environment variables
        $envVars = [];
        if (!empty($data['environment_variables'])) {
            $lines = explode("\n", $data['environment_variables']);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line && str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $envVars[trim($key)] = trim($value);
                }
            }
        }

        $application = Application::create([
            'environment_id' => $environment->id,
            'node_id' => isset($node) ? $node->id : null,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'git_repository' => $data['git_repository'] ?? null,
            'git_branch' => $data['git_branch'] ?? 'main',
            'build_pack' => $data['build_pack'] ?? 'dockerfile',
            'dockerfile_path' => $data['dockerfile_path'] ?? 'Dockerfile',
            'build_context' => $data['build_context'] ?? '.',
            'port' => !empty($data['port']) ? (int)$data['port'] : null,
            'domains' => $data['domains'] ?? null,
            'environment_variables' => !empty($envVars) ? json_encode($envVars) : null,
            'memory_limit' => $data['memory_limit'] ?? '512m',
            'cpu_limit' => $data['cpu_limit'] ?? '1',
            'health_check_enabled' => !empty($data['health_check_enabled']) ? 1 : 0,
            'health_check_path' => $data['health_check_path'] ?? '/',
        ]);

        ActivityLog::log('application.created', 'Application', $application->id, ['name' => $application->name]);

        if ($this->isApiRequest()) {
            $this->json(['application' => $application->toArray()], 201);
        } else {
            flash('success', 'Application created');
            $this->redirect('/applications/' . $application->uuid);
        }
    }

    /**
     * Show application
     */
    public function show(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
        }

        $deployments = Deployment::forApplication($application->id, 10);
        $nodes = Node::onlineForTeam($team->id);

        if ($this->isApiRequest()) {
            $this->json([
                'application' => $application->toArray(),
                'deployments' => array_map(fn($d) => $d->toArray(), $deployments),
            ]);
        } else {
            $this->view('applications/show', [
                'title' => $application->name,
                'application' => $application,
                'environment' => $application->environment(),
                'project' => $application->environment()->project(),
                'deployments' => $deployments,
                'nodes' => $nodes,
            ]);
        }
    }

    /**
     * Update application
     */
    public function update(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
        }

        $data = $this->all();

        // Handle node update
        $nodeId = $application->node_id;
        if (isset($data['node_uuid'])) {
            if (empty($data['node_uuid'])) {
                $nodeId = null;
            } else {
                $node = Node::findByUuid($data['node_uuid']);
                if ($node && $node->team_id === $team->id) {
                    $nodeId = $node->id;
                }
            }
        }

        // Parse environment variables
        $envVars = $application->getEnvironmentVariables();
        if (isset($data['environment_variables'])) {
            $envVars = [];
            $lines = explode("\n", $data['environment_variables']);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line && str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $envVars[trim($key)] = trim($value);
                }
            }
        }

        $application->update([
            'node_id' => $nodeId,
            'name' => $data['name'] ?? $application->name,
            'description' => $data['description'] ?? $application->description,
            'git_repository' => $data['git_repository'] ?? $application->git_repository,
            'git_branch' => $data['git_branch'] ?? $application->git_branch,
            'build_pack' => $data['build_pack'] ?? $application->build_pack,
            'dockerfile_path' => $data['dockerfile_path'] ?? $application->dockerfile_path,
            'build_context' => $data['build_context'] ?? $application->build_context,
            'port' => isset($data['port']) && $data['port'] !== '' ? (int)$data['port'] : $application->port,
            'domains' => $data['domains'] ?? $application->domains,
            'environment_variables' => !empty($envVars) ? json_encode($envVars) : null,
            'memory_limit' => $data['memory_limit'] ?? $application->memory_limit,
            'cpu_limit' => $data['cpu_limit'] ?? $application->cpu_limit,
            'health_check_enabled' => !empty($data['health_check_enabled']) ? 1 : 0,
            'health_check_path' => $data['health_check_path'] ?? $application->health_check_path,
        ]);

        if ($this->isApiRequest()) {
            $this->json(['application' => $application->toArray()]);
        } else {
            flash('success', 'Application updated');
            $this->redirect('/applications/' . $uuid);
        }
    }

    /**
     * Delete application
     */
    public function destroy(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
        }

        $envUuid = $application->environment()->uuid;
        $appName = $application->name;
        $application->delete();

        ActivityLog::log('application.deleted', 'Application', null, ['name' => $appName]);

        if ($this->isApiRequest()) {
            $this->json(['message' => 'Application deleted']);
        } else {
            flash('success', 'Application deleted');
            $this->redirect('/environments/' . $envUuid);
        }
    }

    /**
     * Deploy application
     */
    public function deploy(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
        }

        if (!$application->node_id) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'No node assigned to application'], 400);
            } else {
                flash('error', 'Please assign a node before deploying');
                $this->redirect('/applications/' . $uuid);
            }
        }

        $node = $application->node();
        if (!$node || !$node->isOnline()) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Node is offline'], 400);
            } else {
                flash('error', 'The assigned node is offline');
                $this->redirect('/applications/' . $uuid);
            }
        }

        // Create deployment
        $deployment = DeploymentService::create($application);

        ActivityLog::log('deployment.started', 'Deployment', $deployment->id, [
            'application' => $application->name
        ]);

        if ($this->isApiRequest()) {
            $this->json(['deployment' => $deployment->toArray()], 201);
        } else {
            flash('success', 'Deployment started');
            $this->redirect('/deployments/' . $deployment->uuid);
        }
    }

    /**
     * Stop application
     */
    public function stop(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
        }

        // Send stop command to node via WebSocket
        DeploymentService::stop($application);

        $application->update(['status' => 'stopped']);

        if ($this->isApiRequest()) {
            $this->json(['message' => 'Application stopped']);
        } else {
            flash('success', 'Application stopped');
            $this->redirect('/applications/' . $uuid);
        }
    }

    /**
     * Restart application
     */
    public function restart(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
        }

        DeploymentService::restart($application);

        if ($this->isApiRequest()) {
            $this->json(['message' => 'Application restarted']);
        } else {
            flash('success', 'Application restarted');
            $this->redirect('/applications/' . $uuid);
        }
    }

    /**
     * Live logs page for application
     */
    public function logs(string $uuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($uuid);

        if (!$this->canAccessApplication($application, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
            return;
        }

        // If API request (AJAX from frontend)
        if ($this->isApiRequest()) {
            $containerId = $this->input('container_id');
            $tail = (int) ($this->input('tail') ?: 100);
            
            // Get containers and logs directly from Docker on the node
            $result = $this->getContainersAndLogsFromNode($application, $containerId, $tail);
            
            $this->json($result);
            return;
        }

        // Initial page load - just render the view, JS will fetch containers
        $this->view('applications/logs', [
            'title' => 'Live Logs - ' . $application->name,
            'application' => $application,
            'environment' => $application->environment(),
            'project' => $application->environment()->project(),
            'containers' => [], // Will be fetched via AJAX
        ]);
    }
    
    /**
     * Get containers and logs from the node agent
     */
    private function getContainersAndLogsFromNode(Application $application, ?string $containerId = null, int $tail = 100): array
    {
        $nodeId = $application->node_id;
        if (!$nodeId) {
            // Try to get node from latest deployment
            $db = \Chap\App::db();
            $deployment = $db->fetch(
                "SELECT node_id FROM deployments WHERE application_id = ? AND node_id IS NOT NULL ORDER BY id DESC LIMIT 1",
                [$application->id]
            );
            $nodeId = $deployment['node_id'] ?? null;
        }
        
        if (!$nodeId) {
            return ['containers' => [], 'logs' => [], 'error' => 'No node assigned'];
        }
        
        // Create a task to get containers/logs from node
        $db = \Chap\App::db();
        $taskId = uuid();
        
        $db->insert('deployment_tasks', [
            'node_id' => $nodeId,
            'task_type' => 'container:logs',
            'task_data' => json_encode([
                'type' => 'container:logs',
                'payload' => [
                    'task_id' => $taskId,
                    'application_uuid' => $application->uuid,
                    'container_id' => $containerId,
                    'tail' => $tail
                ]
            ]),
            'status' => 'pending'
        ]);
        // Wait for response (poll the cache file the WebSocket handler creates)
        // Use per-request cache filename to avoid races when multiple requests occur for same application
        $cacheFile = "/tmp/logs_response_{$application->uuid}_{$taskId}.json";
        $startTime = time();
        $timeout = 8; // 8 second timeout

        // Delete old cache first
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }

        // If node appears offline, return early to avoid waiting for timeout
        $node = \Chap\Models\Node::find($nodeId);
        if ($node && method_exists($node, 'isOnline') && !$node->isOnline()) {
            return ['containers' => [], 'logs' => [], 'error' => 'Node is offline'];
        }

        while ((time() - $startTime) < $timeout) {
            usleep(100000); // 100ms

            if (file_exists($cacheFile)) {
                $data = json_decode(file_get_contents($cacheFile), true);
                if ($data && isset($data['timestamp']) && $data['timestamp'] > $startTime) {
                    return [
                        'containers' => $data['containers'] ?? [],
                        'logs' => $data['logs'] ?? []
                    ];
                }
            }
        }

        // Timeout - return empty with error
        return ['containers' => [], 'logs' => [], 'error' => 'Timeout waiting for node response'];
    }

    /**
     * Get containers for an application
     */
    private function getContainersForApplication(Application $application): array
    {
        $db = \Chap\App::db();
        
        // First try to get containers from containers table
        $results = $db->fetchAll(
            "SELECT DISTINCT c.* FROM containers c 
             LEFT JOIN deployments d ON c.deployment_id = d.id 
             WHERE c.application_id = ? OR d.application_id = ? 
             ORDER BY c.created_at DESC",
            [$application->id, $application->id]
        );
        
        if (!empty($results)) {
            return array_map(fn($data) => \Chap\Models\Container::fromArray($data), $results);
        }
        
        // Fallback: Create virtual container entries from deployments that have container_id
        $deployments = $db->fetchAll(
            "SELECT d.id, d.uuid, d.container_id, d.node_id, d.image_tag, d.status 
             FROM deployments d 
             WHERE d.application_id = ? AND d.container_id IS NOT NULL AND d.container_id != ''
             ORDER BY d.created_at DESC 
             LIMIT 5",
            [$application->id]
        );
        
        $containers = [];
        foreach ($deployments as $dep) {
            // Create a virtual container object
            $container = new \stdClass();
            $container->id = $dep['id']; // Use deployment ID as container ID for lookups
            $container->container_id = $dep['container_id'];
            $container->name = $application->name . '-' . substr($dep['container_id'], 0, 12);
            $container->status = ($dep['status'] === 'running') ? 'running' : 'exited';
            $container->image = $dep['image_tag'] ?? $application->docker_image ?? 'unknown';
            $container->node_id = $dep['node_id'];
            $containers[] = $container;
        }
        
        return $containers;
    }

    /**
     * Get logs for a container (placeholder - will be enhanced with real log storage)
     */
    private function getContainerLogs(int $containerId, int $tail = 100): array
    {
        $db = \Chap\App::db();
        
        // Check if container_logs table exists and fetch from it
        try {
            $logs = $db->fetchAll(
                "SELECT * FROM container_logs 
                 WHERE container_id = ? 
                 ORDER BY created_at DESC 
                 LIMIT ?",
                [$containerId, $tail]
            );
            
            // Reverse to get chronological order
            $logs = array_reverse($logs);
            
            return array_map(fn($log) => [
                'timestamp' => $log['created_at'] ?? '',
                'message' => $log['line'] ?? '',
                'level' => $log['level'] ?? 'info',
            ], $logs);
        } catch (\Exception $e) {
            // Table doesn't exist yet or other error
            return [];
        }
    }

    /**
     * Check if user can access application
     */
    private function canAccessApplication(?Application $application, $team): bool
    {
        if (!$application) {
            return false;
        }

        $environment = $application->environment();
        if (!$environment) {
            return false;
        }

        $project = $environment->project();
        if (!$project || $project->team_id !== $team->id) {
            return false;
        }

        return true;
    }
}
