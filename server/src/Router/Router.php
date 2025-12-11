<?php

namespace Chap\Router;

use Chap\Middleware\AuthMiddleware;
use Chap\Middleware\CsrfMiddleware;
use Chap\Middleware\GuestMiddleware;

/**
 * HTTP Router
 */
class Router
{
    private array $routes = [];
    private array $groupMiddleware = [];
    private string $groupPrefix = '';
    private array $namedRoutes = [];
    private static array $middlewareMap = [
        'auth' => AuthMiddleware::class,
        'csrf' => CsrfMiddleware::class,
        'guest' => GuestMiddleware::class,
        'api.auth' => AuthMiddleware::class, // API uses same auth for now
    ];

    /**
     * Add GET route
     */
    public function get(string $uri, callable|array|string $handler): Route
    {
        return $this->addRouteInternal('GET', $uri, $handler);
    }

    /**
     * Add POST route
     */
    public function post(string $uri, callable|array|string $handler): Route
    {
        return $this->addRouteInternal('POST', $uri, $handler);
    }

    /**
     * Add PUT route
     */
    public function put(string $uri, callable|array|string $handler): Route
    {
        return $this->addRouteInternal('PUT', $uri, $handler);
    }

    /**
     * Add DELETE route
     */
    public function delete(string $uri, callable|array|string $handler): Route
    {
        return $this->addRouteInternal('DELETE', $uri, $handler);
    }

    /**
     * Add PATCH route
     */
    public function patch(string $uri, callable|array|string $handler): Route
    {
        return $this->addRouteInternal('PATCH', $uri, $handler);
    }

    /**
     * Add a Route object directly
     */
    public function addRoute(Route $route): void
    {
        $this->routes[] = $route;
    }

    /**
     * Add route to collection (internal)
     */
    private function addRouteInternal(string $method, string $uri, callable|array|string $handler): Route
    {
        $uri = $this->groupPrefix . '/' . ltrim($uri, '/');
        $uri = rtrim($uri, '/') ?: '/';
        
        $route = new Route($method, $uri, $handler);
        
        if (!empty($this->groupMiddleware)) {
            $route->addMiddleware($this->groupMiddleware);
        }
        
        $this->routes[] = $route;
        
        return $route;
    }

    /**
     * Create a route group
     */
    public function group(array $options, callable $callback): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        if (isset($options['prefix'])) {
            $this->groupPrefix .= '/' . trim($options['prefix'], '/');
        }

        if (isset($options['middleware'])) {
            $middleware = is_array($options['middleware']) 
                ? $options['middleware'] 
                : [$options['middleware']];
            $this->groupMiddleware = array_merge($this->groupMiddleware, $middleware);
        }

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    /**
     * Get all registered routes (for testing/debugging)
     */
    public function getRoutes(): array
    {
        $grouped = [];
        foreach ($this->routes as $route) {
            $method = $route->method;
            if (!isset($grouped[$method])) {
                $grouped[$method] = [];
            }
            $grouped[$method][] = [
                'pattern' => $route->pattern,
                'handler' => $route->handler,
            ];
        }
        return $grouped;
    }

    /**
     * Dispatch request to appropriate handler
     */
    public function dispatch(string $method, string $uri): void
    {
        // Parse URI - remove query string
        $uri = parse_url($uri, PHP_URL_PATH);
        $uri = rtrim($uri, '/') ?: '/';

        foreach ($this->routes as $route) {
            if ($route->method !== $method) {
                continue;
            }

            $pattern = $this->convertToRegex($route->pattern);
            
            if (preg_match($pattern, $uri, $matches)) {
                // Extract named parameters
                $params = [];
                foreach ($matches as $key => $value) {
                    if (!is_int($key)) {
                        $params[$key] = $value;
                    }
                }
                
                // Run middleware
                foreach ($route->middleware as $middleware) {
                    $result = $this->runMiddleware($middleware);
                    if ($result === false) {
                        return;
                    }
                }

                // Call handler
                $this->callHandler($route->handler, $params);
                return;
            }
        }

        // No route found
        $this->handleNotFound();
    }

    /**
     * Convert route pattern to regex
     */
    private function convertToRegex(string $pattern): string
    {
        // Convert {param} to named capture group
        $pattern = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        // Escape forward slashes
        $pattern = str_replace('/', '\/', $pattern);
        
        return '/^' . $pattern . '$/';
    }

    /**
     * Run middleware
     */
    private function runMiddleware(string $middleware): bool
    {
        if (isset(self::$middlewareMap[$middleware])) {
            $instance = new self::$middlewareMap[$middleware]();
            return $instance->handle();
        }

        return true;
    }

    /**
     * Call route handler
     */
    private function callHandler(callable|array|string $handler, array $params): void
    {
        // Handle string format "Controller@method"
        if (is_string($handler) && str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler);
            // Add controller namespace if not present
            if (!str_contains($class, '\\')) {
                $class = 'Chap\\Controllers\\' . $class;
            }
            $instance = new $class();
            $instance->$method(...array_values($params));
        } elseif (is_array($handler)) {
            [$class, $method] = $handler;
            $instance = new $class();
            $instance->$method(...array_values($params));
        } else {
            $handler(...array_values($params));
        }
    }

    /**
     * Handle 404 Not Found
     */
    private function handleNotFound(): void
    {
        http_response_code(404);
        
        if ($this->isApiRequest()) {
            json(['error' => 'Not Found'], 404);
        } else {
            // If user is not authenticated, redirect to login instead of showing 404
            if (!\Chap\Auth\AuthManager::check()) {
                redirect('/login');
                return;
            }
            echo view('errors/404');
        }
    }

    /**
     * Check if request is API request
     */
    private function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        
        return str_starts_with($uri, '/api/') || str_contains($accept, 'application/json');
    }

    /**
     * Get URL for named route
     */
    public function route(string $name, array $params = []): ?string
    {
        foreach ($this->routes as $route) {
            if ($route->name === $name) {
                $url = $route->pattern;
                foreach ($params as $key => $value) {
                    $url = str_replace('{' . $key . '}', $value, $url);
                }
                return $url;
            }
        }
        return null;
    }
}
