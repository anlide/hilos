<?php

declare(strict_types=1);

namespace Hilos\Exception\Router;

/**
 * Exception thrown when trying to update a page subscription with a different page name
 */
class PageSubscriptionMismatchException extends RouteException
{
    public function __construct(string $currentPage, string $requestedPage, int $code = 0, ?\Throwable $previous = null)
    {
        $message = "Cannot update page subscription: current page '{$currentPage}' doesn't match page '{$requestedPage}'";
        parent::__construct($message, $code, $previous);
    }
}
