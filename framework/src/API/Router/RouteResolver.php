<?php

declare(strict_types=1);

namespace Hilos\API\Router;

/**
 * RouteResolver - Resolves and executes route handlers
 *
 * Takes a matched route and request, executes the handler,
 * and formats the response.
 */
class RouteResolver
{
    /**
     * Resolve route and execute handler
     *
     * @param array $route Route data with handler
     * @param array $request Request data
     * @return array Response data
     */
    public function resolve(array $route, array $request): array
    {
        $handler = $route['handler'];
        $params = $route['params'] ?? [];

        // Build handler arguments
        $args = [
            'request' => $request,
            'params' => $params,
        ];

        // Execute handler
        $result = call_user_func($handler, $args);

        // Format response
        return $this->formatResponse($result);
    }

    /**
     * Format handler result into HTTP response
     *
     * @param mixed $result Handler result
     * @return array HTTP response
     */
    private function formatResponse($result): array
    {
        // If result is already formatted response
        if (is_array($result) && isset($result['status'])) {
            return $result;
        }

        // If result is array, convert to JSON
        if (is_array($result)) {
            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => json_encode($result),
            ];
        }

        // If result is string, return as plain text
        if (is_string($result)) {
            return [
                'status' => 200,
                'headers' => ['Content-Type' => 'text/plain'],
                'body' => $result,
            ];
        }

        // Default response
        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'text/plain'],
            'body' => '',
        ];
    }
}

