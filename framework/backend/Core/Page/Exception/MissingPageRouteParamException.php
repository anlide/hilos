<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Throwable;

/**
 * MissingPageRouteParamException - Required page route param is missing.
 *
 * Thrown by {@see \Hilos\Core\Page\PageRouteParams} when a `require*` accessor
 * does not find the key (or finds an empty string, which is treated as missing).
 * Maps to HTTP 400 and error code 'missing_page_route_param'.
 */
class MissingPageRouteParamException extends PageSubscriptionException
{
    /**
     * Creates missing page route param exception.
     *
     * @param string $paramKey Name of the missing route param
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(public readonly string $paramKey, ?Throwable $previous = null)
    {
        parent::__construct(
            "Missing page route param: {$paramKey}",
            400,
            'missing_page_route_param',
            $previous,
        );
    }
}
