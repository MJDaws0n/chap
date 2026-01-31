<?php

namespace Chap\Services\ApiV2;

use Chap\Models\Node;

class NodeAccessTokenService
{
    /**
     * @param string[] $scopes
     * @param array<string,mixed> $constraints
     */
    public static function mint(string $nodeUuid, array $scopes, array $constraints, int $ttlSec): array
    {
        $ttlSec = max(30, min(600, $ttlSec));
        $now = time();
        $claims = [
            'iss' => 'chap-server',
            'aud' => 'chap-node',
            'sub' => 'nat_' . bin2hex(random_bytes(8)),
            'iat' => $now,
            'exp' => $now + $ttlSec,
            'node_id' => $nodeUuid,
            'scopes' => array_values($scopes),
            'constraints' => (object)$constraints,
        ];

        // Prefer a per-node signing secret to avoid a single shared secret for all nodes.
        // This uses the node's existing token (also used for node<->server auth).
        $node = Node::findByUuid($nodeUuid);
        $secret = $node ? trim((string)($node->token ?? '')) : '';

        if ($secret === '') {
            // Fail closed.
            throw new \RuntimeException('Server is missing node token (required for node access tokens)');
        }

        $jwt = JwtService::signHs256($claims, $secret);

        return ['token' => $jwt, 'expires_in' => $ttlSec];
    }
}
