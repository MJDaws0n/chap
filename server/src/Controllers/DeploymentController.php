<?php

namespace Chap\Controllers;

use Chap\Models\Deployment;
use Chap\Models\Application;
use Chap\Services\DeploymentService;

/**
 * Deployment Controller
 */
class DeploymentController extends BaseController
{
    /**
     * Start a new deployment for an application
     */
    public function deploy(string $appId): void
    {
        $this->currentTeam();
        $application = Application::findByUuid($appId) ?? Application::find((int)$appId);

        if (!$application) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application not found'], 404);
            } else {
                flash('error', 'Application not found');
                $this->redirect('/projects');
            }
            return;
        }

        // Verify team access
        $environment = $application->environment();
        if (!$environment) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Environment not found'], 404);
            } else {
                flash('error', 'Environment not found');
                $this->redirect('/projects');
            }
            return;
        }

        $project = $environment->project();
        if (!$project || !$this->canAccessTeamId((int)$project->team_id)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Access denied'], 403);
            } else {
                flash('error', 'Access denied');
                $this->redirect('/projects');
            }
            return;
        }

        // Check if application can be deployed
        if ($application->status === 'deploying') {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Application is already deploying'], 400);
            } else {
                flash('error', 'Application is already deploying');
                $this->redirect('/applications/' . $appId);
            }
            return;
        }

        // Get optional commit SHA from request
        $commitSha = $_POST['commit_sha'] ?? null;

        $triggeredByName = $this->user?->displayName();

        // Create deployment
        $deployment = DeploymentService::create($application, $commitSha, [
            'triggered_by' => $this->user ? 'user' : 'manual',
            'triggered_by_name' => $triggeredByName,
        ]);

        if ($this->isApiRequest()) {
            $this->json(['deployment' => $deployment->toArray()], 201);
        } else {
            flash('success', 'Deployment started');
            $this->redirect('/deployments/' . $deployment->uuid);
        }
    }

    /**
     * Show deployment details
     */
    public function show(string $uuid): void
    {
        $team = $this->currentTeam();
        $deployment = Deployment::findByUuid($uuid);

        if (!$this->canAccessDeployment($deployment, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Deployment not found'], 404);
            } else {
                flash('error', 'Deployment not found');
                $this->redirect('/projects');
            }
        }

        $application = $deployment->application();

        if ($this->isApiRequest()) {
            $this->json([
                'deployment' => $deployment->toArray(),
                'logs' => $deployment->logsArray(),
            ]);
        } else {
            $this->view('deployments/show', [
                'title' => 'Deployment',
                'deployment' => $deployment,
                'application' => $application,
                'environment' => $application->environment(),
                'project' => $application->environment()->project(),
            ]);
        }
    }

    /**
     * Get deployment logs
     */
    public function logs(string $uuid): void
    {
        $team = $this->currentTeam();
        $deployment = Deployment::findByUuid($uuid);

        if (!$this->canAccessDeployment($deployment, $team)) {
            $this->json(['error' => 'Deployment not found'], 404);
        }

        $this->json([
            'logs' => $deployment->logsArray(),
            'status' => $deployment->status,
        ]);
    }

    /**
     * Cancel deployment
     */
    public function cancel(string $uuid): void
    {
        $team = $this->currentTeam();
        $deployment = Deployment::findByUuid($uuid);

        if (!$this->canAccessDeployment($deployment, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Deployment not found'], 404);
            } else {
                flash('error', 'Deployment not found');
                $this->redirect('/projects');
            }
        }

        if (!$deployment->canBeCancelled()) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Deployment cannot be cancelled'], 400);
            } else {
                flash('error', 'This deployment cannot be cancelled');
                $this->redirect('/deployments/' . $uuid);
            }
        }

        DeploymentService::cancel($deployment);

        if ($this->isApiRequest()) {
            $this->json(['message' => 'Deployment cancelled']);
        } else {
            flash('success', 'Deployment cancelled');
            $this->redirect('/deployments/' . $uuid);
        }
    }

    /**
     * Rollback to deployment
     */
    public function rollback(string $uuid): void
    {
        $team = $this->currentTeam();
        $deployment = Deployment::findByUuid($uuid);

        if (!$this->canAccessDeployment($deployment, $team)) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Deployment not found'], 404);
            } else {
                flash('error', 'Deployment not found');
                $this->redirect('/projects');
            }
        }

        if ($deployment->status !== 'running' && $deployment->status !== 'failed') {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Cannot rollback to this deployment'], 400);
            } else {
                flash('error', 'Cannot rollback to this deployment');
                $this->redirect('/deployments/' . $uuid);
            }
        }

        $newDeployment = DeploymentService::rollback($deployment, [
            'triggered_by' => $this->user ? 'rollback' : 'rollback',
            'triggered_by_name' => $this->user?->displayName(),
        ]);

        if ($this->isApiRequest()) {
            $this->json(['deployment' => $newDeployment->toArray()], 201);
        } else {
            flash('success', 'Rollback started');
            $this->redirect('/deployments/' . $newDeployment->uuid);
        }
    }

    /**
     * Check if user can access deployment
     */
    private function canAccessDeployment(?Deployment $deployment, $team): bool
    {
        if (!$deployment) {
            return false;
        }

        $application = $deployment->application();
        if (!$application) {
            return false;
        }

        $environment = $application->environment();
        if (!$environment) {
            return false;
        }

        $project = $environment->project();
        if (!$project || !$this->canAccessTeamId((int)$project->team_id)) {
            return false;
        }

        return true;
    }
}
