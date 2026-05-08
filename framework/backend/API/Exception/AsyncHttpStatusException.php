<?php

declare(strict_types=1);

namespace Hilos\API\Exception;

/**
 * Exception thrown when a completed HTTP response has a non-success status code.
 */
class AsyncHttpStatusException extends AsyncHttpException
{
    /**
     * Creates HTTP status exception.
     *
     * @param int $statusCode HTTP status code
     * @param string $body Response body
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
    ) {
        parent::__construct("Async HTTP request failed with status {$statusCode}");
    }
}
