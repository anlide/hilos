<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Throwable;

/**
 * PageResourceNotFoundException - Resource not found during page subscription.
 *
 * Thrown when the requested resource (e.g. user, entity) does not exist.
 * Maps to HTTP 404 and error code 'not_found'.
 */
class PageResourceNotFoundException extends PageSubscriptionException
{
    /**
     * Creates resource not found exception.
     *
     * @param string $message Human-readable error message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message = 'Resource not found', ?Throwable $previous = null)
    {
        parent::__construct($message, 404, 'not_found', $previous);
    }
}
