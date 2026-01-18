<?php

namespace Chap\Services\ApiV2;

use Chap\Config;

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

        $secret = (string)(getenv('CHAP_NODE_ACCESS_TOKEN_SECRET') ?: Config::get('app.secret', ''));
        if ($secret === '') {
            // Fail closed.
            throw new \RuntimeException('Server is missing CHAP_NODE_ACCESS_TOKEN_SECRET');
        }

        $jwt = JwtService::signHs256($claims, $secret);

        return ['token' => $jwt, 'expires_in' => $ttlSec];
    }
}
