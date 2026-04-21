<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Throwable;

/**
 * PageGoneException - Resource permanently deleted during page subscription.
 *
 * Thrown when the requested resource existed previously but has been permanently deleted.
 * Maps to HTTP 410 and error code 'gone'.
 */
class PageGoneException extends PageSubscriptionException
{
    /**
     * Creates gone exception.
     *
     * @param string $message Human-readable error message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message = 'Resource gone', ?Throwable $previous = null)
    {
        parent::__construct($message, 410, 'gone', $previous);
    }
}
