<?php

declare(strict_types=1);

namespace Hilos\API\Router;

/**
 * RouteRegistry - Stores and matches routes
 *
 * Maintains a registry of all registered routes and provides matching
 * functionality to find the appropriate route for a given request.
 */
class RouteRegistry
{
    /** @var array Registered routes */
    private array $routes = [];

    /**
     * Register a route
     *
     * @param string $method HTTP method
     * @param string $path URL path
     * @param callable $handler Handler function
     */
    public function register(string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        
        if (!isset($this->routes[$method])) {
            $this->routes[$method] = [];
        }

        $this->routes[$method][$path] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'pattern' => $this->compilePattern($path),
        ];
    }

    /**
     * Match a route for given method and path
     *
     * @param string $method HTTP method
     * @param string $path URL path
     * @return ?array Route data or null if not found
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        
        if (!isset($this->routes[$method])) {
            return null;
        }

        // Try exact match first
        if (isset($this->routes[$method][$path])) {
            return $this->routes[$method][$path];
        }

        // Try pattern matching
        foreach ($this->routes[$method] as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                $route['params'] = $matches;
                return $route;
            }
        }

        return null;
    }

    /**
     * Compile path pattern to regex
     *
     * Converts route path like /user/{id} to regex pattern
     *
     * @param string $path Route path
     * @return string Regex pattern
     */
    private function compilePattern(string $path): string
    {
        // Convert /user/{id} to /user/([^/]+)
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '([^/]+)', $path);
        
        // Escape slashes and add anchors
        $pattern = '#^' . $pattern . '$#';
        
        return $pattern;
    }

    /**
     * Get all registered routes
     *
     * @return array All routes
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }
}

