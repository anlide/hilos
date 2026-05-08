<?php

declare(strict_types=1);

namespace Hilos\API\Exception;

use Throwable;

/**
 * Exception thrown when an HTTP response cannot be parsed.
 */
class AsyncHttpMalformedResponseException extends AsyncHttpException
{
    /**
     * Creates malformed response exception.
     *
     * @param string $reason Malformed response reason
     * @param ?Throwable $previous Previous exception
     */
    public function __construct(string $reason, ?Throwable $previous = null)
    {
        parent::__construct('Malformed async HTTP response: ' . $reason, previous: $previous);
    }
}
