<?php

declare(strict_types=1);

namespace Hilos\Core\CLI;

/**
 * DaemonPresence - whether a daemon is answering on this installation's command channel.
 *
 * Three cases and not two, because "could not tell" is its own answer and the CLI acts on it
 * differently: a command that writes state the daemon owns is refused when it cannot tell,
 * not admitted. Fail-closed is the whole reason the third case exists - the failure a running
 * daemon and a second writer produce together is a corrupted database, and the cost of being
 * wrong is not symmetric.
 */
enum DaemonPresence: string
{
    /** Something accepted a connection on the command channel: a daemon is running. */
    case UP = 'up';

    /** Nothing accepted a connection: no daemon is running here. */
    case DOWN = 'down';

    /** The channel address could not be formed, so nothing was asked and nothing is known. */
    case UNKNOWN = 'unknown';
}
