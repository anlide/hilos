<?php

declare(strict_types=1);

namespace Hilos\Core\Router\Destination;

/**
 * SessionClientsDestination - delivers a signal to every connection of one browser session.
 *
 * The sibling of {@see AllClientsDestination} and, like it, an instruction to fan out rather than
 * an address: only the node holding the connections can tell which of them carry the session, so
 * the daemon walks its own client list and matches each one's session token hash. What makes this
 * the only way to reach a browser under a protected-mode freeze is that the registry a named
 * address would be looked up in is not being written any more - the agent that records connections
 * is stopped for the length of the operation, so a tab opened during it is known to nobody but the
 * master that accepted it (HIL-655).
 *
 * The hash and never the token: this object is built in a worker, travels to the master and, on a
 * cluster, over the peer channel, while the token itself is the key to the account behind it.
 */
final class SessionClientsDestination implements Destination
{
    /**
     * @param string $sessionTokenHash Hash of the session token whose connections receive the signal
     */
    public function __construct(
        public readonly string $sessionTokenHash,
    ) {
    }
}
