<?php

declare(strict_types=1);

namespace Hilos\API\Router\Exception;

use Throwable;

/**
 * Exception thrown when trying to update a page subscription that doesn't exist.
 */
class PageSubscriptionNotFoundException extends RouteException
{
    /**
     * Creates page subscription not found exception.
     *
     * @param string $acceptKey Accept key with no subscription
     * @param int $code Exception code
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $acceptKey, int $code = 0, ?\Throwable $previous = null)
    {
        $message = "Cannot update page subscription: no subscription found for acceptKey {$acceptKey}";
        parent::__construct($message, $code, $previous);
    }
}
