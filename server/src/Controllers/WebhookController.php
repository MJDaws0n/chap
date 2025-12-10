<?php

namespace Chap\Controllers;

use Chap\Models\Application;
use Chap\Services\DeploymentService;

/**
 * Webhook Controller
 * 
 * Handles incoming webhooks from Git providers to trigger deployments
 */
class WebhookController extends BaseController
{
    /**
     * Handle GitHub webhook
     */
    public function github(string $applicationId): void
    {
        $application = Application::findByUuid($applicationId);
        
        if (!$application) {
            $this->json(['error' => 'Application not found'], 404);
            return;
        }

        // Verify webhook signature
        $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        $payload = file_get_contents('php://input');
        
        // TODO: Verify signature with webhook secret
        // if (!$this->verifyGitHubSignature($payload, $signature, $application->webhook_secret)) {
        //     $this->json(['error' => 'Invalid signature'], 401);
        //     return;
        // }

        $data = json_decode($payload, true);
        $event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';

        // Handle push events
        if ($event === 'push') {
            $branch = str_replace('refs/heads/', '', $data['ref'] ?? '');
            
            // Only deploy if push is to the configured branch
            if ($branch === $application->git_branch) {
                $this->triggerDeployment($application, [
                    'git_commit_sha' => $data['after'] ?? null,
                    'git_commit_message' => $data['head_commit']['message'] ?? null,
                    'triggered_by' => 'webhook:github',
                ]);
            }
        }

        $this->json(['status' => 'ok']);
    }

    /**
     * Handle GitLab webhook
     */
    public function gitlab(string $applicationId): void
    {
        $application = Application::findByUuid($applicationId);
        
        if (!$application) {
            $this->json(['error' => 'Application not found'], 404);
            return;
        }

        // Verify webhook token
        $token = $_SERVER['HTTP_X_GITLAB_TOKEN'] ?? '';
        
        // TODO: Verify token matches application webhook secret

        $data = json_decode(file_get_contents('php://input'), true);
        $event = $data['object_kind'] ?? '';

        // Handle push events
        if ($event === 'push') {
            $branch = str_replace('refs/heads/', '', $data['ref'] ?? '');
            
            if ($branch === $application->git_branch) {
                $this->triggerDeployment($application, [
                    'git_commit_sha' => $data['after'] ?? null,
                    'git_commit_message' => $data['commits'][0]['message'] ?? null,
                    'triggered_by' => 'webhook:gitlab',
                ]);
            }
        }

        $this->json(['status' => 'ok']);
    }

    /**
     * Handle Bitbucket webhook
     */
    public function bitbucket(string $applicationId): void
    {
        $application = Application::findByUuid($applicationId);
        
        if (!$application) {
            $this->json(['error' => 'Application not found'], 404);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true);

        // Handle push events
        if (isset($data['push']['changes'])) {
            foreach ($data['push']['changes'] as $change) {
                $branch = $change['new']['name'] ?? '';
                
                if ($branch === $application->git_branch) {
                    $this->triggerDeployment($application, [
                        'git_commit_sha' => $change['new']['target']['hash'] ?? null,
                        'git_commit_message' => $change['new']['target']['message'] ?? null,
                        'triggered_by' => 'webhook:bitbucket',
                    ]);
                    break;
                }
            }
        }

        $this->json(['status' => 'ok']);
    }

    /**
     * Handle custom webhook
     */
    public function custom(string $applicationId): void
    {
        $application = Application::findByUuid($applicationId);
        
        if (!$application) {
            $this->json(['error' => 'Application not found'], 404);
            return;
        }

        // Verify webhook secret in Authorization header or query param
        $secret = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_GET['secret'] ?? '');
        
        // TODO: Verify secret matches application webhook secret

        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $this->triggerDeployment($application, [
            'git_commit_sha' => $data['commit'] ?? null,
            'git_commit_message' => $data['message'] ?? 'Manual trigger',
            'triggered_by' => 'webhook:custom',
        ]);

        $this->json(['status' => 'ok']);
    }

    /**
     * Trigger a deployment
     */
    private function triggerDeployment(Application $application, array $options): void
    {
        try {
            // Update application with commit info
            if (!empty($options['git_commit_sha'])) {
                $application->update(['git_commit_sha' => $options['git_commit_sha']]);
            }
            
            DeploymentService::create($application, $options['git_commit_sha'] ?? null);
        } catch (\Exception $e) {
            error_log("Webhook deployment failed: " . $e->getMessage());
        }
    }

    /**
     * Verify GitHub webhook signature
     */
    private function verifyGitHubSignature(string $payload, string $signature, string $secret): bool
    {
        if (empty($signature) || empty($secret)) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }
}
