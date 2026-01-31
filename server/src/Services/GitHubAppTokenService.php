<?php

namespace Chap\Services;

/**
 * GitHub App token minting
 *
 * Creates an App JWT and exchanges it for an installation access token.
 */
class GitHubAppTokenService
{
    public static function createAppJwt(int $appId, string $privateKeyPem, int $ttlSeconds = 540): string
    {
        $now = time();

        // GitHub requires iat to be within 60s of now, exp <= 10 minutes
        $payload = [
            'iat' => $now - 5,
            'exp' => $now + max(60, min($ttlSeconds, 600)),
            'iss' => $appId,
        ];

        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];

        $signingInput = implode('.', $segments);

        $pkey = openssl_pkey_get_private($privateKeyPem);
        if ($pkey === false) {
            throw new \RuntimeException('Invalid GitHub App private key');
        }

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $pkey, OPENSSL_ALGO_SHA256);
        if (!$ok) {
            throw new \RuntimeException('Failed to sign GitHub App JWT');
        }

        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * @return array{token:string, expires_at:?string}
     */
    public static function mintInstallationToken(int $appId, string $privateKeyPem, int $installationId): array
    {
        $jwt = self::createAppJwt($appId, $privateKeyPem);

        $url = 'https://api.github.com/app/installations/' . rawurlencode((string)$installationId) . '/access_tokens';
        $headers = [
            'User-Agent: Chap',
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $jwt,
        ];

        $resp = self::curlJson('POST', $url, $headers, '{}');

        if (!is_array($resp) || empty($resp['token'])) {
            $msg = is_array($resp) && isset($resp['message']) ? (string)$resp['message'] : 'Failed to mint installation token';
            throw new \RuntimeException($msg);
        }

        return [
            'token' => (string)$resp['token'],
            'expires_at' => isset($resp['expires_at']) ? (string)$resp['expires_at'] : null,
        ];
    }

    /**
     * Get metadata about the GitHub App itself (e.g. slug, html_url).
     */
    public static function getApp(int $appId, string $privateKeyPem): array
    {
        $jwt = self::createAppJwt($appId, $privateKeyPem);

        $url = 'https://api.github.com/app';
        $headers = [
            'User-Agent: Chap',
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $jwt,
        ];

        $resp = self::curlJson('GET', $url, $headers, null);
        return is_array($resp) ? $resp : [];
    }

    /**
     * List installations for the app.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listInstallations(int $appId, string $privateKeyPem): array
    {
        $jwt = self::createAppJwt($appId, $privateKeyPem);

        $url = 'https://api.github.com/app/installations';
        $headers = [
            'User-Agent: Chap',
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $jwt,
        ];

        $resp = self::curlJson('GET', $url, $headers, null);
        if (is_array($resp) && isset($resp['installations']) && is_array($resp['installations'])) {
            return $resp['installations'];
        }

        // Sometimes the API may return an array directly.
        if (is_array($resp) && array_is_list($resp)) {
            return $resp;
        }

        return [];
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function curlJson(string $method, string $url, array $headers, ?string $body): mixed
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL is required for GitHub App API requests');
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Prevent SSRF via redirects; GitHub endpoints should not require following.
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $method = strtoupper($method);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('GitHub request failed: ' . $err);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($resp, true);
        if ($status < 200 || $status >= 300) {
            if (is_array($decoded) && isset($decoded['message'])) {
                throw new \RuntimeException('GitHub API error: ' . (string)$decoded['message']);
            }
            throw new \RuntimeException('GitHub API error (HTTP ' . $status . ')');
        }

        return $decoded;
    }
}
