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
use Hilos\HilosException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;

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
     * nothing all the same: the door compares the whole snapshot with the row before it admits
     * anything, carries the fields that moved alone, and a snapshot that moved nothing reserves,
     * publishes and sends nothing at all.
     *
     * @param string $nodeId Node id, empty on a standalone install
     * @param string $name Non-empty readable node name
     * @param string $role Node role value
     * @param list<string> $capabilities Capability tags the node declared
     * @param ?string $address Address peers dial to reach the node, or null
     * @param bool $online Whether the node is currently connected
     * @param float $lastSeen Microtime the node was last observed
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws HilosException Whatever preparing the RT write's immutable operation raises
     * @throws RtWriteBackpressureException When the source's ordinary RT FIFO has no room; nothing was written
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws InvalidArgumentException When the queued RT-sync signal cannot be named
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     */
    public function publish(
        string $nodeId,
        string $name,
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
                StateHilosClusterNode::create($nodeId, $name, $role, $capabilities, $address, $online, $lastSeen),
            );

            return;
        }

        $this->applyDiffToState($state, [
            StateHilosClusterNode::name => $name,
            StateHilosClusterNode::role => $role,
            StateHilosClusterNode::capabilities => $capabilities,
            StateHilosClusterNode::address => $address,
            StateHilosClusterNode::online => $online,
            StateHilosClusterNode::lastSeen => $lastSeen,
        ]);
    }
}
