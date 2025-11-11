<?php

declare(strict_types=1);

namespace Hilos\Exception\Router;

/**
 * Exception thrown when trying to update a page subscription that doesn't exist
 */
class PageSubscriptionNotFoundException extends RouteException
{
    public function __construct(string $clientId, int $code = 0, ?\Throwable $previous = null)
    {
        $message = "Cannot update page subscription: no subscription found for client {$clientId}";
        parent::__construct($message, $code, $previous);
    }
}
