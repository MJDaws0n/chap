<?php

namespace Chap\Services\ApiV2;

use Chap\App;
use Chap\Models\ApiToken;

class ApiTokenService
{
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param string[] $scopes
     */
    public static function scopeAllows(array $scopes, string $required): bool
    {
        $required = trim($required);
        if ($required === '') return true;

        $requiredParts = explode(':', $required);

        foreach ($scopes as $scope) {
            $scope = trim((string)$scope);
            if ($scope === '' ) continue;
            if ($scope === '*' || $scope === '*:*') return true;

            $scopeParts = explode(':', $scope);
            $ok = true;
            $n = max(count($scopeParts), count($requiredParts));
            for ($i = 0; $i < $n; $i++) {
                $sp = $scopeParts[$i] ?? null;
                $rp = $requiredParts[$i] ?? null;
                if ($sp === null) {
                    $ok = false;
                    break;
                }
                if ($sp === '*') {
                    // Wildcard matches this segment and any remaining.
                    $ok = true;
                    break;
                }
                if ($rp === null) {
                    // required shorter than scope; accept.
                    $ok = true;
                    break;
                }
                if ($sp !== $rp) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) return true;
        }

        return false;
    }

    /**
     * Constraints are a simple map of {team_id, project_id, environment_id, application_id, node_id}.
     * All present constraints must match the requested IDs.
     *
     * @param array<string,mixed> $constraints
     * @param array<string,string|int|null> $requested
     */
    public static function constraintsAllow(array $constraints, array $requested): bool
    {
        foreach (['team_id','project_id','environment_id','application_id','node_id'] as $k) {
            if (!array_key_exists($k, $constraints)) continue;
            $c = $constraints[$k];
            $r = $requested[$k] ?? null;

            if ($c === null || $c === '') continue;
            if ($r === null || $r === '') return false;

            if ((string)$c !== (string)$r) return false;
        }
        return true;
    }

    public static function rememberIdempotencyResponse(
        int $apiTokenId,
        string $idempotencyKey,
        string $method,
        string $path,
        int $statusCode,
        string $responseBody,
        int $ttlSeconds = 86400
    ): void {
        $db = App::db();
        $db->insert('idempotency_keys', [
            'uuid' => uuid(),
            'api_token_id' => $apiTokenId,
            'idempotency_key' => $idempotencyKey,
            'method' => strtoupper($method),
            'path' => $path,
            'status_code' => $statusCode,
            'response_body' => $responseBody,
            'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
        ]);
    }

    /**
     * @return array{status_code:int,response_body:string}|null
     */
    public static function findIdempotencyResponse(int $apiTokenId, string $idempotencyKey, string $method, string $path): ?array
    {
        $db = App::db();
        $row = $db->fetch(
            "SELECT status_code, response_body FROM idempotency_keys WHERE api_token_id = ? AND idempotency_key = ? AND method = ? AND path = ? AND expires_at > NOW() LIMIT 1",
            [$apiTokenId, $idempotencyKey, strtoupper($method), $path]
        );
        if (!$row) return null;
        return ['status_code' => (int)$row['status_code'], 'response_body' => (string)$row['response_body']];
    }
}
