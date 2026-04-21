<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Throwable;

/**
 * PageInternalErrorException - Internal error during page subscription.
 *
 * Thrown when an unexpected internal error occurs during subscription.
 * Maps to HTTP 500 and error code 'internal_error'.
 */
class PageInternalErrorException extends PageSubscriptionException
{
    /**
     * Creates internal error exception.
     *
     * @param string $message Human-readable error message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message = 'Internal error', ?Throwable $previous = null)
    {
        parent::__construct($message, 500, 'internal_error', $previous);
    }
}
