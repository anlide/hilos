<?php

declare(strict_types=1);

namespace Hilos\Auth\Impersonation;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Item\Session;
use Hilos\Hilos;

/**
 * Who works inside someone else's account on a connection, read where it is written (HIL-1170).
 *
 * The marker lives on the session row ({@see Session::$impersonatorUserId})
 * and nowhere in the runtime: a field on the connection row would have to be written by every
 * project's own connection writer. The sessions are read process-wide already, so the row is at
 * hand wherever an action is dispatched; it is asked only for actions that write.
 */
final class Takeover
{
    /**
     * The administrator working inside someone else's account on this connection.
     *
     * @param string $acceptKey Connection asked about
     * @return ?int Administrator behind the takeover, or null when the connection is inside none
     * @throws DatabaseException When the session row cannot be read
     * @throws LogicException When the session collection is not configured
     * @throws InvalidArgumentException When a loaded session object does not match its collection
     */
    public static function administratorBehind(string $acceptKey): ?int
    {
        $connection = Hilos::$rt?->sessionConnectionsSource()?->get($acceptKey);
        if ($connection?->userId === null || $connection->sessionId === null) {
            return null;
        }

        return Hilos::$db->sessions[$connection->sessionId]?->impersonatorUserId;
    }
}
