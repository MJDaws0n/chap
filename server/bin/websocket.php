#!/usr/bin/env php
<?php
/**
 * Chap WebSocket Server
 * 
 * Handles communication with Chap nodes
 */

require __DIR__ . '/../vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Chap\WebSocket\NodeHandler;
use Chap\App;
use Chap\Config;
use React\EventLoop\Loop;

// Load configuration and boot app
Config::load();
$app = new App();
$app->boot();

$port = config('websocket.port', 8081);

echo "Starting Chap WebSocket Server on port {$port}...\n";

// Create the handler
$handler = new NodeHandler();

$server = IoServer::factory(
    new HttpServer(
        new WsServer($handler)
    ),
    $port
);

// Get the event loop
$loop = $server->loop;

// Add periodic timer to check for pending tasks very frequently (100ms) for low-latency logs
$loop->addPeriodicTimer(0.1, function () use ($handler) {
    $handler->checkAndSendPendingTasks();
});

echo "WebSocket server running at ws://0.0.0.0:{$port}\n";
echo "Task polling enabled (checks every 100ms)\n";

$server->run();
