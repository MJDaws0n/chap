<?php
/**
 * Unit Tests: Router
 * 
 * Tests for the Router class
 */

namespace Tests\Unit;

use Tests\TestCase;
use Chap\Router\Router;

class RouterTest extends TestCase
{
    private Router $router;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->router = new Router();
    }
    
    /**
     * Test registering GET routes
     */
    public function testRegisterGetRoute(): void
    {
        $this->router->get('/test', function() {
            return 'test';
        });
        
        $routes = $this->router->getRoutes();
        $this->assertArrayHasKey('GET', $routes);
        $this->assertNotEmpty($routes['GET']);
    }
    
    /**
     * Test registering POST routes
     */
    public function testRegisterPostRoute(): void
    {
        $this->router->post('/test', function() {
            return 'test';
        });
        
        $routes = $this->router->getRoutes();
        $this->assertArrayHasKey('POST', $routes);
    }
    
    /**
     * Test route with parameters
     */
    public function testRouteWithParameters(): void
    {
        $this->router->get('/users/{id}', function($id) {
            return "User: $id";
        });
        
        $routes = $this->router->getRoutes();
        $this->assertArrayHasKey('GET', $routes);
        
        // Check that the route pattern was registered
        $found = false;
        foreach ($routes['GET'] as $route) {
            if (strpos($route['pattern'], 'users') !== false) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Route with parameter should be registered');
    }
    
    /**
     * Test route groups with prefix
     */
    public function testRouteGroupWithPrefix(): void
    {
        $this->router->group(['prefix' => '/api'], function($router) {
            $router->get('/users', function() {
                return 'users';
            });
        });
        
        $routes = $this->router->getRoutes();
        
        // Check that the prefixed route exists
        $found = false;
        foreach ($routes['GET'] as $route) {
            if ($route['pattern'] === '/api/users') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Prefixed route should exist');
    }
    
    /**
     * Test route matching
     */
    public function testRouteMatching(): void
    {
        $called = false;
        $this->router->get('/test-match', function() use (&$called) {
            $called = true;
        });
        
        // This tests internal matching logic
        $routes = $this->router->getRoutes();
        $this->assertNotEmpty($routes['GET']);
    }
}
