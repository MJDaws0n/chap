<?php

namespace Chap\WebSocket;

use Chap\App;

/**
 * WebSocket Server Singleton Helper
 * Allows other services to send messages to connected nodes
 */
class Server
{
    private static ?NodeHandler $handler = null;

    /**
     * Set the active NodeHandler instance
     */
    public static function setHandler(NodeHandler $handler): void
    {
        self::$handler = $handler;
        error_log("[WebSocket\\Server] Handler registered");
    }

    /**
     * Send message to a specific node
     */
    public static function sendToNode(int $nodeId, array $data): bool
    {
        if (!self::$handler) {
            // We're likely in the HTTP process, while the WebSocket daemon runs in a separate process.
            // Enqueue the message so the daemon can pick it up and deliver it.
            try {
                $db = App::db();
                $type = (string)($data['type'] ?? 'task');
                $db->query(
                    "INSERT INTO deployment_tasks (node_id, task_data, created_at, task_type) VALUES (?, ?, NOW(), ?)",
                    [$nodeId, json_encode($data), $type]
                );
                return true;
            } catch (\Throwable $e) {
                error_log("[WebSocket\\Server] No handler set and failed to enqueue message for node {$nodeId}: " . $e->getMessage());
                return false;
            }
        }

        error_log("[WebSocket\\Server] Forwarding message to handler for node {$nodeId}");
        return self::$handler->sendToNode($nodeId, $data);
    }

    /**
     * Broadcast message to all connected nodes
     */
    public static function broadcast(array $data): void
    {
        if (self::$handler) {
            self::$handler->broadcast($data);
        }
    }
}
