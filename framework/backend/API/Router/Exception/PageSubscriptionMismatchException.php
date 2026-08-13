<?php

declare(strict_types=1);

namespace Hilos\API\Router\Exception;

use Throwable;

/**
 * Exception thrown when trying to update a page subscription with a different page name.
 */
class PageSubscriptionMismatchException extends RouteException
{
    /**
     * Creates page subscription mismatch exception.
     *
     * @param string $currentPage Current page name
     * @param string $requestedPage Requested page name (mismatch)
     * @param int $code Exception code
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $currentPage, string $requestedPage, int $code = 0, ?Throwable $previous = null)
    {
        $message = "Cannot update page subscription: current page '{$currentPage}' doesn't match page '{$requestedPage}'";
        parent::__construct($message, $code, $previous);
    }
}
