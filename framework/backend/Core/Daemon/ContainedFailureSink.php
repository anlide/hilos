<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\ServerInterface;

/**
 * ContainedFailureSink - master-side seam a guard hands a contained failure to.
 *
 * The master swallows what belongs to one connection so the node keeps serving the rest,
 * and until now the swallowing was the end of it: the failure reached the journal and
 * nobody else. This is the other end - whoever contained it says so here, and the master
 * takes the card on to the project ({@see DaemonManager::onContainedFailure()}). A server
 * therefore learns one narrow door and not the manager, exactly as it does for
 * {@see ConnectionDropper}.
 *
 * Handed to every server at registration through {@see ServerInterface}, not to the ones
 * that happen to descend from {@see AbstractServer}: a server left without the seam would
 * contain its failures in silence, and silence is indistinguishable from a node that has
 * none. The price is that a server outside the hierarchy writes the setter itself.
 *
 * There is no answer to give back - see the void return - because a guard has already
 * decided what to do with the failure by the time it reports it, and a report it could
 * branch on would invite it to decide again.
 */
interface ContainedFailureSink
{
    /**
     * Takes a failure a guard contained, on the way to the project.
     *
     * Called after the journal line and never instead of it: the record is not the
     * project's to replace. Runs on the master loop, so an implementation does what the
     * hook it feeds is allowed to do and nothing slower.
     *
     * @param ContainedFailure $failure Failure, the unit it belongs to and where it happened
     */
    public function reportContainedFailure(ContainedFailure $failure): void;
}
