<?php
/**
 * Base Test Case
 * 
 * Provides common functionality for all tests.
 */

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Chap\Database\Connection;

abstract class TestCase extends BaseTestCase
{
    protected static ?Connection $db = null;
    
    /**
     * Get database connection (singleton for test run)
     */
    protected function getDb(): Connection
    {
        if (self::$db === null) {
            self::$db = new Connection();
        }
        return self::$db;
    }
    
    /**
     * Clean up after each test
     */
    protected function tearDown(): void
    {
        // Clear session data
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        
        parent::tearDown();
    }
}
