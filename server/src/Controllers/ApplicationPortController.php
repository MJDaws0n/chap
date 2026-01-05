<?php

namespace Chap\Controllers;

use Chap\Models\Application;
use Chap\Models\Environment;
use Chap\Models\Node;
use Chap\Models\PortAllocation;
use Chap\Services\PortAllocator;
use Chap\Services\NodeAccess;
use Chap\WebSocket\Server as WebSocketServer;

class ApplicationPortController extends BaseController
{
    /**
     * Allocate a new port for an existing application
     */
    public function allocate(string $appUuid): void
    {
        $team = $this->currentTeam();
        $application = Application::findByUuid($appUuid);

        if (!$application || !$this->canAccessApplication($application, $team)) {
            $this->json(['error' => 'Application not found'], 404);
            return;
        }

        if (!$application->node_id) {
            $this->json(['error' => 'Application has no node assigned'], 422);
            return;
        }

        $node = Node::find((int)$application->node_id);
        if (!$node || !$node->isOnline()) {
            $this->json(['error' => 'Node is offline'], 422);
            return;
        }

        try {
            $port = PortAllocator::allocateForApplication((int)$application->id, (int)$node->id, function(int $nodeId, int $port) {
                return $this->checkPortOnNode($nodeId, $port);
            });
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }

        $this->json([
            'port' => $port,
            'ports' => PortAllocation::portsForApplication((int)$application->id),
        ]);
    }

    /**
     * Allocate a new port during application creation (reservation-based).
     */
    public function allocateForReservation(string $envUuid, string $nodeUuid): void
    {
        $team = $this->currentTeam();

        $environment = Environment::findByUuid($envUuid);
        if (!$environment) {
            $this->json(['error' => 'Environment not found'], 404);
            return;
        }
        $project = $environment->project();
        if (!$project || !$this->canAccessTeamId((int)$project->team_id)) {
            $this->json(['error' => 'Environment not found'], 404);
            return;
        }

        $node = Node::findByUuid($nodeUuid);
        if (!$node) {
            $this->json(['error' => 'Node not found'], 404);
            return;
        }

        $allowedNodeIds = $this->user ? NodeAccess::allowedNodeIds($this->user, $team, $project, $environment) : [];
        if (!in_array((int)$node->id, $allowedNodeIds, true)) {
            $this->json(['error' => 'You do not have access to this node'], 403);
            return;
        }
        if (!$node->isOnline()) {
            $this->json(['error' => 'Node is offline'], 422);
            return;
        }

        $reservationUuid = (string)($this->input('reservation_uuid', ''));
        if ($reservationUuid === '') {
            $this->json(['error' => 'Missing reservation_uuid'], 422);
            return;
        }

        try {
            $port = PortAllocator::allocateForReservation($reservationUuid, (int)$node->id, function(int $nodeId, int $port) {
                return $this->checkPortOnNode($nodeId, $port);
            });
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
            return;
        }

        $this->json([
            'port' => $port,
            'ports' => PortAllocation::portsForReservation($reservationUuid, (int)$node->id),
        ]);
    }

    /**
     * Release reserved ports (e.g. user switched node on create flow).
     */
    public function releaseReservation(string $envUuid, string $nodeUuid): void
    {
        $team = $this->currentTeam();

        $environment = Environment::findByUuid($envUuid);
        if (!$environment) {
            $this->json(['error' => 'Environment not found'], 404);
            return;
        }
        $project = $environment->project();
        if (!$project || !$this->canAccessTeamId((int)$project->team_id)) {
            $this->json(['error' => 'Environment not found'], 404);
            return;
        }

        $node = Node::findByUuid($nodeUuid);
        if (!$node) {
            $this->json(['error' => 'Node not found'], 404);
            return;
        }

        $allowedNodeIds = $this->user ? NodeAccess::allowedNodeIds($this->user, $team, $project, $environment) : [];
        if (!in_array((int)$node->id, $allowedNodeIds, true)) {
            $this->json(['error' => 'You do not have access to this node'], 403);
            return;
        }

        $reservationUuid = (string)($this->input('reservation_uuid', ''));
        if ($reservationUuid === '') {
            $this->json(['error' => 'Missing reservation_uuid'], 422);
            return;
        }

        PortAllocator::releaseReservation($reservationUuid, (int)$node->id);
        $this->json(['ok' => true]);
    }

    private function checkPortOnNode(int $nodeId, int $port): bool
    {
        $node = Node::find($nodeId);
        if (!$node) {
            throw new \RuntimeException('Node not found');
        }
        if (!$node->isOnline()) {
            throw new \RuntimeException('Node is offline');
        }

        $requestId = uuid();
        $cacheFile = "/tmp/port_check_{$node->uuid}_{$requestId}.json";
        @unlink($cacheFile);

        $msg = [
            'type' => 'port:check',
            'payload' => [
                'request_id' => $requestId,
                'port' => $port,
            ],
        ];

        try {
            WebSocketServer::sendToNode($nodeId, $msg);
        } catch (\Throwable $e) {
            // If ws isn't available, we can't verify.
            throw new \RuntimeException('Unable to verify port availability on node');
        }

        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            if (file_exists($cacheFile)) {
                $raw = @file_get_contents($cacheFile);
                @unlink($cacheFile);
                if (!$raw) {
                    break;
                }
                $data = json_decode($raw, true);
                if (is_array($data) && array_key_exists('free', $data)) {
                    return (bool)$data['free'];
                }
                break;
            }
            usleep(100 * 1000);
        }

        throw new \RuntimeException('Unable to verify port availability on node');
    }
}
