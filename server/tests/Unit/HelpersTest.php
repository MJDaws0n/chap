<?php
/**
 * Unit Tests: Helper Functions
 * 
 * Tests for the global helper functions in src/Helpers/functions.php
 */

namespace Tests\Unit;

use Tests\TestCase;
use Chap\Config;

class HelpersTest extends TestCase
{
    /**
     * Test UUID generation
     */
    public function testUuidGeneration(): void
    {
        $uuid1 = uuid();
        $uuid2 = uuid();
        
        // Should be valid UUID format
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid1,
            'UUID should be valid v4 format'
        );
        
        // Should be unique
        $this->assertNotEquals($uuid1, $uuid2, 'UUIDs should be unique');
    }
    
    /**
     * Test token generation
     */
    public function testTokenGeneration(): void
    {
        $token16 = generate_token(16);
        $token32 = generate_token(32);
        
        // Check lengths (hex encoding doubles the byte length)
        $this->assertEquals(32, strlen($token16), 'Token should be 32 hex chars for 16 bytes');
        $this->assertEquals(64, strlen($token32), 'Token should be 64 hex chars for 32 bytes');
        
        // Should only contain hex characters
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/i', $token16);
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/i', $token32);
        
        // Should be unique
        $this->assertNotEquals($token16, generate_token(16));
    }
    
    /**
     * Test CSRF token generation and verification
     */
    public function testCsrfToken(): void
    {
        // Generate token
        $token = csrf_token();
        
        // Should be stored in session
        $this->assertNotEmpty($_SESSION['csrf_token'] ?? null, 'CSRF token should be in session');
        
        // Same token should be returned on subsequent calls
        $this->assertEquals($token, csrf_token(), 'Same CSRF token should be returned');
        
        // Verification should work
        $this->assertTrue(verify_csrf($token), 'Valid CSRF token should verify');
        $this->assertFalse(verify_csrf('invalid_token'), 'Invalid CSRF token should not verify');
        $this->assertFalse(verify_csrf(''), 'Empty CSRF token should not verify');
    }
    
    /**
     * Test flash messages
     */
    public function testFlashMessages(): void
    {
        // Set a flash message
        flash('success', 'Test message');
        
        // Should be in session
        $this->assertEquals('Test message', $_SESSION['_flash']['success'] ?? null);
        
        // Get and clear (flash() with no args returns and clears all)
        $messages = flash();
        $this->assertEquals('Test message', $messages['success'] ?? null);
        
        // Should be cleared after getting
        $this->assertEmpty($_SESSION['_flash'] ?? []);
    }
    
    /**
     * Test config function
     */
    public function testConfigFunction(): void
    {
        // Set env var used by Config
        putenv('APP_NAME=Config Test');
        $_ENV['APP_NAME'] = 'Config Test';
        Config::reload();

        $this->assertEquals('Config Test', config('app.name'));

        // Should return default for missing
        $this->assertEquals('default', config('app.missing', 'default'));
        $this->assertNull(config('app.missing'));
    }
    
    /**
     * Test e() escape function
     */
    public function testEscapeHtml(): void
    {
        $this->assertEquals('&lt;script&gt;', e('<script>'));
        $this->assertEquals('Hello &amp; World', e('Hello & World'));
        $this->assertEquals('', e(''));
        $this->assertEquals('normal text', e('normal text'));
    }
    
    /**
     * Test slug generation
     */
    public function testSlugGeneration(): void
    {
        $this->assertEquals('hello-world', slug('Hello World'));
        $this->assertEquals('test-123', slug('Test 123'));
        $this->assertEquals('special-chars', slug('Special @#$% Chars!'));
        $this->assertEquals('multiple-spaces', slug('Multiple    Spaces'));
        $this->assertEquals('', slug(''));
    }
}
