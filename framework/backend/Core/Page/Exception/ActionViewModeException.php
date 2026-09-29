<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageAccessGate;
use Hilos\Core\Page\PageException;
use Hilos\Core\Page\PageSignalRouter;
use Throwable;

/**
 * ActionViewModeException - a viewer of the admin view mode asked an admin page to do something.
 *
 * The third sibling of {@see ActionUnauthorizedException} and {@see ActionForbiddenException}:
 * the viewer may look at the page ({@see PageAccessGate::assertAction}) but changes nothing, so
 * every action of the page is refused except the ones it declared reading
 * ({@see AbstractPage::READING_ACTIONS}). The code is its own and not `forbidden`: a viewer
 * without an account must not be answered with the sign-in modal, and a signed-in one must not
 * be told they lack a right the page is showing them anyway.
 *
 * Not a validation refusal: the message is written for the journal, and the client gets the
 * impersonal placeholder with this code - the sentence on the screen is the frontend's to pick.
 * The dispatcher ({@see PageSignalRouter::dispatchAction}) journals it as a verdict, not as a
 * failure.
 */
final class ActionViewModeException extends PageException
{
    /** Machine-readable error code carried to the client action_error. */
    public const string ERROR_CODE = 'view_mode';

    /**
     * Creates the view-mode refusal with 403 access semantics.
     *
     * @param string $message Message for the journal
     * @param string $errorCode Machine-readable error code for the action_error
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(
        string $message = 'The page is in the admin view mode: a viewer changes nothing',
        public readonly string $errorCode = self::ERROR_CODE,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 403, $previous);
    }
}
