<?php

namespace Chap\Controllers;

use Chap\Models\Deployment;
use Chap\Models\Application;
use Chap\Services\DeploymentService;
use Chap\Services\ChapScript\ChapScriptPreDeploy;

/**
 * Deployment Controller
 */
class DeploymentController extends BaseController
{
    private function redirectToApplicationLogs(?Deployment $deployment): void
    {
        $applicationUuid = (string)($deployment?->application()?->uuid ?? '');
        if ($applicationUuid !== '') {
            $this->redirect('/applications/' . $applicationUuid . '/logs');
            return;
        }

        $this->redirect('/projects');
    }

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

        $this->requireTeamPermission('deployments', 'execute', (int) $project->team_id);

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

        // Run template pre-deploy script (ChapScribe) if configured.
        try {
            $pre = ChapScriptPreDeploy::run($application, [
                'commit_sha' => $commitSha,
                'triggered_by' => $this->user ? 'user' : 'manual',
                'triggered_by_name' => $triggeredByName,
                'user_id' => (int)($this->user?->id ?? 0),
            ]);

            if ($pre['status'] === 'waiting') {
                $run = $pre['run'] ?? null;
                $this->json([
                    'error' => 'action_required',
                    'script_run' => $run ? ['uuid' => $run->uuid, 'status' => $run->status] : null,
                    'prompt' => $pre['prompt'] ?? null,
                ], 409);
                return;
            }

            if ($pre['status'] === 'stopped') {
                $this->json(['error' => $pre['message'] ?? 'Deployment blocked by template script'], 422);
                return;
            }
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }

        // Create deployment
        try {
            $deployment = DeploymentService::create($application, $commitSha, [
                'triggered_by' => $this->user ? 'user' : 'manual',
                'triggered_by_name' => $triggeredByName,
            ]);
        } catch (\Throwable $e) {
            if ($this->isApiRequest()) {
                $this->json(['error' => $e->getMessage()], 422);
            } else {
                flash('error', $e->getMessage());
                $this->redirect('/applications/' . $appId);
            }
            return;
        }

        if ($this->isApiRequest()) {
            $this->json(['deployment' => $deployment->toArray()], 201);
        } else {
            flash('success', 'Deployment started');
            $this->redirectToApplicationLogs($deployment);
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

        $teamId = (int) ($deployment->application()?->environment()?->project()?->team_id ?? 0);
        $this->requireTeamPermission('deployments', 'read', $teamId);

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

        $teamId = (int) ($deployment->application()?->environment()?->project()?->team_id ?? 0);
        $this->requireTeamPermission('deployments', 'execute', $teamId);

        if (!$deployment->canBeCancelled()) {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Deployment cannot be cancelled'], 400);
            } else {
                flash('error', 'This deployment cannot be cancelled');
                $this->redirectToApplicationLogs($deployment);
            }
        }

        DeploymentService::cancel($deployment);

        if ($this->isApiRequest()) {
            $this->json(['message' => 'Deployment cancelled']);
        } else {
            flash('success', 'Deployment cancelled');
            $this->redirectToApplicationLogs($deployment);
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

        $teamId = (int) ($deployment->application()?->environment()?->project()?->team_id ?? 0);
        $this->requireTeamPermission('deployments', 'execute', $teamId);

        if ($deployment->status !== 'running' && $deployment->status !== 'failed') {
            if ($this->isApiRequest()) {
                $this->json(['error' => 'Cannot rollback to this deployment'], 400);
            } else {
                flash('error', 'Cannot rollback to this deployment');
                $this->redirectToApplicationLogs($deployment);
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
            $this->redirectToApplicationLogs($newDeployment);
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
