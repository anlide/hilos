<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Throwable;

/**
 * PageConflictException - Conflict during page subscription.
 *
 * Thrown when the subscription request conflicts with the current state
 * (e.g. resource is locked, concurrent modification detected).
 * Maps to HTTP 409 and error code 'conflict'.
 */
class PageConflictException extends PageSubscriptionException
{
    /**
     * Creates conflict exception.
     *
     * @param string $message Human-readable error message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message = 'Conflict', ?Throwable $previous = null)
    {
        parent::__construct($message, 409, 'conflict', $previous);
    }
}
