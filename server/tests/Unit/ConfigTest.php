<?php
/**
 * Unit Tests: Config Class
 * 
 * Tests for the Config class
 */

namespace Tests\Unit;

use Tests\TestCase;
use Chap\Config;

class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset config state
        Config::load();
    }
    
    /**
     * Test config loading
     */
    public function testConfigLoads(): void
    {
        Config::load();
        
        // APP_ENV should be set from bootstrap
        $this->assertEquals('testing', Config::get('APP_ENV'));
    }
    
    /**
     * Test getting config values
     */
    public function testGetConfigValue(): void
    {
        // Set a test value
        putenv('TEST_KEY=test_value');
        Config::load();
        
        $this->assertEquals('test_value', Config::get('TEST_KEY'));
    }
    
    /**
     * Test default values
     */
    public function testDefaultValues(): void
    {
        $this->assertEquals('default', Config::get('NON_EXISTENT_KEY', 'default'));
        $this->assertNull(Config::get('NON_EXISTENT_KEY'));
    }
    
    /**
     * Test setting config values
     */
    public function testSetConfigValue(): void
    {
        Config::set('CUSTOM_KEY', 'custom_value');
        $this->assertEquals('custom_value', Config::get('CUSTOM_KEY'));
    }
    
    /**
     * Test getting all config
     */
    public function testGetAllConfig(): void
    {
        $all = Config::all();
        
        $this->assertIsArray($all);
        $this->assertArrayHasKey('APP_ENV', $all);
    }
}
