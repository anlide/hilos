<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Hilos\Auth\Impersonation\ImpersonationSettings;
use Hilos\Core\Page\PageException;
use Hilos\Core\Page\PageSignalRouter;
use Throwable;

/**
 * ActionImpersonationException - an administrator inside someone else's account asked for what the settings close there (HIL-1170).
 *
 * A sibling of {@see ActionViewModeException}: nothing broke, the administrator pressed a button
 * the impersonation settings ({@see ImpersonationSettings}) close inside a takeover. Two codes,
 * one per setting: `impersonation_view_only` - only looking is allowed, and every writing action is
 * refused - and `impersonated` - the sign-in of the account is not to be touched. The code is its
 * own and not `forbidden`: the administrator holds every right the page asks for, and the screen
 * says why in its own words, picked by the code.
 *
 * Not a validation refusal: the message is written for the journal, and the client gets the
 * impersonal placeholder with the code. The dispatcher ({@see PageSignalRouter}) journals it as a
 * verdict, not as a failure.
 */
final class ActionImpersonationException extends PageException
{
    /** Code: inside a takeover the administrator only looks. */
    public const string CODE_VIEW_ONLY = 'impersonation_view_only';

    /** Code: inside a takeover the sign-in of the account is not to be touched. */
    public const string CODE_ACCOUNT_ACCESS = 'impersonated';

    /**
     * Creates the impersonation refusal with 403 access semantics.
     *
     * @param string $errorCode One of the CODE_* constants, carried to the client action_error
     * @param string $message Message for the journal
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(
        public readonly string $errorCode,
        string $message = 'Refused inside an impersonation by the impersonation settings',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 403, $previous);
    }
}
