<?php

namespace Chap\Services\ApiV2;

class CursorService
{
    public static function encodeId(?int $id): ?string
    {
        if (!$id || $id <= 0) return null;
        return rtrim(strtr(base64_encode((string)$id), '+/', '-_'), '=');
    }

    public static function decodeId(?string $cursor): ?int
    {
        $c = trim((string)$cursor);
        if ($c === '') return null;
        $c = strtr($c, '-_', '+/');
        $pad = strlen($c) % 4;
        if ($pad) $c .= str_repeat('=', 4 - $pad);
        $raw = base64_decode($c);
        if ($raw === false) return null;
        $n = (int)$raw;
        return $n > 0 ? $n : null;
    }

    public static function parseLimit(mixed $limit, int $default = 50, int $max = 200): int
    {
        $n = (int)$limit;
        if ($n <= 0) $n = $default;
        return max(1, min($max, $n));
    }
}
