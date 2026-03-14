<?php

declare(strict_types=1);

namespace Hilos\API\Router\Exception;

/**
 * Exception thrown when trying to update a group subscription that doesn't exist.
 */
class GroupSubscriptionNotFoundException extends RouteException
{
    /**
     * Creates group subscription not found exception.
     *
     * @param string $acceptKey WebSocket accept key
     * @param string $group Group name that is not subscribed
     * @param int $code Exception code
     * @param ?\Throwable $previous Previous exception for chaining
     */
    public function __construct(string $acceptKey, string $group, int $code = 0, ?\Throwable $previous = null)
    {
        $message = "Cannot update group subscription: group '{$group}' is not subscribed for acceptKey {$acceptKey}";
        parent::__construct($message, $code, $previous);
    }
}
