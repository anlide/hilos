<?php

declare(strict_types=1);

namespace Hilos\API\Exception;

use Hilos\API\AsyncHttpState;

/**
 * Exception thrown when an async HTTP request exceeds its timeout.
 */
class AsyncHttpTimeoutException extends AsyncHttpException
{
    /**
     * Creates timeout exception.
     *
     * @param float $timeoutMs Timeout in milliseconds
     * @param AsyncHttpState $state Request state the timeout expired in
     */
    public function __construct(public readonly float $timeoutMs, public readonly AsyncHttpState $state)
    {
        parent::__construct("Async HTTP request timed out after {$timeoutMs} ms in state {$state->value}");
    }
}
