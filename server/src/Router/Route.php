<?php

namespace Chap\Router;

/**
 * Route Definition with Static Facade
 */
class Route
{
    public string $method;
    public string $pattern;
    public $handler;
    public array $middleware = [];
    public string $name = '';
    
    private static Router $router;
    private static array $groupStack = [];

    public function __construct(string $method, string $pattern, callable|array|string $handler)
    {
        $this->method = $method;
        $this->pattern = $pattern;
        $this->handler = $handler;
    }

    /**
     * Set the router instance
     */
    public static function setRouter(Router $router): void
    {
        self::$router = $router;
    }

    /**
     * Get the router instance
     */
    public static function getRouter(): Router
    {
        if (!isset(self::$router)) {
            self::$router = new Router();
        }
        return self::$router;
    }

    /**
     * Register a GET route
     */
    public static function get(string $pattern, callable|array|string $handler): self
    {
        return self::addRoute('GET', $pattern, $handler);
    }

    /**
     * Register a POST route
     */
    public static function post(string $pattern, callable|array|string $handler): self
    {
        return self::addRoute('POST', $pattern, $handler);
    }

    /**
     * Register a PUT route
     */
    public static function put(string $pattern, callable|array|string $handler): self
    {
        return self::addRoute('PUT', $pattern, $handler);
    }

    /**
     * Register a PATCH route
     */
    public static function patch(string $pattern, callable|array|string $handler): self
    {
        return self::addRoute('PATCH', $pattern, $handler);
    }

    /**
     * Register a DELETE route
     */
    public static function delete(string $pattern, callable|array|string $handler): self
    {
        return self::addRoute('DELETE', $pattern, $handler);
    }

    /**
     * Add a route with group attributes applied
     */
    private static function addRoute(string $method, string $pattern, callable|array|string $handler): self
    {
        $prefix = self::getGroupPrefix();
        $middleware = self::getGroupMiddleware();
        
        $route = new self($method, $prefix . $pattern, $handler);
        
        if (!empty($middleware)) {
            $route->middleware = $middleware;
        }
        
        self::getRouter()->addRoute($route);
        
        return $route;
    }

    /**
     * Create a route group with shared attributes
     */
    public static function group(array $attributes, callable $callback): void
    {
        self::$groupStack[] = $attributes;
        $callback();
        array_pop(self::$groupStack);
    }

    /**
     * Create a route group with middleware
     */
    public static function middleware(array|string $middleware, callable $callback): void
    {
        if (is_string($middleware)) {
            $middleware = [$middleware];
        }
        self::group(['middleware' => $middleware], $callback);
    }

    /**
     * Create a route group with prefix
     */
    public static function prefix(string $prefix, callable $callback): void
    {
        self::group(['prefix' => $prefix], $callback);
    }

    /**
     * Get current group prefix
     */
    private static function getGroupPrefix(): string
    {
        $prefix = '';
        foreach (self::$groupStack as $group) {
            if (isset($group['prefix'])) {
                $prefix .= $group['prefix'];
            }
        }
        return $prefix;
    }

    /**
     * Get current group middleware
     */
    private static function getGroupMiddleware(): array
    {
        $middleware = [];
        foreach (self::$groupStack as $group) {
            if (isset($group['middleware'])) {
                $middleware = array_merge($middleware, (array)$group['middleware']);
            }
        }
        return $middleware;
    }

    /**
     * Add middleware to this route instance
     */
    public function addMiddleware(string|array $middleware): self
    {
        if (is_string($middleware)) {
            $middleware = [$middleware];
        }
        $this->middleware = array_merge($this->middleware, $middleware);
        return $this;
    }

    /**
     * Set route name
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }
}
