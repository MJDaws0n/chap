<?php

namespace Chap\Services;

/**
 * GitHub App manifest flow.
 *
 * This uses GitHub's "Create from manifest" flow to auto-create an app and
 * exchange the callback `code` for the app credentials (App ID + PEM).
 */
class GitHubAppManifestService
{
    public static function buildManifest(string $appName, string $appUrl, string $redirectUrl): array
    {
        $appUrl = rtrim($appUrl, '/');

        return [
            'name' => $appName,
            'url' => $appUrl,
            'redirect_url' => $redirectUrl,
            'public' => false,
            'default_permissions' => [
                'contents' => 'read',
                'metadata' => 'read',
            ],
            'default_events' => [],
        ];
    }

    /**
     * Convert manifest code to an app.
     *
     * @return array<string, mixed>
     */
    public static function convert(string $code): array
    {
        if ($code === '') {
            throw new \InvalidArgumentException('Missing manifest conversion code');
        }

        $url = 'https://api.github.com/app-manifests/' . rawurlencode($code) . '/conversions';
        $headers = [
            'User-Agent: Chap',
            'Accept: application/vnd.github+json',
        ];

        $resp = self::curlJson('POST', $url, $headers, '{}');
        return is_array($resp) ? $resp : [];
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
