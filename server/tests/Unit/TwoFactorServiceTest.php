<?php

use Chap\Security\TwoFactorService;

final class TwoFactorServiceTest extends \PHPUnit\Framework\TestCase
{
    public function testGeneratedSecretIsBase32(): void
    {
        $secret = TwoFactorService::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertGreaterThanOrEqual(16, strlen($secret));
    }

    public function testVerifyCodeAcceptsCorrectTotp(): void
    {
        // RFC 6238 test vector secret (Base32 for "12345678901234567890")
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        // At T=59, 30s period => timestep=1, expected HOTP value 94287082 (8 digits).
        // For 6 digits, it's 287082.
        $this->assertTrue(TwoFactorService::verifyCode($secret, '287082', 0, 59));
        $this->assertFalse(TwoFactorService::verifyCode($secret, '000000', 0, 59));
    }
}
