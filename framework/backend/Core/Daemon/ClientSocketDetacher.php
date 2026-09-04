<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\ServerInterface;

/**
 * ClientSocketDetacher - master-side seam that takes a client's socket off the watch.
 *
 * The sockets live in the master process and nobody but the daemon watches them, so a
 * server on its way to closing one has to say so first and has no way of doing it
 * itself: the Socket layer is not given the loop, not as a field and not as an argument.
 * This is the missing half - the server announces the departure here, the master takes
 * the socket off its watch ({@see DaemonManager::detachClientSocket()}), and the server
 * learns one narrow door instead of the manager, exactly as it does for
 * {@see ContainedFailureSink} and {@see ConnectionDropper}.
 *
 * Handed to every server at registration through {@see ServerInterface}, not to the ones
 * that happen to descend from {@see AbstractServer}: a server left without the seam would
 * close watched sockets in silence, which is indistinguishable from having no clients.
 * The price is that a server outside the hierarchy writes the setter itself.
 *
 * The order is the whole point: detaching runs BEFORE the close, because a watch left on
 * a closed descriptor keeps a reference to it - and to the dead client through the read
 * callback - until the master itself goes down.
 */
interface ClientSocketDetacher
{
    /**
     * Takes the client's socket off the master's watch, ahead of the close.
     *
     * A client whose socket is already gone, or was never watched, is a no-op: the
     * callers are exit paths and several of them can reach the same client twice.
     *
     * @param ClientInterface $client Client whose socket is about to be closed
     */
    public function detachClientSocket(ClientInterface $client): void;
}
