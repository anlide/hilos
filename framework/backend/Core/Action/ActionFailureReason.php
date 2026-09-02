<?php

declare(strict_types=1);

namespace Hilos\Core\Action;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Table\Exception\TableActionException;
use Throwable;

/**
 * The one door an exception passes through to become text a client may read.
 *
 * Four places used to turn a failure into a sentence - the tracked reply in
 * {@see PageSignalRouter}, the untracked {@see ActionReply::sendException()}, and two
 * catch blocks in the log store agent - and only the first of them checked anything. So
 * the gate was as strong as whichever path a given action happened to take, and the one
 * check there let through any framework exception that was not a database error: with the
 * frontend no longer dropping what arrives, "Setting 'logs.write_level' value is not a
 * valid integer" would have been read as an answer to a person (HIL-779).
 *
 * The check itself is the exception family, not a marker interface: {@see
 * ValidationException} already means "this text was written for the person who asked",
 * already carries the table-action refusals through {@see TableActionException}, and is
 * already read that way on the agent-signal path. A second mechanism beside it would
 * have to be remembered by every future thrower; this one is remembered by the type.
 */
final class ActionFailureReason
{
    /**
     * Tells whether the failure describes itself in terms the caller asked about.
     *
     * @param Throwable $e Failure raised by an action handler
     * @return bool Whether the exception's own message is addressed to a person
     */
    public static function isPersonFacing(Throwable $e): bool
    {
        return $e instanceof ValidationException;
    }

    /**
     * Reduces an action failure to what may cross the wire.
     *
     * A domain refusal - a validation rule, a table action, a business rule - describes
     * itself in terms the caller asked about, so its message travels. Anything else - a
     * driver error carrying SQL text, index names, paths, or an unexpected engine fault -
     * stays on the server, and the caller gets the placeholder instead; the full detail is
     * in the log the dispatcher wrote. See docs/agents/frontend/wire-protocol.md.
     *
     * @param Throwable $e Failure raised by an action handler
     * @return string Message safe to deliver to a client
     */
    public static function forClient(Throwable $e): string
    {
        if (!self::isPersonFacing($e)) {
            return SignalConstants::ACTION_FAILED_REASON;
        }

        return $e->getMessage();
    }

    /**
     * Names the failure's class without its namespace, for an admin to quote in a ticket.
     *
     * Short on purpose: the namespace says which framework subsystem raised it, which is
     * a map of the backend an admin surface has no business drawing, while the class name
     * alone is what a search over the sources or the issue tracker is run with.
     *
     * @param Throwable $e Failure raised by an action handler
     * @return string Class name of the exception, with no namespace before it
     */
    public static function typeOf(Throwable $e): string
    {
        $className = $e::class;
        $lastSeparator = strrpos($className, '\\');
        if ($lastSeparator === false) {
            return $className;
        }

        return substr($className, $lastSeparator + 1);
    }
}
