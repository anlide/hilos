<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Hilos\Core\Page\PageException;
use Hilos\Core\Page\PageSignalRouter;
use Throwable;

/**
 * ActionForbiddenException - the acting user lacks the privilege a page's
 * access level demands for its actions.
 *
 * The 403 counterpart of {@see ActionUnauthorizedException}: that one denies an
 * anonymous session (the frontend answers it with the sign-in modal), while
 * this one denies an authenticated user who is simply not an admin — a sign-in
 * modal is useless to someone already signed in, so the client needs the
 * distinct forbidden code. It is a page-subsystem error, not a
 * {@see PageSubscriptionException}: the action dispatcher
 * ({@see PageSignalRouter::dispatchAction}) converts it into the client
 * `action_error`, carrying 403 semantics through the exception code.
 */
final class ActionForbiddenException extends PageException
{
    /** Machine-readable error code carried to the client action_error. */
    public const string ERROR_CODE = 'forbidden';

    /**
     * Creates the action forbidden exception with 403 access semantics.
     *
     * @param string $message Human-readable error message
     * @param string $errorCode Machine-readable error code for the action_error
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'Access forbidden',
        public readonly string $errorCode = self::ERROR_CODE,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 403, $previous);
    }
}
