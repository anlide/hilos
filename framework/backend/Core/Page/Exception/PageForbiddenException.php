<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Throwable;

/**
 * PageForbiddenException - Access forbidden during page subscription.
 *
 * Thrown when the user lacks permission to access the requested resource.
 * Maps to HTTP 403 and error code 'forbidden'; a subclass names a narrower
 * reason by its own {@see static::ERROR_CODE} and stays a 403 everywhere a
 * forbidden page is caught.
 */
class PageForbiddenException extends PageSubscriptionException
{
    /** Machine-readable error code carried to the client subscription error. */
    public const string ERROR_CODE = 'forbidden';

    /**
     * Creates forbidden exception.
     *
     * @param string $message Human-readable error message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message = 'Access forbidden', ?Throwable $previous = null)
    {
        parent::__construct($message, 403, static::ERROR_CODE, $previous);
    }
}
