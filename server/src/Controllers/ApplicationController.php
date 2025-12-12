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
