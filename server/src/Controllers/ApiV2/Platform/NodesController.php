<?php

namespace Chap\Controllers\ApiV2\Platform;

use Chap\Models\Node;

class NodesController extends BasePlatformController
{
    public function index(): void
    {
        $key = $this->requirePlatformScope('nodes:read');
        if (!$key) return;

        $nodes = Node::all();
        $this->ok([
            'data' => array_map(fn($n) => $this->nodeToClient($n), $nodes),
        ]);
    }

    public function show(string $node_id): void
    {
        $key = $this->requirePlatformScope('nodes:read');
        if (!$key) return;

        $node = Node::findByUuid($node_id);
        if (!$node) {
            $this->v2Error('not_found', 'Node not found', 404);
            return;
        }

        if (!$this->requirePlatformConstraints($key, ['node_id' => (string)$node->uuid])) return;

        $this->ok(['data' => $this->nodeToClient($node)]);
    }

    private function nodeApiUrl(Node $node): ?string
    {
        $url = trim((string)($node->api_url ?? ''));
        if ($url !== '') return rtrim($url, '/');

        $ws = trim((string)($node->logs_websocket_url ?? ''));
        if ($ws === '') return null;

        if (str_starts_with($ws, 'wss://')) return 'https://' . substr($ws, strlen('wss://'));
        if (str_starts_with($ws, 'ws://')) return 'http://' . substr($ws, strlen('ws://'));
        return null;
    }

    private function nodeToClient(Node $node): array
    {
        $arr = $node->toArray();
        $arr['internal_id'] = $arr['id'] ?? null;
        $arr['id'] = (string)$node->uuid;
        $arr['uuid'] = (string)$node->uuid;
        $arr['node_url'] = $this->nodeApiUrl($node);
        return $arr;
    }
}
