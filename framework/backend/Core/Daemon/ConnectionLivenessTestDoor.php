<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Socket\WebSocket\Liveness\WebSocketLivenessMoment;

/** Master entrance for the test-only command that brings a browser's socket liveness moment to now. */
interface ConnectionLivenessTestDoor
{
    /**
     * @param string $sessionTokenHash Hash of the session token of the browser whose sockets take the moment
     * @param WebSocketLivenessMoment $moment Moment to bring to now
     * @return int How many live sockets of that browser on this master took the moment
     * @throws InvalidArgumentException When a socket's liveness signal cannot be named
     * @throws InvalidFormatException When a socket has no accept key to name in the signal
     * @throws RtActionsStateCollectionNullException When the connections registry has no state collection behind it
     */
    public function bringLivenessMomentToNow(string $sessionTokenHash, WebSocketLivenessMoment $moment): int;
}
