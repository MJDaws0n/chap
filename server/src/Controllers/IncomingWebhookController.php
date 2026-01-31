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
    * Create a new incoming webhook for an application.
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

        $teamId = (int) ($application->environment()?->project()?->team_id ?? 0);
        $this->requireTeamPermission('applications', 'write', $teamId);

        $provider = strtolower(trim((string)($_POST['provider'] ?? 'github')));
        if (!in_array($provider, ['github', 'gitlab', 'bitbucket', 'custom'], true)) {
            $provider = 'github';
        }

        $defaultName = match ($provider) {
            'gitlab' => 'GitLab',
            'bitbucket' => 'Bitbucket',
            'custom' => 'Custom',
            default => 'GitHub',
        };

        $name = trim((string)($_POST['name'] ?? $defaultName));
        if ($name === '') {
            $name = $defaultName;
        }

        $branch = trim((string)($_POST['branch'] ?? ''));
        $branch = $branch !== '' ? $branch : null;

        $secret = generate_token(32);

        $webhook = IncomingWebhook::create([
            'application_id' => $application->id,
            'provider' => $provider,
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

        $teamId = (int) ($application->environment()?->project()?->team_id ?? 0);
        $this->requireTeamPermission('applications', 'write', $teamId);

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

        $teamId = (int) ($application->environment()?->project()?->team_id ?? 0);
        $this->requireTeamPermission('applications', 'write', $teamId);

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

    /**
     * GitLab webhook receiver.
     *
     * Verifies X-Gitlab-Token matches the stored secret.
     */
    public function gitlab(string $webhookUuid): void
    {
        $incoming = IncomingWebhook::findByUuid($webhookUuid);
        if (!$incoming || $incoming->provider !== 'gitlab' || !$incoming->is_active) {
            $this->json(['error' => 'Not found'], 404);
            return;
        }

        $application = $incoming->application();
        if (!$application) {
            $this->json(['error' => 'Not found'], 404);
            return;
        }

        $token = (string)($_SERVER['HTTP_X_GITLAB_TOKEN'] ?? '');
        if ($incoming->secret === '' || $token === '' || !hash_equals($incoming->secret, $token)) {
            $incoming->update([
                'last_received_at' => date('Y-m-d H:i:s'),
                'last_event' => (string)($_SERVER['HTTP_X_GITLAB_EVENT'] ?? ''),
                'last_delivery_id' => (string)($_SERVER['HTTP_X_GITLAB_EVENT_UUID'] ?? ''),
                'last_status' => 'rejected',
                'last_error' => 'Invalid token',
            ]);
            $this->json(['error' => 'Invalid token'], 401);
            return;
        }

        $payloadRaw = (string)file_get_contents('php://input');
        $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        $data = $this->parsePayload($payloadRaw, $contentType);

        $event = (string)($_SERVER['HTTP_X_GITLAB_EVENT'] ?? ($data['object_kind'] ?? ''));
        $deliveryId = (string)($_SERVER['HTTP_X_GITLAB_EVENT_UUID'] ?? '');

        // Dedupe when possible.
        if ($deliveryId !== '') {
            $inserted = IncomingWebhookDelivery::tryInsert(
                $incoming->id,
                'gitlab',
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

        if ($event === 'Push Hook' || $event === 'push') {
            $ref = (string)($data['ref'] ?? '');
            $branch = str_replace('refs/heads/', '', $ref);
            $expectedBranch = (string)($incoming->effectiveBranch($application) ?? '');
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

            $commitSha = $data['checkout_sha'] ?? ($data['after'] ?? null);
            $commitMessage = $data['commits'][0]['message'] ?? null;
            if (!empty($commitSha)) {
                $application->update(['git_commit_sha' => $commitSha]);
            }

            try {
                $deployment = DeploymentService::create($application, $commitSha ?: null, [
                    'triggered_by' => 'webhook:gitlab',
                    'triggered_by_name' => 'GitLab Webhook',
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
                    'deployment' => ['uuid' => $deployment->uuid],
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

    /**
     * Bitbucket webhook receiver.
     *
     * Bitbucket doesn't reliably provide a shared-secret header in all setups.
     * We accept Bearer auth or a `secret` query param.
     */
    public function bitbucket(string $webhookUuid): void
    {
        $incoming = IncomingWebhook::findByUuid($webhookUuid);
        if (!$incoming || $incoming->provider !== 'bitbucket' || !$incoming->is_active) {
            $this->json(['error' => 'Not found'], 404);
            return;
        }

        $application = $incoming->application();
        if (!$application) {
            $this->json(['error' => 'Not found'], 404);
            return;
        }

        $secret = (string)($_GET['secret'] ?? '');
        $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($secret === '' && str_starts_with($auth, 'Bearer ')) {
            $secret = trim(substr($auth, 7));
        }
        if ($incoming->secret === '' || $secret === '' || !hash_equals($incoming->secret, $secret)) {
            $incoming->update([
                'last_received_at' => date('Y-m-d H:i:s'),
                'last_event' => (string)($_SERVER['HTTP_X_EVENT_KEY'] ?? ''),
                'last_delivery_id' => (string)($_SERVER['HTTP_X_REQUEST_UUID'] ?? ''),
                'last_status' => 'rejected',
                'last_error' => 'Invalid secret',
            ]);
            $this->json(['error' => 'Invalid secret'], 401);
            return;
        }

        $payloadRaw = (string)file_get_contents('php://input');
        $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        $data = $this->parsePayload($payloadRaw, $contentType);

        $event = (string)($_SERVER['HTTP_X_EVENT_KEY'] ?? '');
        $deliveryId = (string)($_SERVER['HTTP_X_REQUEST_UUID'] ?? '');

        if ($deliveryId !== '') {
            $inserted = IncomingWebhookDelivery::tryInsert(
                $incoming->id,
                'bitbucket',
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

        // Push events trigger auto-redeploy
        if (isset($data['push']['changes']) && is_array($data['push']['changes'])) {
            foreach ($data['push']['changes'] as $change) {
                $branch = (string)($change['new']['name'] ?? '');
                if ($branch === '') continue;

                $expectedBranch = (string)($incoming->effectiveBranch($application) ?? '');
                if ($expectedBranch !== '' && $branch !== $expectedBranch) {
                    continue;
                }

                $commitSha = $change['new']['target']['hash'] ?? null;
                $commitMessage = $change['new']['target']['message'] ?? null;

                if (!empty($commitSha)) {
                    $application->update(['git_commit_sha' => $commitSha]);
                }

                try {
                    $deployment = DeploymentService::create($application, $commitSha ?: null, [
                        'triggered_by' => 'webhook:bitbucket',
                        'triggered_by_name' => 'Bitbucket Webhook',
                    ]);

                    $incoming->update([
                        'last_received_at' => date('Y-m-d H:i:s'),
                        'last_event' => $event ?: 'push',
                        'last_delivery_id' => $deliveryId ?: null,
                        'last_status' => 'deployed',
                        'last_error' => null,
                    ]);

                    $this->json([
                        'status' => 'deployed',
                        'deployment' => ['uuid' => $deployment->uuid],
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
                        'last_event' => $event ?: 'push',
                        'last_delivery_id' => $deliveryId ?: null,
                        'last_status' => 'error',
                        'last_error' => $e->getMessage(),
                    ]);
                    $this->json(['status' => 'error'], 202);
                    return;
                }
            }

            $incoming->update([
                'last_received_at' => date('Y-m-d H:i:s'),
                'last_event' => $event ?: 'push',
                'last_delivery_id' => $deliveryId ?: null,
                'last_status' => 'ignored',
                'last_error' => 'Branch mismatch',
            ]);
            $this->json(['status' => 'ignored']);
            return;
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

    /**
     * Custom webhook receiver.
     *
     * Verifies Bearer auth or a `secret` query param.
     */
    public function custom(string $webhookUuid): void
    {
        $incoming = IncomingWebhook::findByUuid($webhookUuid);
        if (!$incoming || $incoming->provider !== 'custom' || !$incoming->is_active) {
            $this->json(['error' => 'Not found'], 404);
            return;
        }

        $application = $incoming->application();
        if (!$application) {
            $this->json(['error' => 'Not found'], 404);
            return;
        }

        $secret = (string)($_GET['secret'] ?? '');
        $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($secret === '' && str_starts_with($auth, 'Bearer ')) {
            $secret = trim(substr($auth, 7));
        }

        if ($incoming->secret === '' || $secret === '' || !hash_equals($incoming->secret, $secret)) {
            $incoming->update([
                'last_received_at' => date('Y-m-d H:i:s'),
                'last_event' => 'custom',
                'last_delivery_id' => null,
                'last_status' => 'rejected',
                'last_error' => 'Invalid secret',
            ]);
            $this->json(['error' => 'Invalid secret'], 401);
            return;
        }

        $payloadRaw = (string)file_get_contents('php://input');
        $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        $data = $this->parsePayload($payloadRaw, $contentType);

        $branch = (string)($data['branch'] ?? '');
        if ($branch === '' && isset($data['ref'])) {
            $branch = str_replace('refs/heads/', '', (string)$data['ref']);
        }
        $expectedBranch = (string)($incoming->effectiveBranch($application) ?? '');
        if ($expectedBranch !== '' && $branch !== '' && $branch !== $expectedBranch) {
            $incoming->update([
                'last_received_at' => date('Y-m-d H:i:s'),
                'last_event' => 'custom',
                'last_delivery_id' => null,
                'last_status' => 'ignored',
                'last_error' => 'Branch mismatch',
            ]);
            $this->json(['status' => 'ignored']);
            return;
        }

        $commitSha = $data['commit'] ?? null;
        $commitMessage = $data['message'] ?? 'Manual trigger';
        if (!empty($commitSha)) {
            $application->update(['git_commit_sha' => $commitSha]);
        }

        try {
            $deployment = DeploymentService::create($application, $commitSha ?: null, [
                'triggered_by' => 'webhook:custom',
                'triggered_by_name' => 'Custom Webhook',
            ]);

            $incoming->update([
                'last_received_at' => date('Y-m-d H:i:s'),
                'last_event' => 'custom',
                'last_delivery_id' => null,
                'last_status' => 'deployed',
                'last_error' => null,
            ]);

            $this->json([
                'status' => 'deployed',
                'deployment' => ['uuid' => $deployment->uuid],
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
                'last_event' => 'custom',
                'last_delivery_id' => null,
                'last_status' => 'error',
                'last_error' => $e->getMessage(),
            ]);
            $this->json(['status' => 'error'], 202);
            return;
        }
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

}
