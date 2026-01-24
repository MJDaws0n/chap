<?php

namespace Chap\Models;

use Chap\App;
use Chap\Models\NodePortRange;
use Chap\Services\NotificationService;

/**
 * Node Model (Server/Node that runs deployments)
 */
class Node extends BaseModel
{
    protected static string $table = 'nodes';
    protected static array $fillable = [
        'team_id', 'name', 'description', 'logs_websocket_url', 'api_url', 'token', 'status', 
        'agent_version', 'docker_version', 'os_info', 'cpu_cores',
        'memory_total', 'disk_total', 'last_seen_at', 'settings', 'port_cursor'
    ];
    protected static array $hidden = ['token'];

    public ?int $team_id = null;
    public string $name = '';
    public ?string $description = null;
    public ?string $logs_websocket_url = null;
    public ?string $api_url = null;
    public string $token = '';
    public string $status = 'pending';
    public ?string $agent_version = null;
    public ?string $docker_version = null;
    public ?string $os_info = null;
    public ?int $cpu_cores = null;
    public ?int $memory_total = null;
    public ?int $disk_total = null;
    public ?string $last_seen_at = null;
    public ?string $settings = null;
    public ?int $port_cursor = null;

    /** @return array<int,array{start_port:int,end_port:int}> */
    public function portRanges(): array
    {
        $ranges = NodePortRange::forNode((int)$this->id);
        return array_map(fn($r) => ['start_port' => (int)$r->start_port, 'end_port' => (int)$r->end_port], $ranges);
    }

    /**
     * Get team
     */
    public function team(): ?Team
    {
        // Nodes are treated as global (not owned). Keep this for backwards compatibility.
        return $this->team_id ? Team::find($this->team_id) : null;
    }

    /** @return int[] */
    public static function allIds(): array
    {
        $db = App::db();
        $rows = $db->fetchAll('SELECT id FROM nodes ORDER BY id');
        return array_values(array_map(fn($r) => (int)$r['id'], $rows));
    }

    /**
     * Get containers running on this node
     */
    public function containers(): array
    {
        return Container::where('node_id', $this->id);
    }

    /**
     * Get deployments on this node
     */
    public function deployments(): array
    {
        return Deployment::where('node_id', $this->id);
    }

    /**
     * Generate new token
     */
    public function regenerateToken(): string
    {
        $token = generate_token(32);
        $this->token = $token;
        $this->update(['token' => $token]);
        return $token;
    }

    /**
     * Mark as online
     */
    public function markOnline(): void
    {
        $db = App::db();
        $db->update('nodes', [
            'status' => 'online',
            'last_seen_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$this->id]);
        
        $this->status = 'online';
        $this->last_seen_at = date('Y-m-d H:i:s');

        NotificationService::clearNodeDownAlert($this);
    }

    /**
     * Mark as offline
     */
    public function markOffline(): void
    {
        $db = App::db();
        $db->update('nodes', ['status' => 'offline'], 'id = ?', [$this->id]);
        $this->status = 'offline';
    }

    /**
     * Update system info from agent heartbeat
     */
    public function updateFromHeartbeat(array $info): void
    {
        $db = App::db();
        $data = [
            'status' => 'online',
            'last_seen_at' => date('Y-m-d H:i:s'),
        ];
        
        if (isset($info['agent_version'])) {
            $data['agent_version'] = $info['agent_version'];
            $this->agent_version = $info['agent_version'];
        }
        if (isset($info['docker_version'])) {
            $data['docker_version'] = $info['docker_version'];
            $this->docker_version = $info['docker_version'];
        }
        if (isset($info['os_info'])) {
            $data['os_info'] = $info['os_info'];
            $this->os_info = $info['os_info'];
        }
        if (isset($info['cpu_cores'])) {
            $data['cpu_cores'] = $info['cpu_cores'];
            $this->cpu_cores = $info['cpu_cores'];
        }
        if (isset($info['memory_total'])) {
            $data['memory_total'] = $info['memory_total'];
            $this->memory_total = $info['memory_total'];
        }
        if (isset($info['disk_total'])) {
            $data['disk_total'] = $info['disk_total'];
            $this->disk_total = $info['disk_total'];
        }
        
        $db->update('nodes', $data, 'id = ?', [$this->id]);
        $this->status = 'online';
        $this->last_seen_at = $data['last_seen_at'];

        NotificationService::clearNodeDownAlert($this);
    }

    /**
     * Get system info as array
     */
    public function getSystemInfo(): array
    {
        return [
            'agent_version' => $this->agent_version,
            'docker_version' => $this->docker_version,
            'os_info' => $this->os_info,
            'cpu_cores' => $this->cpu_cores,
            'memory_total' => $this->memory_total,
            'disk_total' => $this->disk_total,
        ];
    }

    /**
     * Check if node is online
     */
    public function isOnline(): bool
    {
        return $this->status === 'online';
    }

    /**
     * Find by token
     */
    public static function findByToken(string $token): ?self
    {
        return self::findBy('token', $token);
    }

    /**
     * Get nodes for team
     */
    public static function forTeam(int $teamId): array
    {
        // Nodes are global; team_id is ignored.
        return self::all();
    }

    /**
     * Get online nodes for team
     */
    public static function onlineForTeam(int $teamId): array
    {
        $db = App::db();
        $results = $db->fetchAll(
            "SELECT * FROM nodes WHERE status = 'online' ORDER BY name"
        );
        
        return array_map(fn($data) => self::fromArray($data), $results);
    }

    /**
     * Get online nodes for team filtered to a set of allowed node IDs.
     *
     * @param int[] $allowedNodeIds
     */
    public static function onlineForTeamAllowed(int $teamId, array $allowedNodeIds): array
    {
        $allowedNodeIds = array_values(array_unique(array_map('intval', $allowedNodeIds)));
        $allowedNodeIds = array_values(array_filter($allowedNodeIds, fn($v) => $v > 0));
        if (empty($allowedNodeIds)) {
            return [];
        }

        $db = App::db();
        $placeholders = implode(',', array_fill(0, count($allowedNodeIds), '?'));
        $params = $allowedNodeIds;

        $results = $db->fetchAll(
            "SELECT * FROM nodes WHERE status = 'online' AND id IN ({$placeholders}) ORDER BY name",
            $params
        );

        return array_map(fn($data) => self::fromArray($data), $results);
    }
}
