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

// Load configuration and boot app
Config::load();
$app = new App();
$app->boot();

$port = config('websocket.port', 8081);

echo "Starting Chap WebSocket Server on port {$port}...\n";

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new NodeHandler()
        )
    ),
    $port
);

echo "WebSocket server running at ws://0.0.0.0:{$port}\n";

$server->run();
