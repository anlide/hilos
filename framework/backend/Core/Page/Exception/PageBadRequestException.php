<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Throwable;

/**
 * PageBadRequestException - Bad request during page subscription.
 *
 * Thrown when the subscription request has invalid or malformed parameters.
 * Maps to HTTP 400 and error code 'bad_request'.
 */
class PageBadRequestException extends PageSubscriptionException
{
    /**
     * Creates bad request exception.
     *
     * @param string $message Human-readable error message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message = 'Bad request', ?Throwable $previous = null)
    {
        parent::__construct($message, 400, 'bad_request', $previous);
    }
}
