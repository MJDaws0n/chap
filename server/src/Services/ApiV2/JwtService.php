<?php

namespace Chap\Services\ApiV2;

class JwtService
{
    private static function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode($data) ?: '';
    }

    /**
     * @param array<string,mixed> $claims
     */
    public static function signHs256(array $claims, string $secret, array $header = []): string
    {
        $hdr = array_merge(['typ' => 'JWT', 'alg' => 'HS256'], $header);
        $h = self::b64urlEncode(json_encode($hdr));
        $p = self::b64urlEncode(json_encode($claims));
        $sig = hash_hmac('sha256', $h . '.' . $p, $secret, true);
        $s = self::b64urlEncode($sig);
        return $h . '.' . $p . '.' . $s;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function verifyHs256(string $jwt, string $secret): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return null;
        [$h, $p, $s] = $parts;
        $sig = self::b64urlDecode($s);
        $expected = hash_hmac('sha256', $h . '.' . $p, $secret, true);
        if (!hash_equals($expected, $sig)) return null;
        $payload = json_decode(self::b64urlDecode($p), true);
        return is_array($payload) ? $payload : null;
    }
}
