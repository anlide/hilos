<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Throwable;

/**
 * PageServiceUnavailableException - Page temporarily unavailable during page subscription.
 *
 * Thrown when the system withholds a page from a connection for a transient,
 * operational reason rather than a permission or resource fault - the protected-mode
 * route lockdown freezes every connection out, the initiator's included, while a
 * destructive operation runs. Maps to HTTP 503 and error code 'service_unavailable'.
 * The default message is a domain sentence (never engine detail), per
 * docs/agents/frontend/wire-protocol.md.
 */
class PageServiceUnavailableException extends PageSubscriptionException
{
    /**
     * Creates service-unavailable exception.
     *
     * @param string $message Human-readable error message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message = 'The system is temporarily unavailable for maintenance', ?Throwable $previous = null)
    {
        parent::__construct($message, 503, 'service_unavailable', $previous);
    }
}
