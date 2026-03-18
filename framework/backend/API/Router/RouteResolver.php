<?php

declare(strict_types=1);

namespace Hilos\API\Router;

use Hilos\Constants\HttpConstants;

/**
 * RouteResolver - Resolves and executes route handlers.
 *
 * Takes a matched route and request, executes the handler,
 * and formats the response.
 */
class RouteResolver
{
    /**
     * Resolves route and executes handler.
     *
     * @param array<string, mixed> $route Route data (handler, params)
     * @param array<string, mixed> $request Request data (method, path, etc.)
     * @return array<string, mixed> Response data (status, headers, body)
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
     * Formats handler result into HTTP response.
     *
     * @param mixed $result Handler result (array, string, or other)
     * @return array<string, mixed> Response with status, headers, body keys
     */
    private function formatResponse(mixed $result): array
    {
        // If result is already formatted response
        if (is_array($result) && isset($result[HttpConstants::RESPONSE_KEY_STATUS])) {
            return $result;
        }

        // If result is array, convert to JSON
        if (is_array($result)) {
            return [
                HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_OK,
                HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_JSON],
                HttpConstants::RESPONSE_KEY_BODY => json_encode($result),
            ];
        }

        // If result is string, return as plain text
        if (is_string($result)) {
            return [
                HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_OK,
                HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_TEXT],
                HttpConstants::RESPONSE_KEY_BODY => $result,
            ];
        }

        // Default response
        return [
            HttpConstants::RESPONSE_KEY_STATUS => HttpConstants::HTTP_OK,
            HttpConstants::RESPONSE_KEY_HEADERS => [HttpConstants::HEADER_CONTENT_TYPE => HttpConstants::CONTENT_TYPE_TEXT],
            HttpConstants::RESPONSE_KEY_BODY => '',
        ];
    }
}
