<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Hilos\HilosException;
use Throwable;

/**
 * PageSubscriptionException - Base exception for page subscription errors.
 *
 * Thrown during AbstractPage::onSubscribe when subscription should succeed but
 * the page needs to signal an error state (e.g. resource not found, access denied).
 * Unlike throwing arbitrary exceptions, these preserve the subscription and send
 * a structured error signal to the client.
 */
abstract class PageSubscriptionException extends HilosException
{
    /**
     * Creates page subscription exception.
     *
     * @param string $message Human-readable error message
     * @param int $httpCode HTTP status code for the error (e.g. 404, 403, 500)
     * @param string $errorCode Machine-readable error code (e.g. 'not_found', 'forbidden')
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(
        string $message = '',
        public readonly int $httpCode = 500,
        public readonly string $errorCode = 'error',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpCode, $previous);
    }
}
