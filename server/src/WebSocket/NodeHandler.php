<?php

namespace Chap\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Chap\Models\Node;
use Chap\Services\DeploymentService;
use Chap\WebSocket\Server;

/**
 * WebSocket Server for Node Communication
 */
class NodeHandler implements MessageComponentInterface {

    protected $clients;
    protected $nodeConnections = [];

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
        // Register this handler with the singleton server helper so
        // other services can push messages to connected nodes.
        try {
            Server::setHandler($this);
        } catch (\Throwable $e) {
            // If Server class is not available for some reason, ignore.
        }
    }


    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);
        echo "New connection: {$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        
        if (!$data || !isset($data['type'])) {
            $this->sendError($from, 'Invalid message format');
            return;
        }

        echo "Message from {$from->resourceId}: {$data['type']}\n";

        switch ($data['type']) {
            case 'node:auth':
                $this->handleAuth($from, $data);
                break;

            case 'node:system_info':
                $this->handleSystemInfo($from, $data);
                break;

            case 'ping':
                $this->handlePing($from, $data);
                break;

            case 'heartbeat':
                $this->handleHeartbeat($from, $data);
                break;

            case 'task:ack':
                $this->handleTaskAck($from, $data);
                break;

            case 'task:log':
                $this->handleTaskLog($from, $data);
                break;

            case 'task:complete':
                $this->handleTaskComplete($from, $data);
                break;

            case 'task:failed':
                $this->handleTaskFailed($from, $data);
                break;

            case 'container:list:response':
                $this->handleContainerList($from, $data);
                break;

            case 'container:logs:stream':
                $this->handleContainerLogs($from, $data);
                break;

            case 'container:logs:response':
                $this->handleContainerLogsResponse($from, $data);
                break;

            // Node agent metrics reporting
            case 'node:metrics':
                $this->handleNodeMetrics($from, $data);
                break;

            // Node agent system info reporting
            case 'node:system_info':
                // Already handled above, but ensure no error
                break;

            // Node agent container logs
            case 'containerLogs':
                $this->handleContainerLogsFromAgent($from, $data);
                break;

            // Node agent exec result
            case 'execResult':
                // Accept exec result from agent, no-op for now
                break;

            // Node agent pull result
            case 'pulled':
            case 'pullFailed':
                // Accept image pull result from agent, no-op for now
                break;

            // Node agent stopped/restarted
            case 'stopped':
            case 'restarted':
                // Accept container stop/restart result from agent, no-op for now
                break;

            default:
                $this->sendError($from, 'Unknown message type: ' . $data['type']);
        }
    }

    /**
     * Handle heartbeat command from server
     */
    protected function handleHeartbeat(ConnectionInterface $conn, array $data): void
    {
        // Optionally update node status, log, or respond
        $nodeId = $conn->nodeId ?? null;
        if ($nodeId) {
            $node = Node::find($nodeId);
            if ($node) {
                $node->markOnline();
            }
        }
        // Respond with ack if needed
        $this->send($conn, [
            'type' => 'heartbeat:ack',
            'payload' => ['received' => 'heartbeat'],
        ]);
        
        // Send any pending tasks on heartbeat
        if ($nodeId) {
            $this->sendPendingTasks($conn, $nodeId);
        }
    }

    /**
     * Handle node metrics reporting
     */
    protected function handleNodeMetrics(ConnectionInterface $conn, array $data): void
    {
        $nodeId = $conn->nodeId ?? null;
        if (!$nodeId) {
            return;
        }

        $node = Node::find($nodeId);
        if ($node) {
            // Update node with metrics from payload
            $payload = $data['payload'] ?? [];
            $node->updateFromHeartbeat($payload);
            
            // Sync containers from node metrics
            if (isset($payload['containers']) && is_array($payload['containers'])) {
                DeploymentService::syncContainersFromNode($nodeId, $payload['containers']);
            }
            
            echo "Received metrics from node {$node->name}\n";
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        $this->clients->detach($conn);

        // Mark node as offline
        $nodeId = $this->getNodeIdForConnection($conn);
        if ($nodeId) {
            $node = Node::find($nodeId);
            if ($node) {
                $node->markOffline();
                echo "Node {$node->name} disconnected\n";
            }
            unset($this->nodeConnections[$nodeId]);
        }

        echo "Connection {$conn->resourceId} closed\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }

    /**
     * Handle node authentication
     */
    protected function handleAuth(ConnectionInterface $conn, array $data): void
    {
        $token = $data['payload']['token'] ?? '';
        
        if (empty($token)) {
            $this->send($conn, [
                'type' => 'server:auth:failed',
                'payload' => ['error' => 'Token required'],
            ]);
            $conn->close();
            return;
        }

        $node = Node::findByToken($token);
        
        if (!$node) {
            $this->send($conn, [
                'type' => 'server:auth:failed',
                'payload' => ['error' => 'Invalid token'],
            ]);
            $conn->close();
            return;
        }

        // Store connection mapping
        $conn->nodeId = $node->id;
        $this->nodeConnections[$node->id] = $conn;

        // Mark node as online
        $node->markOnline();

        echo "Node connection stored: ID={$node->id}, Name={$node->name}, Total connections: " . count($this->nodeConnections) . "\n";

        $this->send($conn, [
            'type' => 'server:auth:success',
            'payload' => [
                'node_id' => $node->uuid,
                'name' => $node->name,
            ],
        ]);

        echo "Node authenticated: {$node->name}\n";

        // Send any pending tasks
        $this->sendPendingTasks($conn, $node->id);
    }

    /**
     * Handle system info update
     */
    protected function handleSystemInfo(ConnectionInterface $conn, array $data): void
    {
        $nodeId = $conn->nodeId ?? null;
        if (!$nodeId) {
            return;
        }

        $node = Node::find($nodeId);
        if ($node) {
            $node->updateFromHeartbeat($data['payload'] ?? []);
        }

        $this->send($conn, [
            'type' => 'server:ack',
            'payload' => ['received' => 'system_info'],
        ]);
    }

    /**
     * Handle ping
     */
    protected function handlePing(ConnectionInterface $conn, array $data): void
    {
        $nodeId = $conn->nodeId ?? null;
        
        if ($nodeId) {
            $node = Node::find($nodeId);
            if ($node) {
                $node->markOnline();
            }
        }

        $this->send($conn, [
            'type' => 'pong',
        ]);

        // Send any pending tasks
        if ($nodeId) {
            $this->sendPendingTasks($conn, $nodeId);
        }
    }

    /**
     * Handle task acknowledgment
     */
    protected function handleTaskAck(ConnectionInterface $conn, array $data): void
    {
        $taskId = $data['payload']['task_id'] ?? '';
        $status = $data['payload']['status'] ?? '';
        
        echo "Task {$taskId} acknowledged: {$status}\n";
    }

    /**
     * Handle task log
     */
    protected function handleTaskLog(ConnectionInterface $conn, array $data): void
    {
        $deploymentId = $data['payload']['deployment_id'] ?? '';
        $message = $data['payload']['message'] ?? '';
        $logType = $data['payload']['log_type'] ?? 'stdout';

        if ($deploymentId && $message) {
            DeploymentService::handleLog($deploymentId, $message, $logType);
        }
    }

    /**
     * Handle task completion
     */
    protected function handleTaskComplete(ConnectionInterface $conn, array $data): void
    {
        $deploymentId = $data['payload']['deployment_id'] ?? '';
        $containerId = $data['payload']['container_id'] ?? '';
        
        if ($deploymentId) {
            DeploymentService::handleCompletion($deploymentId, true, null, $containerId);
            echo "Deployment {$deploymentId} completed successfully\n";
        }
    }

    /**
     * Handle task failure
     */
    protected function handleTaskFailed(ConnectionInterface $conn, array $data): void
    {
        $deploymentId = $data['payload']['deployment_id'] ?? '';
        $error = $data['payload']['error'] ?? 'Unknown error';
        
        if ($deploymentId) {
            DeploymentService::handleCompletion($deploymentId, false, $error);
            echo "Deployment {$deploymentId} failed: {$error}\n";
        }
    }

    /**
     * Handle container list response
     */
    protected function handleContainerList(ConnectionInterface $conn, array $data): void
    {
        // Store/broadcast container list
        $requestId = $data['payload']['request_id'] ?? '';
        $containers = $data['payload']['containers'] ?? [];
        
        echo "Received container list for request {$requestId}: " . count($containers) . " containers\n";
    }

    /**
     * Handle container logs stream
     */
    protected function handleContainerLogs(ConnectionInterface $conn, array $data): void
    {
        // Persist logs to database
        $containerId = $data['payload']['container_id'] ?? '';
        $message = $data['payload']['message'] ?? '';
        $level = $data['payload']['level'] ?? 'info';
        
        if ($containerId && $message) {
            DeploymentService::handleContainerLog($containerId, $message, $level, $conn->nodeId ?? null);
        }
        
        echo "Container log [{$containerId}]: {$message}\n";
    }

    /**
     * Handle container logs from node agent (batch format)
     */
    protected function handleContainerLogsFromAgent(ConnectionInterface $conn, array $data): void
    {
        $payload = $data['payload'] ?? [];
        $containerId = $payload['container_id'] ?? $payload['containerId'] ?? '';
        $logs = $payload['logs'] ?? [];
        $nodeId = $conn->nodeId ?? null;
        
        if (empty($containerId) || empty($logs)) {
            return;
        }
        
        // Logs can be an array of log lines
        foreach ($logs as $log) {
            $line = is_string($log) ? $log : ($log['line'] ?? $log['message'] ?? '');
            $level = is_array($log) ? ($log['level'] ?? 'info') : 'info';
            
            if (!empty($line)) {
                DeploymentService::handleContainerLog($containerId, $line, $level, $nodeId);
            }
        }
        
        echo "Received " . count($logs) . " log lines for container {$containerId}\n";
    }

    /**
     * Handle container logs response from node (includes container list)
     */
    protected function handleContainerLogsResponse(ConnectionInterface $conn, array $data): void
    {
        $payload = $data['payload'] ?? [];
        $applicationUuid = $payload['application_uuid'] ?? '';
        $containers = $payload['containers'] ?? [];
        $logs = $payload['logs'] ?? [];
        $nodeId = $conn->nodeId ?? null;
        
        echo "Received logs response for {$applicationUuid}: " . count($containers) . " containers, " . count($logs) . " log lines\n";
        
        // Store/update containers in database
        if (!empty($containers) && !empty($applicationUuid)) {
            DeploymentService::syncContainersForApplication($applicationUuid, $containers, $nodeId);
        }
        
        // Store in cache for the HTTP endpoint to fetch
        $requestId = $payload['task_id'] ?? $payload['request_id'] ?? null;
        if ($requestId) {
            $cacheFile = "/tmp/logs_response_{$applicationUuid}_{$requestId}.json";
            file_put_contents($cacheFile, json_encode([
                'containers' => $containers,
                'logs' => $logs,
                'timestamp' => time()
            ]));
        }

        // Also write a fallback to the generic key for backwards compatibility
        $cacheFileGeneric = "/tmp/logs_response_{$applicationUuid}.json";
        file_put_contents($cacheFileGeneric, json_encode([
            'containers' => $containers,
            'logs' => $logs,
            'timestamp' => time()
        ]));
    }

    /**
     * Send pending tasks to node
     */
    protected function sendPendingTasks(ConnectionInterface $conn, int $nodeId): void
    {
        $tasks = DeploymentService::getPendingTasks($nodeId);
        
        foreach ($tasks as $task) {
            $this->send($conn, $task);
        }
    }

    /**
     * Check for pending tasks and send to all connected nodes
     * Called by periodic timer
     */
    public function checkAndSendPendingTasks(): void
    {
        foreach ($this->nodeConnections as $nodeId => $conn) {
            $tasks = DeploymentService::getPendingTasks($nodeId);
            
            if (!empty($tasks)) {
                echo "[Task Poller] Found " . count($tasks) . " pending task(s) for node {$nodeId}, sending...\n";
                foreach ($tasks as $task) {
                    $this->send($conn, $task);
                }
            }
        }
    }

    /**
     * Send message to connection
     */
    protected function send(ConnectionInterface $conn, array $data): void
    {
        $data['timestamp'] = time();
        $conn->send(json_encode($data));
    }

    /**
     * Send error message
     */
    protected function sendError(ConnectionInterface $conn, string $error): void
    {
        $this->send($conn, [
            'type' => 'server:error',
            'payload' => ['error' => $error],
        ]);
    }

    /**
     * Get node ID for connection
     */
    protected function getNodeIdForConnection(ConnectionInterface $conn): ?int
    {
        return $conn->nodeId ?? null;
    }

    /**
     * Send message to specific node
     */
    public function sendToNode(int $nodeId, array $data): bool
    {
        echo "[sendToNode] Attempting to send to node ID={$nodeId}, Connected nodes: " . implode(',', array_keys($this->nodeConnections)) . "\n";
        
        if (!isset($this->nodeConnections[$nodeId])) {
            echo "[sendToNode] Node {$nodeId} not found in connections\n";
            return false;
        }

        echo "[sendToNode] Sending message type={$data['type']} to node {$nodeId}\n";
        $this->send($this->nodeConnections[$nodeId], $data);
        return true;
    }

    /**
     * Broadcast to all connected nodes
     */
    public function broadcast(array $data): void
    {
        foreach ($this->nodeConnections as $conn) {
            $this->send($conn, $data);
        }
    }
}
