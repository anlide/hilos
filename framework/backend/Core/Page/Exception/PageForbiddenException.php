<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Throwable;

/**
 * PageForbiddenException - Access forbidden during page subscription.
 *
 * Thrown when the user lacks permission to access the requested resource.
 * Maps to HTTP 403 and error code 'forbidden'.
 */
class PageForbiddenException extends PageSubscriptionException
{
    /**
     * Creates forbidden exception.
     *
     * @param string $message Human-readable error message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message = 'Access forbidden', ?Throwable $previous = null)
    {
        parent::__construct($message, 403, 'forbidden', $previous);
    }
}
