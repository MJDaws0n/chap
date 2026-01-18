<?php

namespace Chap\Controllers\ApiV2;

use Chap\Models\Node;
use Chap\Services\ApiV2\ApiTokenService;
use Chap\Services\ApiV2\NodeAccessTokenService;

class NodesController extends BaseApiV2Controller
{
    public function index(): void
    {
        $token = $this->apiToken();
        if (!$token) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        if (!ApiTokenService::scopeAllows($token->scopesList(), 'nodes:read')) {
            $this->v2Error('forbidden', 'Token lacks scope: nodes:read', 403);
            return;
        }

        $nodes = Node::all();
        $this->ok([
            'data' => array_map(fn($n) => $this->nodeToClient($n), $nodes),
        ]);
    }

    public function show(string $node_id): void
    {
        $token = $this->apiToken();
        if (!$token) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        if (!ApiTokenService::scopeAllows($token->scopesList(), 'nodes:read')) {
            $this->v2Error('forbidden', 'Token lacks scope: nodes:read', 403);
            return;
        }

        $node = Node::findByUuid($node_id);
        if (!$node) {
            $this->v2Error('not_found', 'Node not found', 404);
            return;
        }

        $this->ok(['data' => $this->nodeToClient($node)]);
    }

    public function mintSession(string $node_id): void
    {
        $token = $this->apiToken();
        if (!$token) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return;
        }
        if (!ApiTokenService::scopeAllows($token->scopesList(), 'nodes:session:mint')) {
            $this->v2Error('forbidden', 'Token lacks scope: nodes:session:mint', 403);
            return;
        }

        $node = Node::findByUuid($node_id);
        if (!$node) {
            $this->v2Error('not_found', 'Node not found', 404);
            return;
        }

        // Constraints: node_id if present.
        if (!ApiTokenService::constraintsAllow($token->constraintsMap(), ['node_id' => (string)$node->uuid])) {
            $this->v2Error('forbidden', 'Token constraints forbid this node', 403);
            return;
        }

        $data = $this->all();
        $reqScopes = $data['scopes'] ?? [];
        $constraints = $data['constraints'] ?? [];
        $ttl = (int)($data['ttl_sec'] ?? 120);

        if (!is_array($reqScopes) || empty($reqScopes)) {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'scopes']);
            return;
        }
        if (!is_array($constraints)) {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'constraints']);
            return;
        }

        // Always bind minted token to this node.
        $constraints['node_id'] = (string)$node->uuid;

        // Require application-scoped tokens for node operations.
        $appId = trim((string)($constraints['application_id'] ?? ''));
        if ($appId === '') {
            $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'constraints.application_id']);
            return;
        }

        // Validate optional filesystem constraints.
        if (array_key_exists('paths', $constraints)) {
            if (!is_array($constraints['paths'])) {
                $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'constraints.paths']);
                return;
            }
            if (count($constraints['paths']) > 50) {
                $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'constraints.paths']);
                return;
            }
            foreach ($constraints['paths'] as $p) {
                $pp = trim((string)$p);
                if ($pp === '' || !str_starts_with($pp, '/')) {
                    $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'constraints.paths']);
                    return;
                }
                if (!(str_starts_with($pp, '/app') || str_starts_with($pp, '/data'))) {
                    $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'constraints.paths']);
                    return;
                }
            }
        }
        if (array_key_exists('max_bytes', $constraints)) {
            $mb = (int)$constraints['max_bytes'];
            if ($mb <= 0 || $mb > 10_000_000) {
                $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'constraints.max_bytes']);
                return;
            }
            $constraints['max_bytes'] = $mb;
        }

        // Ensure requested scopes are allowed by caller token.
        $callerScopes = $token->scopesList();
        foreach ($reqScopes as $s) {
            $ss = trim((string)$s);
            if ($ss === '') {
                $this->v2Error('validation_error', 'Validation error', 422, ['field' => 'scopes']);
                return;
            }
            if (!ApiTokenService::scopeAllows($callerScopes, $ss)) {
                $this->v2Error('forbidden', 'Requested scope not allowed by caller token', 403, ['scope' => $ss]);
                return;
            }
        }

        // Ensure requested constraints do not exceed the caller token's constraints.
        $callerConstraints = $token->constraintsMap();
        if (isset($callerConstraints['team_id']) && $callerConstraints['team_id'] !== null) {
            $constraints['team_id'] = (string)$callerConstraints['team_id'];
        }
        if (isset($callerConstraints['project_id']) && $callerConstraints['project_id'] !== null) {
            $constraints['project_id'] = (string)$callerConstraints['project_id'];
        }
        if (isset($callerConstraints['environment_id']) && $callerConstraints['environment_id'] !== null) {
            $constraints['environment_id'] = (string)$callerConstraints['environment_id'];
        }
        if (isset($callerConstraints['application_id']) && $callerConstraints['application_id'] !== null) {
            if ((string)$callerConstraints['application_id'] !== (string)$constraints['application_id']) {
                $this->v2Error('forbidden', 'Token constraints forbid this application', 403);
                return;
            }
        }
        if (isset($callerConstraints['node_id']) && $callerConstraints['node_id'] !== null) {
            if ((string)$callerConstraints['node_id'] !== (string)$constraints['node_id']) {
                $this->v2Error('forbidden', 'Token constraints forbid this node', 403);
                return;
            }
        }

        $nodeUrl = $this->nodeApiUrl($node);
        if (!$nodeUrl) {
            $this->v2Error('failed_precondition', 'Node is missing api_url (configure in node settings)', 409);
            return;
        }

        try {
            $minted = NodeAccessTokenService::mint((string)$node->uuid, array_values($reqScopes), $constraints, $ttl);
        } catch (\RuntimeException $e) {
            $this->v2Error('server_misconfigured', $e->getMessage(), 503);
            return;
        }

        $this->ok([
            'node_url' => $nodeUrl,
            'node_access_token' => $minted['token'],
            'expires_in' => $minted['expires_in'],
        ]);
    }

    private function nodeApiUrl(Node $node): ?string
    {
        $url = trim((string)($node->api_url ?? ''));
        if ($url !== '') return rtrim($url, '/');

        $ws = trim((string)($node->logs_websocket_url ?? ''));
        if ($ws === '') return null;

        // Best-effort: derive http(s) from ws(s).
        if (str_starts_with($ws, 'wss://')) return 'https://' . substr($ws, strlen('wss://'));
        if (str_starts_with($ws, 'ws://')) return 'http://' . substr($ws, strlen('ws://'));
        return null;
    }

    private function nodeToClient(Node $node): array
    {
        $arr = $node->toArray();
        $arr['node_url'] = $this->nodeApiUrl($node);
        return $arr;
    }
}
