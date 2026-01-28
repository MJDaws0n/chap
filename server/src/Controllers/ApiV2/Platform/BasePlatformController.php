<?php

namespace Chap\Controllers\ApiV2\Platform;

use Chap\Controllers\ApiV2\BaseApiV2Controller;
use Chap\Models\PlatformApiKey;
use Chap\Services\ApiV2\ApiTokenService;

abstract class BasePlatformController extends BaseApiV2Controller
{
    protected function platformKey(): ?PlatformApiKey
    {
        $k = $GLOBALS['chap_platform_api_key'] ?? null;
        return $k instanceof PlatformApiKey ? $k : null;
    }

    protected function requirePlatformScope(string $scope): ?PlatformApiKey
    {
        $key = $this->platformKey();
        if (!$key) {
            $this->v2Error('unauthorized', 'Unauthorized', 401);
            return null;
        }
        if (!ApiTokenService::scopeAllows($key->scopesList(), $scope)) {
            $this->v2Error('forbidden', 'Token lacks scope: ' . $scope, 403);
            return null;
        }
        return $key;
    }

    /**
     * @param array<string,string|int|null> $requested
     */
    protected function requirePlatformConstraints(PlatformApiKey $key, array $requested): bool
    {
        if (!ApiTokenService::constraintsAllow($key->constraintsMap(), $requested)) {
            $this->v2Error('forbidden', 'Token constraints forbid this request', 403);
            return false;
        }
        return true;
    }
}
