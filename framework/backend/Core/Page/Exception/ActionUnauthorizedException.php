<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Hilos\Core\Page\PageException;
use Hilos\Core\Page\PageSignalRouter;
use Throwable;

/**
 * ActionUnauthorizedException - authentication required for a page or table action.
 *
 * The action-dispatch counterpart of {@see PageUnauthorizedException}: the page
 * ACCESS guard gates a whole subscription, but an anonymous session may still be
 * subscribed to an anonymously-readable page and invoke a write action (the
 * anonymous-read + authenticated-write model). A project requires an
 * authenticated session for such an action with {@see self::requireUser}, which
 * throws this when the acting session has no user. It is a page-subsystem error,
 * not a {@see PageSubscriptionException}: the action dispatcher
 * ({@see PageSignalRouter::dispatchAction}) converts it into the
 * client `action_error`, carrying 401 auth semantics through the exception code.
 */
final class ActionUnauthorizedException extends PageException
{
    /** Machine-readable error code carried to the client action_error. */
    public const string ERROR_CODE = 'unauthorized';

    /**
     * Creates the action authorization exception with 401 auth semantics.
     *
     * @param string $message Human-readable error message
     * @param string $errorCode Machine-readable error code for the action_error
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'Authentication required',
        public readonly string $errorCode = self::ERROR_CODE,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 401, $previous);
    }

    /**
     * Guards a write action on an authenticated session, returning its user id.
     *
     * The thin framework seam an action handler or table action calls to require
     * an authenticated session; the project resolves the acting session's user id
     * (e.g. from its runtime connection registry) and passes it here.
     *
     * @param ?int $userId Acting session's authenticated user id, or null when anonymous
     * @return int Authenticated user id
     * @throws self When the acting session is anonymous
     */
    public static function requireUser(?int $userId): int
    {
        if ($userId === null) {
            throw new self();
        }

        return $userId;
    }
}
