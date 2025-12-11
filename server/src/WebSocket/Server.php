<?php

namespace Chap\WebSocket;

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
            error_log("[WebSocket\\Server] No handler set, cannot send to node {$nodeId}");
            return false;
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
