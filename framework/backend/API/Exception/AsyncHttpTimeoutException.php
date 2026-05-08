<?php

declare(strict_types=1);

namespace Hilos\API\Exception;

/**
 * Exception thrown when an async HTTP request exceeds its timeout.
 */
class AsyncHttpTimeoutException extends AsyncHttpException
{
    /**
     * Creates timeout exception.
     *
     * @param float $timeoutMs Timeout in milliseconds
     */
    public function __construct(public readonly float $timeoutMs)
    {
        parent::__construct("Async HTTP request timed out after {$timeoutMs} ms");
    }
}
