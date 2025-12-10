<?php

namespace Chap\Controllers\Api;

use Chap\Models\Deployment;
use Chap\Models\Application;
use Chap\Services\DeploymentService;

/**
 * API Deployment Controller
 */
class DeploymentController extends BaseApiController
{
    /**
     * List deployments for an application
     */
    public function index(string $appId): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($appId);

        if (!$application) {
            $this->notFound('Application not found');
            return;
        }

        $environment = $application->environment();
        if (!$environment) {
            $this->notFound('Application not found');
            return;
        }

        $project = $environment->project();
        if (!$project || $project->team_id !== $team->id) {
            $this->notFound('Application not found');
            return;
        }

        $deployments = $application->deployments();

        $this->success([
            'deployments' => array_map(fn($d) => $d->toArray(), $deployments),
        ]);
    }

    /**
     * Show deployment
     */
    public function show(string $id): void
    {
        $team = $this->currentTeam();
        $deployment = Deployment::findByUuid($id);

        if (!$deployment) {
            $this->notFound('Deployment not found');
            return;
        }

        $application = Application::find($deployment->application_id);
        if (!$application) {
            $this->notFound('Deployment not found');
            return;
        }

        $environment = $application->environment();
        if (!$environment) {
            $this->notFound('Deployment not found');
            return;
        }

        $project = $environment->project();
        if (!$project || $project->team_id !== $team->id) {
            $this->notFound('Deployment not found');
            return;
        }

        $this->success([
            'deployment' => $deployment->toArray(),
        ]);
    }

    /**
     * Cancel deployment
     */
    public function cancel(string $id): void
    {
        $team = $this->currentTeam();
        $deployment = Deployment::findByUuid($id);

        if (!$deployment) {
            $this->notFound('Deployment not found');
            return;
        }

        $application = Application::find($deployment->application_id);
        if (!$application) {
            $this->notFound('Deployment not found');
            return;
        }

        $environment = $application->environment();
        if (!$environment) {
            $this->notFound('Deployment not found');
            return;
        }

        $project = $environment->project();
        if (!$project || $project->team_id !== $team->id) {
            $this->notFound('Deployment not found');
            return;
        }

        if (!$deployment->canBeCancelled()) {
            $this->error('Deployment cannot be cancelled', 400);
            return;
        }

        DeploymentService::cancel($deployment);

        $this->success(['deployment' => $deployment->toArray()]);
    }
}
