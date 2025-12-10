<?php

namespace Chap\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Chap\Models\Node;
use Chap\Services\DeploymentService;

/**
 * WebSocket Server for Node Communication
 */
class NodeHandler implements MessageComponentInterface
{
    protected \SplObjectStorage $clients;
    protected array $nodeConnections = []; // node_id => connection

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
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

            default:
                $this->sendError($from, 'Unknown message type: ' . $data['type']);
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
        
        if ($deploymentId) {
            DeploymentService::handleCompletion($deploymentId, true);
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
        // Could broadcast to subscribed clients
        $containerId = $data['payload']['container_id'] ?? '';
        $message = $data['payload']['message'] ?? '';
        
        echo "Container log [{$containerId}]: {$message}\n";
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
        if (!isset($this->nodeConnections[$nodeId])) {
            return false;
        }

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
