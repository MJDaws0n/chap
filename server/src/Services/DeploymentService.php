<?php

namespace Chap\Services;

use Chap\App;
use Chap\Models\Application;
use Chap\Models\Deployment;
use Chap\Models\Node;
use Chap\WebSocket\Server as WebSocketServer;

/**
 * Deployment Service
 */
class DeploymentService
{
    /**
     * Create a new deployment
     */
    public static function create(Application $application, ?string $commitSha = null): Deployment
    {
        $db = App::db();

        // Update application status
        $db->update('applications', ['status' => 'deploying'], 'id = ?', [$application->id]);

        // Create deployment record
        $deployment = Deployment::create([
            'application_id' => $application->id,
            'node_id' => $application->node_id,
            'git_commit_sha' => $commitSha ?? $application->git_commit_sha,
            'status' => 'queued',
        ]);

        // Queue the deployment task
        self::queueDeployment($deployment, $application);

        return $deployment;
    }

    /**
     * Queue deployment to be sent to node
     */
    private static function queueDeployment(Deployment $deployment, Application $application): void
    {
        $node = Node::find($application->node_id);
        
        if (!$node) {
            $deployment->markFailed('No node assigned');
            return;
        }

        // Create the task message
        $task = [
            'type' => 'task:deploy',
            'id' => uuid(),
            'timestamp' => time(),
            'payload' => [
                'task_id' => uuid(),
                'deployment_id' => $deployment->uuid,
                'application' => $application->toDeployPayload(),
            ],
        ];

        // Store task for WebSocket server to send
        self::storeTask($node->id, $task);
        
        $deployment->appendLog('Deployment queued for node: ' . $node->name, 'info');
    }

    /**
     * Store task in database for WebSocket server
     */
    private static function storeTask(int $nodeId, array $task): void
    {
        $db = App::db();
        
        // Create tasks table if not exists (for simplicity, using a simple queue)
        $db->query(
            "INSERT INTO deployment_tasks (node_id, task_data, created_at) VALUES (?, ?, NOW())",
            [$nodeId, json_encode($task)]
        );
    }

    /**
     * Cancel a deployment
     */
    public static function cancel(Deployment $deployment): void
    {
        if (!$deployment->canBeCancelled()) {
            return;
        }

        $deployment->markCancelled();

        // Update application status
        $db = App::db();
        $db->update('applications', ['status' => 'stopped'], 'id = ?', [$deployment->application_id]);

        // Send cancel command to node
        $node = Node::find($deployment->node_id);
        if ($node) {
            $task = [
                'type' => 'task:cancel',
                'payload' => [
                    'deployment_id' => $deployment->uuid,
                ],
            ];
            self::storeTask($node->id, $task);
        }
    }

    /**
     * Stop an application
     */
    public static function stop(Application $application): void
    {
        if (!$application->node_id) {
            return;
        }

        $node = Node::find($application->node_id);
        if (!$node) {
            return;
        }

        $task = [
            'type' => 'container:stop',
            'payload' => [
                'application_uuid' => $application->uuid,
            ],
        ];

        self::storeTask($node->id, $task);
    }

    /**
     * Restart an application
     */
    public static function restart(Application $application): void
    {
        if (!$application->node_id) {
            return;
        }

        $node = Node::find($application->node_id);
        if (!$node) {
            return;
        }

        $task = [
            'type' => 'container:restart',
            'payload' => [
                'application_uuid' => $application->uuid,
            ],
        ];

        self::storeTask($node->id, $task);
    }

    /**
     * Rollback to a previous deployment
     */
    public static function rollback(Deployment $previousDeployment): Deployment
    {
        $application = $previousDeployment->application();
        
        $db = App::db();
        $db->update('applications', ['status' => 'deploying'], 'id = ?', [$application->id]);

        // Create new deployment as rollback
        $deployment = Deployment::create([
            'application_id' => $application->id,
            'node_id' => $application->node_id,
            'commit_sha' => $previousDeployment->commit_sha,
            'rollback_of_id' => $previousDeployment->id,
            'status' => 'queued',
        ]);

        $deployment->appendLog('Rolling back to deployment: ' . $previousDeployment->uuid, 'info');

        self::queueDeployment($deployment, $application);

        return $deployment;
    }

    /**
     * Handle deployment completion from node
     */
    public static function handleCompletion(string $deploymentUuid, bool $success, ?string $error = null): void
    {
        $deployment = Deployment::findByUuid($deploymentUuid);
        if (!$deployment) {
            return;
        }

        $db = App::db();

        if ($success) {
            $deployment->markRunning();
            $db->update('applications', ['status' => 'running'], 'id = ?', [$deployment->application_id]);
        } else {
            $deployment->markFailed($error ?? 'Unknown error');
            $db->update('applications', ['status' => 'error'], 'id = ?', [$deployment->application_id]);
        }
    }

    /**
     * Handle log message from node
     */
    public static function handleLog(string $deploymentUuid, string $message, string $type = 'info'): void
    {
        $deployment = Deployment::findByUuid($deploymentUuid);
        if ($deployment) {
            $deployment->appendLog($message, $type);
        }
    }

    /**
     * Get pending tasks for a node
     */
    public static function getPendingTasks(int $nodeId): array
    {
        try {
            $db = App::db();
            
            $tasks = $db->fetchAll(
                "SELECT * FROM deployment_tasks WHERE node_id = ? ORDER BY created_at ASC LIMIT 10",
                [$nodeId]
            );

            // Delete fetched tasks
            if (!empty($tasks)) {
                $ids = array_column($tasks, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $db->query("DELETE FROM deployment_tasks WHERE id IN ({$placeholders})", $ids);
            }

            return array_map(fn($t) => json_decode($t['task_data'], true), $tasks);
        } catch (\Exception $e) {
            // Table may not exist yet
            return [];
        }
    }
}
