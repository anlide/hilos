<?php

declare(strict_types=1);

namespace Hilos\Core\Page\Exception;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\PageSignalRouter;
use Throwable;

/**
 * ActionAccountFrozenException - a frozen person asked for an action that is not one of the exits (HIL-945).
 *
 * The action counterpart of {@see PageAccountFrozenException}: raised by the action dispatcher
 * ({@see PageSignalRouter::dispatchAction}) for any action that does not only read, is not the
 * session's own and is not an exit. An exit is declared by its owner ({@see AbstractPage::FROZEN_EXIT_ACTIONS},
 * {@see AbstractAgent::FROZEN_EXIT_ACTIONS}) or belongs to a page open while frozen, and opens
 * only outside a takeover. A 403 like its parent, with the code the client tells a freeze by.
 */
final class ActionAccountFrozenException extends ActionForbiddenException
{
    /** Machine-readable error code carried to the client action_error. */
    public const string ERROR_CODE = 'account_frozen';

    /**
     * Creates the frozen-account refusal of an action.
     *
     * @param string $message Human-readable error message
     * @param ?Throwable $previous Previous exception for chaining
     */
    public function __construct(string $message = 'Account frozen', ?Throwable $previous = null)
    {
        parent::__construct($message, self::ERROR_CODE, $previous);
    }
}
