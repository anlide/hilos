<?php

declare(strict_types=1);

namespace Hilos\Runtime;

use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Item\HilosConnection;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Strikes the connection rows whose socket is gone, against the roster of a starting agent (HIL-664).
 *
 * A connection row is written by the agent that owns the collection, so while that agent is
 * stopped nobody may write it: a tab closed in that window leaves its row behind, and the node
 * goes on reporting a person as present who left. The window is not exotic - it is every
 * protected-mode freeze, every restart of a crashed agent, and the gap between the node coming
 * up and its agents being linked.
 *
 * The cure is at the other end of the window rather than inside it: the master hands each agent
 * start the accept keys it still holds sockets for, and everything the collection has that the
 * roster does not is struck out here. That makes the thaw, the restart and the first start one
 * behavior - on a first start the roster is empty and there is nothing to strike, which is the
 * same answer for the same reason.
 *
 * It is a class of its own rather than a method on the worker manager because its whole input is
 * a list of strings, and a test of it should not have to raise a worker.
 */
final class ConnectionRosterReconciler
{
    /**
     * Removes every connection row whose accept key is not on the roster.
     *
     * Answers a silent zero for anyone with nothing to reconcile: a project that mounts no
     * connections or does not represent them, and an agent that does not own the collection -
     * which is most of them, since the roster rides every agent start and only one agent per
     * node writes the connections.
     *
     * @param list<string> $liveAcceptKeys Accept keys the node still holds sockets for
     * @return int Rows struck out
     *
     * @throws RtActionsCollectionNameNullException When the collection name is unavailable
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When a struck connection is not attached to a collection
     * @throws RtTruthSourceWriteNotAllowedException When the caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws HilosException Whatever the project's own close-time cleanup raises
     */
    public static function reconcile(array $liveAcceptKeys): int
    {
        $registry = Hilos::$rt?->connectionsRegistry();
        if ($registry === null || !self::ownsCollection($registry->getCollectionName())) {
            return 0;
        }

        $live = array_fill_keys($liveAcceptKeys, true);
        $staleKeys = [];
        foreach ($registry->getStateCollection() as $acceptKey => $connection) {
            if (!isset($live[$acceptKey])) {
                $staleKeys[] = (string)$acceptKey;
            }
        }

        // Struck after the walk, not inside it: removal changes the very collection being read,
        // and the two passes keep the reading of it out of that argument entirely.
        $struck = 0;
        foreach ($staleKeys as $acceptKey) {
            $connection = $registry[$acceptKey];
            if ($connection instanceof HilosConnection) {
                $connection->actions->unregister();
                $struck++;
            }
        }

        return $struck;
    }

    /**
     * Whether the agent this call runs for is the truth source of the connections collection.
     *
     * Asked before the walk rather than met as a refusal on the first write: the reconcile is
     * offered to every agent start, and for an agent that owns something else the answer is
     * "nothing to do here", not an error.
     *
     * @param ?string $collectionName Name the connections collection is mounted under, or null when unnamed
     * @return bool True when the current agent may write that collection
     */
    private static function ownsCollection(?string $collectionName): bool
    {
        $agentId = ExecutionContext::currentAgentId();
        if ($collectionName === null || $agentId === null) {
            return false;
        }

        return in_array($collectionName, RtTruthSourceRegistry::collectionsOf($agentId), true);
    }
}
