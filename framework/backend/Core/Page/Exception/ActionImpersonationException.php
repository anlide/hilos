<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Hilos\Auth\Impersonation\ImpersonationSettings;
use Hilos\Core\Action\ActionMessages;
use Hilos\Core\Exception\PersonFacingFailure;
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
 * own and not `forbidden`: the administrator holds every right the page asks for.
 *
 * The person-facing message defaults to the catalog sentence for the given code and travels
 * with it in the action-error frame. The dispatcher ({@see PageSignalRouter}) journals it as
 * a verdict, not as a failure.
 */
final class ActionImpersonationException extends PageException implements PersonFacingFailure
{
    /** Code: inside a takeover the administrator only looks. */
    public const string CODE_VIEW_ONLY = 'impersonation_view_only';

    /** Code: inside a takeover the sign-in of the account is not to be touched. */
    public const string CODE_ACCOUNT_ACCESS = 'impersonated';

    /**
     * Creates the impersonation refusal with 403 access semantics.
     *
     * @param string $errorCode One of the CODE_* constants, carried to the client action_error
     * @param ?string $message Person-facing refusal message, or null for the code's catalog sentence
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(
        public readonly string $errorCode,
        ?string $message = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? ($errorCode === self::CODE_VIEW_ONLY ? ActionMessages::IMPERSONATION_VIEW_ONLY : ActionMessages::IMPERSONATED),
            403,
            $previous,
        );
    }
}
