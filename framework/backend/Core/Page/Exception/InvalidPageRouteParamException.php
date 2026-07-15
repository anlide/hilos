<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Hilos\Core\Page\PageRouteParams;
use Throwable;

/**
 * InvalidPageRouteParamException - Page route param value does not match expected type.
 *
 * Thrown by {@see PageRouteParams} accessors when a key is
 * present with a non-empty value, but the value fails the expected type
 * contract (e.g. non-numeric for `getInt`, `<= 0` for `getPositiveInt`,
 * unknown case for `getEnum`). Raised even by `get*` variants, because a
 * malformed value is never equivalent to a missing value.
 * Maps to HTTP 400 and error code 'invalid_page_route_param'.
 */
class InvalidPageRouteParamException extends PageSubscriptionException
{
    /**
     * Creates invalid page route param exception.
     *
     * @param string $paramKey Name of the offending route param
     * @param string $reason Short explanation of why the value is invalid
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(
        public readonly string $paramKey,
        string $reason = 'invalid value',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "Invalid page route param: {$paramKey} ({$reason})",
            400,
            'invalid_page_route_param',
            $previous,
        );
    }
}
