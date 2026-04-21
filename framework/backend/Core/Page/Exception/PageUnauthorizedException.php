<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Throwable;

/**
 * PageUnauthorizedException - Unauthorized access during page subscription.
 *
 * Thrown when authentication is required but missing or invalid.
 * Maps to HTTP 401 and error code 'unauthorized'.
 */
class PageUnauthorizedException extends PageSubscriptionException
{
    /**
     * Creates unauthorized exception.
     *
     * @param string $message Human-readable error message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message = 'Unauthorized', ?Throwable $previous = null)
    {
        parent::__construct($message, 401, 'unauthorized', $previous);
    }
}
