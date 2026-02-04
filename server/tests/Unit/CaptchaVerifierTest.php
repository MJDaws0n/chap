<?php
/**
 * Unit Tests: CaptchaVerifier
 * 
 * Tests for the CaptchaVerifier security class
 */

namespace Tests\Unit;

use Tests\TestCase;
use Chap\Security\Captcha\CaptchaVerifier;
use Chap\Config;

class CaptchaVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::reset();
    }
    
    protected function tearDown(): void
    {
        parent::tearDown();
        Config::reset();
    }
    
    /**
     * Test that verification passes when captcha is disabled (provider = none)
     */
    public function testVerifyPassesWhenDisabled(): void
    {
        Config::set('captcha.provider', 'none');
        
        // Empty POST data should pass when captcha is disabled
        $result = CaptchaVerifier::verify([], null);
        $this->assertTrue($result);
        
        // Any POST data should pass
        $result = CaptchaVerifier::verify(['foo' => 'bar'], '127.0.0.1');
        $this->assertTrue($result);
    }
    
    /**
     * Test that verification fails when reCAPTCHA is enabled but no token provided
     */
    public function testVerifyFailsWithRecaptchaNoToken(): void
    {
        Config::set('captcha.provider', 'recaptcha');
        Config::set('captcha.recaptcha.site_key', 'test-site-key');
        Config::set('captcha.recaptcha.secret_key', 'test-secret-key');
        
        // No token should fail
        $result = CaptchaVerifier::verify([], null);
        $this->assertFalse($result);
        
        // Empty token should fail
        $result = CaptchaVerifier::verify(['g-recaptcha-response' => ''], null);
        $this->assertFalse($result);
    }
    
    /**
     * Test that verification fails when reCAPTCHA secret is not configured
     */
    public function testVerifyFailsWithRecaptchaNoSecret(): void
    {
        Config::set('captcha.provider', 'recaptcha');
        Config::set('captcha.recaptcha.site_key', 'test-site-key');
        Config::set('captcha.recaptcha.secret_key', ''); // No secret
        
        $result = CaptchaVerifier::verify(['g-recaptcha-response' => 'some-token'], null);
        $this->assertFalse($result);
    }
    
    /**
     * Test that verification fails when Autogate is enabled but no token provided
     */
    public function testVerifyFailsWithAutogateNoToken(): void
    {
        Config::set('captcha.provider', 'autogate');
        Config::set('captcha.autogate.public_key', 'test-public-key');
        Config::set('captcha.autogate.private_key', 'test-private-key');
        
        // No token should fail
        $result = CaptchaVerifier::verify([], null);
        $this->assertFalse($result);
        
        // Empty token should fail
        $result = CaptchaVerifier::verify(['captcha_token' => ''], null);
        $this->assertFalse($result);
    }
    
    /**
     * Test that verification fails when Autogate private key is not configured
     */
    public function testVerifyFailsWithAutogateNoPrivateKey(): void
    {
        Config::set('captcha.provider', 'autogate');
        Config::set('captcha.autogate.public_key', 'test-public-key');
        Config::set('captcha.autogate.private_key', ''); // No private key
        
        $result = CaptchaVerifier::verify(['captcha_token' => 'some-token'], null);
        $this->assertFalse($result);
    }
    
    /**
     * Test that isEnabled returns correct values
     */
    public function testIsEnabled(): void
    {
        Config::set('captcha.provider', 'none');
        $this->assertFalse(CaptchaVerifier::isEnabled());
        
        Config::set('captcha.provider', '');
        $this->assertFalse(CaptchaVerifier::isEnabled());
        
        Config::set('captcha.provider', 'recaptcha');
        $this->assertTrue(CaptchaVerifier::isEnabled());
        
        Config::set('captcha.provider', 'autogate');
        $this->assertTrue(CaptchaVerifier::isEnabled());
        
        // Unknown provider should not be enabled
        Config::set('captcha.provider', 'unknown');
        $this->assertFalse(CaptchaVerifier::isEnabled());
    }
    
    /**
     * Test that unknown provider fails closed (returns false)
     */
    public function testUnknownProviderFailsClosed(): void
    {
        Config::set('captcha.provider', 'unknown_provider');
        
        $result = CaptchaVerifier::verify(['some_token' => 'value'], '127.0.0.1');
        $this->assertFalse($result);
    }
}
