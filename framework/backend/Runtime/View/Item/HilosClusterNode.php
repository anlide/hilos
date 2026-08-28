<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\HilosClusterNode as StateHilosClusterNode;
use Hilos\Runtime\View\Actions\Collection\HilosClusterNodesActions;

/**
 * Read-only wrapper over one cluster node as this node's master observed it (HIL-337).
 *
 * What a worker gets when it walks the cluster: who the node is, what it declared it can do,
 * where peers reach it, and whether the master still saw it a moment ago. The row carries no
 * write API of its own - publishing the picture is the master's act over the whole collection
 * ({@see HilosClusterNodesActions}), and a worker holding one of these rows is by definition
 * not the writer.
 *
 * @extends RtItem<StateHilosClusterNode>
 *
 * @property-read string $nodeId Id of the node, empty on a standalone install
 * @property-read string $role Self-declared role of the node, a NodeRole value
 * @property-read list<string> $capabilities Capability tags the node declared
 * @property-read ?string $address Address peers dial to reach the node, or null
 * @property-read bool $online Whether the master saw the node as connected when it last published
 * @property-read float $lastSeen Microtime the node was last observed
 */
final class HilosClusterNode extends RtItem
{
    /**
     * @param StateHilosClusterNode $state Backing runtime state
     */
    public function __construct(StateHilosClusterNode $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|array<int, string>|bool|float|null Property value
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws RtItemActionsClassException When the item actions class is missing or invalid
     */
    public function __get(string $name): string|array|bool|float|null
    {
        return match ($name) {
            StateHilosClusterNode::nodeId => $this->_state->nodeId,
            StateHilosClusterNode::role => $this->_state->role,
            StateHilosClusterNode::capabilities => $this->_state->capabilities,
            StateHilosClusterNode::address => $this->_state->address,
            StateHilosClusterNode::online => $this->_state->online,
            StateHilosClusterNode::lastSeen => $this->_state->lastSeen,
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
