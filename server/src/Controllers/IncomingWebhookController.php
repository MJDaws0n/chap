<?php

namespace Chap\Controllers;

use Chap\App;
use Chap\Models\Application;
use Chap\Models\IncomingWebhook;
use Chap\Models\IncomingWebhookDelivery;
use Chap\Services\DeploymentService;

/**
 * Incoming Webhook Controller
 *
 * - Public receiver endpoints for Git providers (signed)
 * - Authenticated management endpoints for creating/rotating/deleting
 */
class IncomingWebhookController extends BaseController
{
    /**
     * Create a new incoming webhook for an application (GitHub).
     */
    public function store(string $applicationUuid): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid CSRF token');
            $this->redirect($_SERVER['HTTP_REFERER'] ?? '/projects');
            return;
        }

        $team = $this->currentTeam();
        $application = Application::findByUuid($applicationUuid);

        if (!$this->canAccessApplication($application, $team)) {
            flash('error', 'Application not found');
            $this->redirect('/projects');
            return;
        }

        $name = trim((string)($_POST['name'] ?? 'GitHub'));
        if ($name === '') {
            $name = 'GitHub';
        }

        $branch = trim((string)($_POST['branch'] ?? ''));
        $branch = $branch !== '' ? $branch : null;

        $secret = generate_token(32);

        $webhook = IncomingWebhook::create([
            'application_id' => $application->id,
            'provider' => 'github',
            'name' => $name,
            'secret' => $secret,
            'branch' => $branch,
            'is_active' => 1,
        ]);

        $_SESSION['incoming_webhook_reveals'] = $_SESSION['incoming_webhook_reveals'] ?? [];
        $_SESSION['incoming_webhook_reveals'][$webhook->uuid] = $secret;

        flash('success', 'Incoming webhook created');
        $this->redirect('/applications/' . $application->uuid);
    }

    /**
     * Rotate secret for an incoming webhook.
     */
    public function rotate(string $webhookUuid): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid CSRF token');
            $this->redirect($_SERVER['HTTP_REFERER'] ?? '/projects');
            return;
        }

        $team = $this->currentTeam();
        $webhook = IncomingWebhook::findByUuid($webhookUuid);

        if (!$webhook) {
            flash('error', 'Webhook not found');
            $this->redirect('/projects');
            return;
        }

        $application = $webhook->application();
        if (!$this->canAccessApplication($application, $team)) {
            flash('error', 'Access denied');
            $this->redirect('/projects');
            return;
        }

        $secret = generate_token(32);
        $webhook->update([
            'secret' => $secret,
            'last_error' => null,
        ]);

        $_SESSION['incoming_webhook_reveals'] = $_SESSION['incoming_webhook_reveals'] ?? [];
        $_SESSION['incoming_webhook_reveals'][$webhook->uuid] = $secret;

        flash('success', 'Webhook secret rotated');
        $this->redirect('/applications/' . $application->uuid);
    }

    /**
     * Delete an incoming webhook.
     */
    public function destroy(string $webhookUuid): void
    {
        if (!verify_csrf($this->input('_csrf_token', ''))) {
            flash('error', 'Invalid CSRF token');
            $this->redirect($_SERVER['HTTP_REFERER'] ?? '/projects');
            return;
        }

        $team = $this->currentTeam();
        $webhook = IncomingWebhook::findByUuid($webhookUuid);

        if (!$webhook) {
            flash('error', 'Webhook not found');
            $this->redirect('/projects');
            return;
        }

        $application = $webhook->application();
        if (!$this->canAccessApplication($application, $team)) {
            flash('error', 'Access denied');
            $this->redirect('/projects');
            return;
        }

        $webhook->delete();

        flash('success', 'Webhook deleted');
        $this->redirect('/applications/' . $application->uuid);
    }

    /**
     * GitHub webhook receiver
     *
     * Supports application/json and application/x-www-form-urlencoded.
     * Verifies X-Hub-Signature-256 against the raw request body.
     */
    public function github(string $webhookUuid): void
    {
        $incoming = IncomingWebhook::findByUuid($webhookUuid);

        // Avoid leaking existence
        if (!$incoming || $incoming->provider !== 'github' || !$incoming->is_active) {
            $this->json(['error' => 'Not found'], 404);
            return;
        }

        $application = $incoming->application();
        if (!$application) {
            $this->json(['error' => 'Not found'], 404);
            return;
        }

        $payloadRaw = (string) file_get_contents('php://input');
        $signature = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
        $event = (string) ($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');
        $deliveryId = (string) ($_SERVER['HTTP_X_GITHUB_DELIVERY'] ?? '');
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

        if (!$this->verifyGitHubSignature($payloadRaw, $signature, $incoming->secret)) {
            $incoming->update([
                'last_received_at' => date('Y-m-d H:i:s'),
                'last_event' => $event ?: null,
                'last_delivery_id' => $deliveryId ?: null,
                'last_status' => 'rejected',
                'last_error' => 'Invalid signature',
            ]);
            $this->json(['error' => 'Invalid signature'], 401);
            return;
        }

        // Dedupe
        if ($deliveryId !== '') {
            $inserted = IncomingWebhookDelivery::tryInsert(
                $incoming->id,
                'github',
                $deliveryId,
                $event ?: null,
                null,
                null
            );

            if (!$inserted) {
                $incoming->update([
                    'last_received_at' => date('Y-m-d H:i:s'),
                    'last_event' => $event ?: null,
                    'last_delivery_id' => $deliveryId,
                    'last_status' => 'duplicate',
                    'last_error' => null,
                ]);
                $this->json(['status' => 'duplicate']);
                return;
            }
        }

        $data = $this->parsePayload($payloadRaw, $contentType);

        // Ping/test
        if ($event === 'ping') {
            $incoming->update([
                'last_received_at' => date('Y-m-d H:i:s'),
                'last_event' => 'ping',
                'last_delivery_id' => $deliveryId ?: null,
                'last_status' => 'ok',
                'last_error' => null,
            ]);
            $this->json(['status' => 'ok']);
            return;
        }

        // Push events trigger auto-redeploy
        if ($event === 'push') {
            $ref = (string) ($data['ref'] ?? '');
            $branch = str_replace('refs/heads/', '', $ref);

            $expectedBranch = (string) ($incoming->effectiveBranch($application) ?? '');

            if ($expectedBranch !== '' && $branch !== $expectedBranch) {
                $incoming->update([
                    'last_received_at' => date('Y-m-d H:i:s'),
                    'last_event' => 'push',
                    'last_delivery_id' => $deliveryId ?: null,
                    'last_status' => 'ignored',
                    'last_error' => 'Branch mismatch',
                ]);
                $this->json(['status' => 'ignored']);
                return;
            }

            $commitSha = $data['after'] ?? null;
            $commitMessage = $data['head_commit']['message'] ?? null;

            if (!empty($commitSha)) {
                $application->update(['git_commit_sha' => $commitSha]);
            }

            // Update delivery record with extra info if available
            if ($deliveryId !== '') {
                try {
                    App::db()->update(
                        'incoming_webhook_deliveries',
                        [
                            'ref' => $ref ?: null,
                            'commit_sha' => $commitSha,
                        ],
                        'incoming_webhook_id = ? AND delivery_id = ?',
                        [$incoming->id, $deliveryId]
                    );
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            try {
                $deployment = DeploymentService::create($application, $commitSha, [
                    'triggered_by' => 'webhook:github',
                    'triggered_by_name' => 'GitHub Webhook',
                ]);

                $incoming->update([
                    'last_received_at' => date('Y-m-d H:i:s'),
                    'last_event' => 'push',
                    'last_delivery_id' => $deliveryId ?: null,
                    'last_status' => 'deployed',
                    'last_error' => null,
                ]);

                $this->json([
                    'status' => 'deployed',
                    'deployment' => [
                        'uuid' => $deployment->uuid,
                    ],
                    'commit' => [
                        'sha' => $commitSha,
                        'message' => $commitMessage,
                        'branch' => $branch,
                    ],
                ], 202);
                return;
            } catch (\Throwable $e) {
                $incoming->update([
                    'last_received_at' => date('Y-m-d H:i:s'),
                    'last_event' => 'push',
                    'last_delivery_id' => $deliveryId ?: null,
                    'last_status' => 'error',
                    'last_error' => $e->getMessage(),
                ]);

                // Still return 2xx so GitHub doesn't keep retrying forever on internal errors.
                $this->json(['status' => 'error'], 202);
                return;
            }
        }

        $incoming->update([
            'last_received_at' => date('Y-m-d H:i:s'),
            'last_event' => $event ?: null,
            'last_delivery_id' => $deliveryId ?: null,
            'last_status' => 'ok',
            'last_error' => null,
        ]);

        $this->json(['status' => 'ok']);
    }

    private function verifyGitHubSignature(string $payloadRaw, string $signatureHeader, string $secret): bool
    {
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $payloadRaw, $secret);
        return hash_equals($expected, $signatureHeader);
    }

    /**
     * Parse payload supporting JSON and form-urlencoded.
     */
    private function parsePayload(string $payloadRaw, string $contentType): array
    {
        if (str_contains($contentType, 'application/json')) {
            return json_decode($payloadRaw, true) ?: [];
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $parsed = [];
            parse_str($payloadRaw, $parsed);

            if (isset($parsed['payload']) && is_string($parsed['payload'])) {
                $decoded = json_decode($parsed['payload'], true);
                return is_array($decoded) ? $decoded : [];
            }

            return $parsed;
        }

        // Fallback: try JSON
        $decoded = json_decode($payloadRaw, true);
        return is_array($decoded) ? $decoded : [];
    }

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
