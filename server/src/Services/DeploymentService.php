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

        // Check if application has a node assigned
        if (!$application->node_id) {
            throw new \Exception('Application does not have a node assigned. Please assign a node first.');
        }

        $node = Node::find($application->node_id);
        if (!$node) {
            throw new \Exception('The assigned node no longer exists. Please assign a valid node.');
        }

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

        $deployment->appendLog('🚀 Deployment queued for node: ' . $node->name, 'info');
        $deployment->appendLog('⚡ Task will be delivered instantly (WebSocket server polls every second)', 'info');
    }

    /**
     * Store task in database for WebSocket server
     */
    private static function storeTask(int $nodeId, array $task): void
    {
        $db = App::db();
        
        // Create tasks table if not exists (for simplicity, using a simple queue)
        $db->query(
            "INSERT INTO deployment_tasks (node_id, task_data, created_at, task_type) VALUES (?, ?, NOW(), ?)",
            [$nodeId, json_encode($task), $task['type']]
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

            // Also send an app:event with full application+deployment info
            try {
                WebSocketServer::sendToNode($node->id, [
                    'type' => 'app:event',
                    'payload' => [
                        'action' => 'deployment_cancelled',
                        'application' => $deployment->application()?->toDeployPayload() ?? [],
                        'deployment' => [
                            'uuid' => $deployment->uuid,
                            'status' => $deployment->status,
                            'commit_sha' => $deployment->commit_sha,
                        ],
                    ],
                ]);
            } catch (\Throwable $e) {
                // ignore
            }
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
                'build_pack' => $application->build_pack,
            ],
        ];

        self::storeTask($node->id, $task);

        try {
            WebSocketServer::sendToNode($node->id, [
                'type' => 'app:event',
                'payload' => [
                    'action' => 'stop',
                    'application' => $application->toDeployPayload(),
                ],
            ]);
        } catch (\Throwable $e) {
            // fallback to queue
        }
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
                'build_pack' => $application->build_pack,
            ],
        ];

        self::storeTask($node->id, $task);

        try {
            WebSocketServer::sendToNode($node->id, [
                'type' => 'app:event',
                'payload' => [
                    'action' => 'restart',
                    'application' => $application->toDeployPayload(),
                ],
            ]);
        } catch (\Throwable $e) {
            // fallback to queue
        }
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

        // Notify node immediately if possible about rollback
        $node = Node::find($application->node_id);
        if ($node) {
            try {
                WebSocketServer::sendToNode($node->id, [
                    'type' => 'app:event',
                    'payload' => [
                        'action' => 'rollback',
                        'application' => $application->toDeployPayload(),
                        'deployment' => [
                            'uuid' => $deployment->uuid,
                            'rollback_of' => $previousDeployment->uuid,
                        ],
                    ],
                ]);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $deployment;
    }

    /**
     * Handle deployment completion from node
     */
    public static function handleCompletion(string $deploymentUuid, bool $success, ?string $error = null, ?string $containerId = null): void
    {
        $deployment = Deployment::findByUuid($deploymentUuid);
        if (!$deployment) {
            return;
        }

        $db = App::db();

        if ($success) {
            $deployment->markRunning();
            $db->update('applications', ['status' => 'running'], 'id = ?', [$deployment->application_id]);
            
            // Store container_id in deployment record
            if ($containerId) {
                $db->update('deployments', ['container_id' => $containerId], 'id = ?', [$deployment->id]);
                self::trackContainer($deployment, $containerId);
            }
        } else {
            $deployment->markFailed($error ?? 'Unknown error');
            $db->update('applications', ['status' => 'error'], 'id = ?', [$deployment->application_id]);
        }
    }
    
    /**
     * Track container in database
     */
    private static function trackContainer(Deployment $deployment, string $dockerContainerId): void
    {
        try {
            $db = App::db();
            $application = $deployment->application();
            
            if (!$application) {
                return;
            }
            
            // Check if container already exists
            $existing = $db->fetch(
                "SELECT id FROM containers WHERE container_id = ?",
                [$dockerContainerId]
            );
            
            if ($existing) {
                // Update existing container
                $db->update('containers', [
                    'deployment_id' => $deployment->id,
                    'application_id' => $application->id,
                    'status' => 'running',
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'id = ?', [$existing['id']]);
            } else {
                // Create new container record
                $containerName = $application->name . '-' . substr($dockerContainerId, 0, 12);
                
                $db->insert('containers', [
                    'uuid' => uuid(),
                    'node_id' => $deployment->node_id,
                    'deployment_id' => $deployment->id,
                    'application_id' => $application->id,
                    'container_id' => $dockerContainerId,
                    'name' => $containerName,
                    'image' => $application->docker_image ?? 'unknown',
                    'status' => 'running',
                    'started_at' => date('Y-m-d H:i:s'),
                ]);
            }
            
            echo "Container {$dockerContainerId} tracked for deployment {$deployment->uuid}\n";
        } catch (\Exception $e) {
            error_log("Failed to track container: " . $e->getMessage());
        }
    }
    
    /**
     * Sync containers from node metrics report
     */
    public static function syncContainersFromNode(int $nodeId, array $containers): void
    {
        try {
            $db = App::db();
            
            foreach ($containers as $containerData) {
                $dockerId = $containerData['id'] ?? '';
                $name = $containerData['name'] ?? '';
                $image = $containerData['image'] ?? '';
                $status = $containerData['status'] ?? '';
                
                if (empty($dockerId)) {
                    continue;
                }
                
                // Parse status to determine if running
                $isRunning = stripos($status, 'up') !== false;
                $containerStatus = $isRunning ? 'running' : 'exited';
                
                // Check if container exists
                $existing = $db->fetch(
                    "SELECT id, application_id FROM containers WHERE container_id = ?",
                    [$dockerId]
                );
                
                if ($existing) {
                    // Update existing container
                    $db->update('containers', [
                        'status' => $containerStatus,
                        'image' => $image,
                        'updated_at' => date('Y-m-d H:i:s')
                    ], 'id = ?', [$existing['id']]);
                } else {
                    // Try to find application_id from container name
                    // Container names typically include application UUID or name
                    $applicationId = self::findApplicationIdFromContainerName($name);
                    
                    if ($applicationId) {
                        // Create new container record
                        $db->insert('containers', [
                            'uuid' => uuid(),
                            'node_id' => $nodeId,
                            'application_id' => $applicationId,
                            'container_id' => $dockerId,
                            'name' => $name,
                            'image' => $image,
                            'status' => $containerStatus,
                            'started_at' => date('Y-m-d H:i:s'),
                        ]);
                        
                        echo "Discovered and tracked new container: {$name} ({$dockerId})\n";
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("Failed to sync containers from node: " . $e->getMessage());
        }
    }
    
    /**
     * Try to find application ID from container name
     */
    private static function findApplicationIdFromContainerName(string $containerName): ?int
    {
        try {
            $db = App::db();
            
            // Container names often include the application UUID
            // Try to match against application UUIDs in the name
            $applications = $db->fetchAll("SELECT id, uuid, name FROM applications");
            
            foreach ($applications as $app) {
                // Check if UUID is in container name
                if (stripos($containerName, $app['uuid']) !== false) {
                    return (int) $app['id'];
                }
                
                // Check if app name (sanitized) is in container name
                $sanitizedName = strtolower(preg_replace('/[^a-z0-9]+/', '-', $app['name']));
                if (stripos($containerName, $sanitizedName) !== false) {
                    return (int) $app['id'];
                }
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Sync containers for a specific application from node response
     */
    public static function syncContainersForApplication(string $applicationUuid, array $containers, ?int $nodeId): void
    {
        try {
            $db = App::db();
            
            // Find the application
            $app = $db->fetch("SELECT id FROM applications WHERE uuid = ?", [$applicationUuid]);
            if (!$app) {
                return;
            }
            $applicationId = (int) $app['id'];
            
            // Get the latest deployment for this application
            $deployment = $db->fetch(
                "SELECT id FROM deployments WHERE application_id = ? ORDER BY id DESC LIMIT 1",
                [$applicationId]
            );
            $deploymentId = $deployment ? (int) $deployment['id'] : null;
            
            foreach ($containers as $containerData) {
                $dockerId = $containerData['id'] ?? '';
                $name = $containerData['name'] ?? '';
                $image = $containerData['image'] ?? '';
                $status = $containerData['status'] ?? 'running';
                
                if (empty($dockerId) && empty($name)) {
                    continue;
                }
                
                // Normalize status
                $containerStatus = (stripos($status, 'running') !== false || stripos($status, 'up') !== false) ? 'running' : 'exited';
                
                // Check if container exists by docker ID or name
                $existing = $db->fetch(
                    "SELECT id FROM containers WHERE (container_id = ? OR name = ?) AND application_id = ?",
                    [$dockerId, $name, $applicationId]
                );
                
                if ($existing) {
                    // Update existing
                    $db->update('containers', [
                        'container_id' => $dockerId ?: $existing['container_id'],
                        'status' => $containerStatus,
                        'image' => $image,
                        'updated_at' => date('Y-m-d H:i:s')
                    ], 'id = ?', [$existing['id']]);
                } else {
                    // Insert new
                    $db->insert('containers', [
                        'uuid' => uuid(),
                        'node_id' => $nodeId,
                        'deployment_id' => $deploymentId,
                        'application_id' => $applicationId,
                        'container_id' => $dockerId,
                        'name' => $name,
                        'image' => $image,
                        'status' => $containerStatus,
                        'started_at' => date('Y-m-d H:i:s'),
                    ]);
                }
            }
            
            echo "Synced " . count($containers) . " containers for application {$applicationUuid}\n";
        } catch (\Exception $e) {
            error_log("Failed to sync containers for application: " . $e->getMessage());
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
     * Handle container log line from node agent
     */
    public static function handleContainerLog(string $containerId, string $line, string $level = 'info', ?int $nodeId = null): void
    {
        try {
            $db = App::db();
            
            // Find the container by its docker_id or name
            $container = $db->fetch(
                "SELECT c.id, c.application_id FROM containers c WHERE c.docker_id = ? OR c.name = ? LIMIT 1",
                [$containerId, $containerId]
            );
            
            if (!$container) {
                // Try to find by partial docker ID match (first 12 chars)
                $shortId = substr($containerId, 0, 12);
                $container = $db->fetch(
                    "SELECT c.id, c.application_id FROM containers c WHERE c.docker_id LIKE ? LIMIT 1",
                    [$shortId . '%']
                );
            }
            
            $containerDbId = $container['id'] ?? null;
            $applicationId = $container['application_id'] ?? null;
            
            // Insert log line
            $db->query(
                "INSERT INTO container_logs (container_id, application_id, node_id, level, line, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
                [$containerDbId, $applicationId, $nodeId, $level, $line]
            );
        } catch (\Exception $e) {
            // Log error but don't crash - table might not exist yet
            error_log("Failed to store container log: " . $e->getMessage());
        }
    }

    /**
     * Get pending tasks for a node
     */
    public static function getPendingTasks(int $nodeId): array
    {
        try {
            $db = App::db();
            
            // Pick pending tasks, and also retry 'sent' tasks that haven't been updated recently (10s)
            $tasks = $db->fetchAll(
                "SELECT * FROM deployment_tasks WHERE node_id = ? AND (status = 'pending' OR (status = 'sent' AND updated_at < (NOW() - INTERVAL 10 SECOND))) ORDER BY created_at ASC LIMIT 10",
                [$nodeId]
            );

            // Mark fetched tasks as 'sent' so they aren't deleted before node ack
            if (!empty($tasks)) {
                $ids = array_column($tasks, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $db->query("UPDATE deployment_tasks SET status = 'sent', updated_at = NOW() WHERE id IN ({$placeholders})", $ids);
            }

            return array_map(fn($t) => json_decode($t['task_data'], true), $tasks);
        } catch (\Exception $e) {
            // Table may not exist yet
            return [];
        }
    }
}
