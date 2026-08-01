<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Hilos\Core\Page\PageException;
use Throwable;

/**
 * ActionRateLimitedException - an expensive auth/detection action was throttled.
 *
 * The anti-abuse counterpart of {@see ActionUnauthorizedException}: the action
 * dispatch seam ({@see \Hilos\Core\Page\PageSignalRouter::dispatchAction}) counts
 * attempts on a page's THROTTLED_ACTIONS and, once a caller trips the window
 * ladder or is under a durable block, denies the action BEFORE onAction runs
 * rather than sleeping (the worker is single-threaded, so a blocking delay would
 * stall the whole cohort). It carries a retry-after hint in seconds alongside the
 * 'rate_limited' code; the dispatcher converts it into the client `action_error`,
 * carrying 429 rate-limit semantics through the exception code.
 */
final class ActionRateLimitedException extends PageException
{
    /** Machine-readable error code carried to the client action_error. */
    public const string ERROR_CODE = 'rate_limited';

    /**
     * Creates the throttle exception with 429 rate-limit semantics.
     *
     * @param int $retryAfter Seconds the caller should wait before retrying
     * @param string $message Human-readable error message
     * @param string $errorCode Machine-readable error code for the action_error
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(
        public readonly int $retryAfter,
        string $message = 'Too many attempts',
        public readonly string $errorCode = self::ERROR_CODE,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 429, $previous);
    }
}
