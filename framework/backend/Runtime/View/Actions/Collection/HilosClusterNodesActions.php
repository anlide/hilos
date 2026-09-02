<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosClusterNodes as StateHilosClusterNodes;
use Hilos\Runtime\State\Item\HilosClusterNode as StateHilosClusterNode;
use Hilos\Runtime\View\Collection\HilosClusterNodes;
use Hilos\Runtime\View\Item\HilosClusterNode;

/**
 * Write API for the cluster picture the master publishes (HIL-337).
 *
 * One write and no more, because the master has one thing to say about a node: this is how I
 * see it now. There is no removal to match it - the registry this mirrors never drops a
 * record, it marks the node offline - and a mirror that could drop rows would answer "there
 * is no such node" for a node that is merely down.
 *
 * @extends RtActions<HilosClusterNode, HilosClusterNodes, StateHilosClusterNodes>
 * @property-read StateHilosClusterNodes $stateCollection
 */
final class HilosClusterNodesActions extends RtActions
{
    /**
     * Publishes how the master sees one node right now.
     *
     * Always called with the full set of fields, never with what changed: the caller mirrors a
     * snapshot rather than tracking edits, and telling apart the first sight of a node from a
     * later one is this method's job, not its caller's. A node whose row did not move costs
     * nothing all the same - {@see StateHilosClusterNode::sync()} diffs the row against the
     * baseline it kept, and an empty diff queues no frame at all. That is why the fields are
     * written and synced rather than handed over as a diff: a diff is taken at its word, and a
     * caller republishing a whole snapshot has no idea which of its values are news.
     *
     * @param string $nodeId Node id, empty on a standalone install
     * @param string $role Node role value
     * @param list<string> $capabilities Capability tags the node declared
     * @param ?string $address Address peers dial to reach the node, or null
     * @param bool $online Whether the node is currently connected
     * @param float $lastSeen Microtime the node was last observed
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     */
    public function publish(
        string $nodeId,
        string $role,
        array $capabilities,
        ?string $address,
        bool $online,
        float $lastSeen,
    ): void {
        $this->ensureCanWrite();

        $state = $this->stateCollection->get($nodeId);
        if ($state === null) {
            $this->addStateToCollection(
                StateHilosClusterNode::create($nodeId, $role, $capabilities, $address, $online, $lastSeen),
            );

            return;
        }

        $state->role = $role;
        $state->capabilities = $capabilities;
        $state->address = $address;
        $state->online = $online;
        $state->lastSeen = $lastSeen;

        $state->sync();
    }
}
