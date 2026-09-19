<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use LogicException;

/**
 * The resource cost an agent brings to placement (HIL-182, HIL-448): how much of each named
 * resource it consumes from the node it lands on.
 *
 * This is the task side of the resource-aware model. A boolean capability tag list stays the
 * hard gate (an agent keeps declaring it through {@see AgentDaemonInterface::requiredCapabilities()});
 * this profile adds the consumable layer on top of it. The cost is a reservation: the leader
 * subtracts it from the node's declared capacity ({@see NodeCapacities}) for as long as the
 * agent's placement is alive, and a node whose free capacity falls below the cost is no longer a
 * candidate for it.
 *
 * The cost is read on the leader's master loop, every time a node is chosen, so it must be
 * derived only from the agent type, its index and constants — never from the database, a file
 * or the network (docs/agents/antipatterns/heavy-work-in-master.md). An agent that does not know
 * its appetite in advance declares a reservation, not a measurement.
 *
 * The default is {@see none()}: an agent that declares nothing consumes nothing, and the policy
 * spreads such agents over the nodes by head count.
 */
final class ResourceProfile
{
    /**
     * @param array<string, float> $costs Cost keyed by resource name, each value above zero
     */
    private function __construct(
        public readonly array $costs,
    ) {
    }

    /**
     * The empty profile: the agent consumes nothing.
     *
     * @return self Empty profile
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Builds a profile from the agent's cost per resource. A resource that costs zero is dropped,
     * so an empty map and a map of zeros mean the same free agent.
     *
     * @param array<string, float> $costs Cost keyed by resource name
     * @return self Resource profile
     * @throws LogicException When a cost is negative
     */
    public static function costs(array $costs): self
    {
        $kept = [];
        foreach ($costs as $key => $cost) {
            if ($cost < 0.0) {
                throw new LogicException("Resource cost of '{$key}' must not be negative, got {$cost}");
            }

            if ($cost > 0.0) {
                $kept[$key] = (float)$cost;
            }
        }

        return new self($kept);
    }

    /**
     * Reports whether the profile consumes nothing at all.
     *
     * @return bool True when no resource carries a cost
     */
    public function isEmpty(): bool
    {
        return $this->costs === [];
    }
}
