<?php

namespace Chap\Security\Captcha;

use Chap\Config;

class CaptchaVerifier
{
    public static function isEnabled(): bool
    {
        $provider = (string)Config::get('captcha.provider', 'none');
        return in_array($provider, ['recaptcha', 'autogate'], true);
    }

    /**
     * Verify a human check for the current request.
     *
     * @param array $post Typically $_POST
     */
    public static function verify(array $post, ?string $remoteIp = null): bool
    {
        $provider = (string)Config::get('captcha.provider', 'none');

        if ($provider === 'none' || $provider === '') {
            return true;
        }

        if ($provider === 'recaptcha') {
            $secret = (string)Config::get('captcha.recaptcha.secret_key', '');
            $token = (string)($post['g-recaptcha-response'] ?? '');
            if ($secret === '' || $token === '') {
                return false;
            }

            return self::verifyRecaptcha($secret, $token, $remoteIp);
        }

        if ($provider === 'autogate') {
            $privateKey = (string)Config::get('captcha.autogate.private_key', '');
            $token = (string)($post['captcha_token'] ?? '');
            if ($privateKey === '' || $token === '') {
                return false;
            }

            return self::verifyAutogate($privateKey, $token);
        }

        // Unknown provider: fail closed.
        return false;
    }

    private static function verifyRecaptcha(string $secret, string $token, ?string $remoteIp): bool
    {
        $endpoint = 'https://www.google.com/recaptcha/api/siteverify';

        $fields = [
            'secret' => $secret,
            'response' => $token,
        ];
        if (!empty($remoteIp)) {
            $fields['remoteip'] = $remoteIp;
        }

        $response = self::postForm($endpoint, $fields);
        if (!$response) {
            return false;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) && !empty($decoded['success']);
    }

    private static function verifyAutogate(string $privateKey, string $token): bool
    {
        $endpoint = 'https://autogate.mjdawson.net/api/validate';

        $response = self::postForm($endpoint, [
            'private_key' => $privateKey,
            'token' => $token,
        ]);

        if (!$response) {
            return false;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) && !empty($decoded['valid']);
    }

    private static function postForm(string $url, array $fields): ?string
    {
        $ch = curl_init();
        if ($ch === false) {
            return null;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $err) {
            return null;
        }

        if ($status < 200 || $status >= 300) {
            return null;
        }

        return is_string($body) ? $body : null;
    }
}
