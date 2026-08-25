<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Cluster\WorkerPlacement;
use Hilos\Core\Router\SignalRouter;

/**
 * AgentLocation - where a placement lookup says one agent runs.
 *
 * The answer {@see WorkerPlacement::locate()} gives, and the reason it is a value rather than a
 * nullable node id: a caller has to tell "here" from "I do not know", and null said both. The
 * pair is carried together because neither half is meaningful alone — {@see AgentLocationKind::Node}
 * is the only case with a node id, and the other two have none to carry.
 *
 * Read by the routing post-pass ({@see SignalRouter}) and by the master's own send path, both of
 * which turn the three cases into three destinations. Never persisted, never on the wire: it is
 * computed per lookup from live placement state and consumed immediately.
 */
final readonly class AgentLocation
{
    /**
     * @param AgentLocationKind $kind Which of the three answers this is
     * @param ?string $nodeId Hosting node id when the kind is {@see AgentLocationKind::Node}, else null
     */
    private function __construct(
        public AgentLocationKind $kind,
        public ?string $nodeId,
    ) {
    }

    /**
     * The agent runs on this node.
     *
     * @return self Location naming no node, because the node is this one
     */
    public static function here(): self
    {
        return new self(AgentLocationKind::Here, null);
    }

    /**
     * The agent runs on another node.
     *
     * @param string $nodeId Id of the node hosting the agent
     * @return self Location carrying that node id
     */
    public static function onNode(string $nodeId): self
    {
        return new self(AgentLocationKind::Node, $nodeId);
    }

    /**
     * Nobody knows where the agent runs.
     *
     * @return self Location naming no node, because none is known
     */
    public static function unknown(): self
    {
        return new self(AgentLocationKind::Unknown, null);
    }
}
