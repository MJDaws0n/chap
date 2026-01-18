<?php

namespace Chap\Security;

use Chap\Config;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class TwoFactorService
{
    public const DIGITS = 6;
    public const PERIOD = 30;

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function provisioningUri(string $accountName, string $secret, ?string $issuer = null): string
    {
        $issuer = $issuer ?: (string)Config::get('app.name', 'Chap');

        // otpauth://totp/Issuer:account?secret=...&issuer=...
        $label = rawurlencode($issuer . ':' . $accountName);
        $issuerParam = rawurlencode($issuer);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $label,
            rawurlencode($secret),
            $issuerParam,
            self::DIGITS,
            self::PERIOD
        );
    }

    /**
     * Returns a PNG data URI that can be used directly in an <img src="...">.
     */
    public static function qrCodeDataUri(string $text, int $size = 220): string
    {
        $qrCode = new QrCode($text);
        $qrCode = $qrCode->setSize($size);

        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        return $result->getDataUri();
    }

    public static function verifyCode(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $timestamp = $timestamp ?? time();
        $code = preg_replace('/\s+/', '', $code);

        if (!is_string($code) || $code === '' || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $timeStep = (int)floor($timestamp / self::PERIOD);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $expected = self::totp($secret, $timeStep + $offset);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    private static function totp(string $secret, int $timeStep): string
    {
        $key = self::base32Decode($secret);
        $counter = pack('N*', 0) . pack('N*', $timeStep);
        $hash = hash_hmac('sha1', $counter, $key, true);

        $offset = ord($hash[19]) & 0x0f;
        $binary = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        );

        $otp = $binary % (10 ** self::DIGITS);
        return str_pad((string)$otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($bits, 5);
        $encoded = '';
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= $alphabet[bindec($chunk)];
        }

        // No padding for OTP secrets.
        return $encoded;
    }

    private static function base32Decode(string $encoded): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $encoded = strtoupper(preg_replace('/[^A-Z2-7]/', '', $encoded));

        $bits = '';
        for ($i = 0; $i < strlen($encoded); $i++) {
            $val = strpos($alphabet, $encoded[$i]);
            if ($val === false) {
                continue;
            }
            $bits .= str_pad(decbin($val), 5, '0', STR_PAD_LEFT);
        }

        $bytes = str_split($bits, 8);
        $decoded = '';
        foreach ($bytes as $byte) {
            if (strlen($byte) < 8) {
                continue;
            }
            $decoded .= chr(bindec($byte));
        }

        return $decoded;
    }
}
