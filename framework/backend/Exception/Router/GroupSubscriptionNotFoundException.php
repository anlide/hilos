<?php

declare(strict_types=1);

namespace Hilos\Exception\Router;

/**
 * Exception thrown when trying to update a group subscription that doesn't exist
 */
class GroupSubscriptionNotFoundException extends RouteException
{
    public function __construct(string $acceptKey, string $group, int $code = 0, ?\Throwable $previous = null)
    {
        $message = "Cannot update group subscription: group '{$group}' is not subscribed for acceptKey {$acceptKey}";
        parent::__construct($message, $code, $previous);
    }
}
