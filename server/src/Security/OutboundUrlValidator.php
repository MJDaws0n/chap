<?php

namespace Chap\Security;

final class OutboundUrlValidator
{
    /**
     * Validates an outbound webhook URL with SSRF resistance.
     *
     * This is best-effort: DNS can change after validation.
     *
     * @return array{valid:bool,error?:string}
     */
    public static function validateWebhookUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['valid' => false, 'error' => 'Webhook URL is required'];
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['valid' => false, 'error' => 'Webhook URL must be a valid URL'];
        }

        $parts = @parse_url($url);
        if (!is_array($parts)) {
            return ['valid' => false, 'error' => 'Webhook URL must be a valid URL'];
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ['valid' => false, 'error' => 'Webhook URL must start with http:// or https://'];
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return ['valid' => false, 'error' => 'Webhook URL must not include credentials'];
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '') {
            return ['valid' => false, 'error' => 'Webhook URL must include a host'];
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return ['valid' => false, 'error' => 'Webhook URL host is not allowed'];
        }

        if (isset($parts['port'])) {
            $port = (int)$parts['port'];
            if ($port <= 0 || $port > 65535) {
                return ['valid' => false, 'error' => 'Webhook URL port is invalid'];
            }
        }

        // If host is an IP, block private/reserved ranges.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return ['valid' => false, 'error' => 'Webhook URL host is not allowed'];
            }
            return ['valid' => true];
        }

        // Best-effort DNS resolution: if it resolves to any private/reserved IP, reject.
        $ips = [];

        if (function_exists('dns_get_record')) {
            $a = @dns_get_record($host, DNS_A);
            if (is_array($a)) {
                foreach ($a as $rec) {
                    $ip = $rec['ip'] ?? null;
                    if (is_string($ip) && $ip !== '') $ips[] = $ip;
                }
            }

            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $rec) {
                    $ip = $rec['ipv6'] ?? null;
                    if (is_string($ip) && $ip !== '') $ips[] = $ip;
                }
            }
        }

        // Fallback for A records.
        if (empty($ips) && function_exists('gethostbyname')) {
            $ip = @gethostbyname($host);
            if (is_string($ip) && $ip !== '' && $ip !== $host) {
                $ips[] = $ip;
            }
        }

        foreach ($ips as $ip) {
            $ip = trim((string)$ip);
            if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return ['valid' => false, 'error' => 'Webhook URL host is not allowed'];
            }
        }

        return ['valid' => true];
    }
}
