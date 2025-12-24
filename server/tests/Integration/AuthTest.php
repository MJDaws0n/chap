<?php
/**
 * Integration Tests: Authentication
 * 
 * Tests for authentication functionality
 */

namespace Tests\Integration;

use Tests\TestCase;
use Chap\Auth\AuthManager;
use Chap\Models\User;

class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear any existing session
        $_SESSION = [];
    }
    
    /**
     * Test successful login attempt
     */
    public function testSuccessfulLogin(): void
    {
        $result = AuthManager::attempt('max@chap.dev', 'password');
        
        $this->assertTrue($result, 'Should login with correct credentials');
        $this->assertTrue(AuthManager::check(), 'Should be authenticated after login');
    }
    
    /**
     * Test failed login with wrong password
     */
    public function testFailedLoginWrongPassword(): void
    {
        $result = AuthManager::attempt('max@chap.dev', 'wrong_password');
        
        $this->assertFalse($result, 'Should not login with wrong password');
        $this->assertFalse(AuthManager::check(), 'Should not be authenticated');
    }
    
    /**
     * Test failed login with non-existent user
     */
    public function testFailedLoginNoUser(): void
    {
        $result = AuthManager::attempt('nonexistent@example.com', 'password');
        
        $this->assertFalse($result, 'Should not login with non-existent user');
    }
    
    /**
     * Test getting current user
     */
    public function testGetCurrentUser(): void
    {
        AuthManager::attempt('max@chap.dev', 'password');
        
        $user = AuthManager::user();
        $this->assertNotNull($user);
        $this->assertEquals('max@chap.dev', $user->email);
    }
    
    /**
     * Test logout
     */
    public function testLogout(): void
    {
        AuthManager::attempt('max@chap.dev', 'password');
        $this->assertTrue(AuthManager::check());
        
        AuthManager::logout();
        
        $this->assertFalse(AuthManager::check(), 'Should not be authenticated after logout');
        $this->assertNull(AuthManager::user(), 'Should not have user after logout');
    }
    
    /**
     * Test user ID in session
     */
    public function testUserIdInSession(): void
    {
        AuthManager::attempt('max@chap.dev', 'password');
        
        $this->assertArrayHasKey('user_id', $_SESSION);
        $this->assertIsInt($_SESSION['user_id']);
    }
    
    /**
     * Test password hashing
     */
    public function testPasswordHashing(): void
    {
        $password = 'test_password_123';
        $hash = password_hash($password, PASSWORD_BCRYPT);
        
        $this->assertTrue(password_verify($password, $hash));
        $this->assertFalse(password_verify('wrong', $hash));
    }
}
