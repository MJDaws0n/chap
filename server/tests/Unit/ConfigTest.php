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
        // Ensure tests don't depend on previous cached config
        Config::reset();
        Config::load();
    }
    
    /**
     * Test config loading
     */
    public function testConfigLoads(): void
    {
        // app.env should be set from bootstrap env vars
        $this->assertEquals('testing', Config::get('app.env'));
    }
    
    /**
     * Test getting config values
     */
    public function testGetConfigValue(): void
    {
        // Config reads environment variables into dot-notation keys
        putenv('APP_NAME=Test Chap');
        Config::reload();

        $this->assertEquals('Test Chap', Config::get('app.name'));
    }
    
    /**
     * Test default values
     */
    public function testDefaultValues(): void
    {
        $this->assertEquals('default', Config::get('app.non_existent', 'default'));
        $this->assertNull(Config::get('app.non_existent'));
    }
    
    /**
     * Test setting config values
     */
    public function testSetConfigValue(): void
    {
        Config::set('app.custom_key', 'custom_value');
        $this->assertEquals('custom_value', Config::get('app.custom_key'));
    }
    
    /**
     * Test getting all config
     */
    public function testGetAllConfig(): void
    {
        $all = Config::all();
        
        $this->assertIsArray($all);
        $this->assertArrayHasKey('app', $all);
        $this->assertArrayHasKey('database', $all);
    }
}
