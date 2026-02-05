<?php

namespace Chap\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Chap\Models\Node;
use Chap\Auth\TeamPermissionService;
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
                $this->handleTaskAckCaller($from, $data);
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

            case 'port:check:response':
                $this->handlePortCheckResponse($from, $data);
                break;

            // Session validation for browser WebSocket connections
            case 'session:validate':
                $this->handleSessionValidate($from, $data);
                break;

            // Node agent metrics reporting
            case 'node:metrics':
                $this->handleNodeMetrics($from, $data);
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
                // Accept container stop/restart result from agent and update application status.
                $payload = $data['payload'] ?? [];
                $applicationUuid = (string)($payload['application_uuid'] ?? $payload['applicationId'] ?? '');
                if (!empty($applicationUuid)) {
                    try {
                        $db = \Chap\App::db();
                        $newStatus = ($data['type'] === 'restarted') ? 'running' : 'stopped';
                        $db->query("UPDATE applications SET status = ?, updated_at = NOW() WHERE uuid = ?", [$newStatus, $applicationUuid]);
                    } catch (\Throwable $e) {
                        // ignore
                    }
                }
                break;

            // Node agent application deletion result
            case 'application:deleted':
            case 'application:delete:failed':
                // Accept delete result messages from agent. The reliable stop-retry signal
                // is task:ack (sent by the agent when it receives the delete task).
                $payload = $data['payload'] ?? [];
                $applicationUuid = $payload['application_uuid'] ?? '';
                $taskId = $payload['task_id'] ?? '';
                if ($data['type'] === 'application:deleted') {
                    echo "Application deleted on node: {$applicationUuid} task_id={$taskId}\n";

                    // Best-effort: remove any tracked container rows for this app.
                    // The application row may already be gone (e.g. deleted via cascade),
                    // so also match by container name containing the UUID.
                    try {
                        $db = \Chap\App::db();
                        $nodeId = $from->nodeId ?? null;
                        if ($nodeId && $applicationUuid) {
                            $appRow = $db->fetch("SELECT id FROM applications WHERE uuid = ?", [$applicationUuid]);
                            if ($appRow && !empty($appRow['id'])) {
                                $db->query("DELETE FROM containers WHERE application_id = ?", [(int)$appRow['id']]);
                            }
                            $db->query("DELETE FROM containers WHERE node_id = ? AND name LIKE ?", [(int)$nodeId, '%' . $applicationUuid . '%']);
                        }
                    } catch (\Throwable $e) {
                        // ignore
                    }
                } else {
                    $error = $payload['error'] ?? 'Unknown error';
                    echo "Application delete failed on node: {$applicationUuid} task_id={$taskId} error={$error}\n";
                }
                break;

            default:
                $this->sendError($from, 'Unknown message type: ' . $data['type']);
        }
    }

    protected function handlePortCheckResponse(ConnectionInterface $conn, array $data): void
    {
        $payload = $data['payload'] ?? [];
        $requestId = (string)($payload['request_id'] ?? '');
        $port = $payload['port'] ?? null;
        $free = $payload['free'] ?? null;

        if ($requestId === '' || $port === null || $free === null) {
            return;
        }

        $nodeId = $conn->nodeId ?? null;
        if (!$nodeId) {
            return;
        }

        $node = Node::find((int)$nodeId);
        if (!$node) {
            return;
        }

        $cacheFile = "/tmp/port_check_{$node->uuid}_{$requestId}.json";
        file_put_contents($cacheFile, json_encode([
            'port' => (int)$port,
            'free' => (bool)$free,
            'timestamp' => time(),
        ]));
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
    
    // Backwards-compatible: call DB updater
    protected function handleTaskAckCaller(ConnectionInterface $conn, array $data): void
    {
        $this->handleTaskAck($conn, $data);
        $this->handleTaskAck_updateDb($conn, $data);
    }

    /**
     * When a node acknowledges a task, update the task row so it won't be retried.
     */
    protected function handleTaskAck_updateDb(ConnectionInterface $conn, array $data): void
    {
        $taskId = $data['payload']['task_id'] ?? '';
        $status = $data['payload']['status'] ?? '';

        if (empty($taskId)) return;

        try {
            $db = \Chap\App::db();
            // Find task rows that contain this task_id in their JSON payload
            $rows = $db->fetchAll("SELECT id FROM deployment_tasks WHERE JSON_EXTRACT(task_data, '$.payload.task_id') = ? LIMIT 10", [$taskId]);
            if (!empty($rows)) {
                $ids = array_column($rows, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $db->query("UPDATE deployment_tasks SET status = 'acknowledged', updated_at = NOW() WHERE id IN ({$placeholders})", $ids);
                echo "Marked " . count($ids) . " task(s) acknowledged for task_id={$taskId}\n";
            }
        } catch (\Throwable $e) {
            echo "Failed to mark task ack for {$taskId}: " . $e->getMessage() . "\n";
        }
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
     * Send pending tasks to node
     */
    protected function sendPendingTasks(ConnectionInterface $conn, int $nodeId): void
    {
        $tasks = DeploymentService::getPendingTasks($nodeId);
        
        foreach ($tasks as $task) {
            $tid = $task['payload']['task_id'] ?? ($task['id'] ?? null) ?? 'unknown';
            echo "[sendPendingTasks] Sending task type={$task['type']} task_id={$tid} to node {$nodeId}\n";
            $this->send($conn, $task);
        }
    }

    /**
     * Check for pending tasks and send to all connected nodes
     * Called by periodic timer (every 100ms)
     */
    public function checkAndSendPendingTasks(): void
    {
        // Skip if no nodes connected
        if (empty($this->nodeConnections)) {
            return;
        }
        
        // Get all pending tasks in one query for all connected nodes
        $nodeIds = array_keys($this->nodeConnections);
        $allTasks = DeploymentService::getPendingTasksForNodes($nodeIds);
        
        // Group by node and send
        foreach ($allTasks as $nodeId => $tasks) {
            if (!empty($tasks) && isset($this->nodeConnections[$nodeId])) {
                $conn = $this->nodeConnections[$nodeId];
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

    /**
     * Handle session validation request from node
     * Node calls this to validate a browser's session before allowing log streaming
     */
    protected function handleSessionValidate(ConnectionInterface $from, array $data): void
    {
        $sessionId = $data['session_id'] ?? null;
        $applicationUuid = $data['application_uuid'] ?? null;
        $requestId = $data['request_id'] ?? null;

        // Log all incoming data for debugging
        error_log("[handleSessionValidate] Incoming: " . json_encode($data));
        echo "[handleSessionValidate] Incoming: " . json_encode($data) . "\n";

        if (!$sessionId || !$applicationUuid) {
            $error = 'Missing session_id or application_uuid';
            error_log("[handleSessionValidate] $error");
            $this->send($from, [
                'type' => 'session:validate:response',
                'request_id' => $requestId,
                'authorized' => false,
                'error' => $error
            ]);
            return;
        }

        try {
            $db = \Chap\App::db();

            // Look up session in database
            $session = $db->fetch(
                "SELECT * FROM sessions WHERE id = ?",
                [$sessionId]
            );

            if (!$session) {
                $error = "Session not found: {$sessionId}";
                error_log("[handleSessionValidate] $error");
                $this->send($from, [
                    'type' => 'session:validate:response',
                    'request_id' => $requestId,
                    'authorized' => false,
                    'error' => $error
                ]);
                return;
            }

            // Check session expiry (matches AuthManager logic; supports remember-me sessions)
            $defaultSeconds = (int)config('session.lifetime', 120) * 60;
            $days = (int)config('session.remember_lifetime_days', 30);
            $rememberSeconds = max(1, $days) * 86400;

            $lifetimeSeconds = max(60, $defaultSeconds);
            $payload = $session['payload'] ?? '';
            if (is_string($payload) && $payload !== '') {
                $decoded = json_decode($payload, true);
                if (is_array($decoded) && !empty($decoded['remember'])) {
                    $lifetimeSeconds = max($lifetimeSeconds, $rememberSeconds);
                }
            }

            if (time() - (int)$session['last_activity'] > $lifetimeSeconds) {
                $error = "Session expired: {$sessionId}";
                error_log("[handleSessionValidate] $error");
                $this->send($from, [
                    'type' => 'session:validate:response',
                    'request_id' => $requestId,
                    'authorized' => false,
                    'error' => $error
                ]);
                return;
            }

            // Find application by UUID
            $application = $db->fetch(
                "SELECT a.*, e.project_id AS env_project_id, p.team_id AS project_team_id
                 FROM applications a
                 LEFT JOIN environments e ON e.id = a.environment_id
                 LEFT JOIN projects p ON p.id = e.project_id
                 WHERE a.uuid = ?",
                [$applicationUuid]
            );

            if (!$application) {
                $error = "Application not found: {$applicationUuid}";
                error_log("[handleSessionValidate] $error");
                $this->send($from, [
                    'type' => 'session:validate:response',
                    'request_id' => $requestId,
                    'authorized' => false,
                    'error' => $error
                ]);
                return;
            }

            // Bind validation to the node connection to prevent other nodes from authorizing access
            $connNodeId = $from->nodeId ?? null;
            if (!$connNodeId || empty($application['node_id']) || (int)$application['node_id'] !== (int)$connNodeId) {
                $error = "Access denied: application is not assigned to this node";
                error_log("[handleSessionValidate] $error (connNodeId={$connNodeId}, appNodeId=" . ($application['node_id'] ?? 'null') . ")");
                $this->send($from, [
                    'type' => 'session:validate:response',
                    'request_id' => $requestId,
                    'authorized' => false,
                    'error' => $error
                ]);
                return;
            }

            $teamId = $application['project_team_id'] ?? null;
            if (!$teamId) {
                $error = "Access denied: application is missing team context";
                error_log("[handleSessionValidate] $error");
                $this->send($from, [
                    'type' => 'session:validate:response',
                    'request_id' => $requestId,
                    'authorized' => false,
                    'error' => $error
                ]);
                return;
            }

            // Authorize if the user is a member of the owning team
            $teamUser = $db->fetch(
                "SELECT id FROM team_user WHERE team_id = ? AND user_id = ? LIMIT 1",
                [$teamId, $session['user_id']]
            );
            if (!$teamUser) {
                $error = "Access denied: user is not a member of the application's team";
                error_log("[handleSessionValidate] $error (user_id={$session['user_id']}, team_id={$teamId})");
                $this->send($from, [
                    'type' => 'session:validate:response',
                    'request_id' => $requestId,
                    'authorized' => false,
                    'error' => $error
                ]);
                return;
            }

            // Session is valid and user has access
            error_log("[handleSessionValidate] Session valid, user {$session['user_id']} authorized for app {$applicationUuid}");

            $effectivePerms = [];
            try {
                $effectivePerms = TeamPermissionService::effectivePermissions((int)$teamId, (int)$session['user_id']);
            } catch (\Throwable $e) {
                $effectivePerms = [];
            }

            $this->send($from, [
                'type' => 'session:validate:response',
                'request_id' => $requestId,
                'authorized' => true,
                'user_id' => $session['user_id'],
                'team_id' => $teamId,
                'application_id' => $application['id'],
                'perms' => $effectivePerms,
            ]);

        } catch (\Exception $e) {
            $error = '[handleSessionValidate] Exception: ' . $e->getMessage();
            error_log($error . "\n" . $e->getTraceAsString());
            echo "[handleSessionValidate] Error: {$e->getMessage()}\n";
            $this->send($from, [
                'type' => 'session:validate:response',
                'request_id' => $requestId,
                'authorized' => false,
                'error' => $error
            ]);
        }
    }
}
