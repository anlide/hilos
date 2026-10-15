<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use LogicException;

/**
 * The resource cost an agent brings to placement (HIL-182, HIL-448, HIL-447): how much of each
 * named resource it consumes from the node it lands on.
 *
 * This is the task side of the resource-aware model. A boolean capability tag list stays the
 * hard gate (an agent keeps declaring it through {@see AgentDaemonInterface::requiredCapabilities()});
 * this profile adds the consumable layer on top of it. The cost is a reservation: the leader
 * subtracts it from the node's declared capacity ({@see NodeCapacities}) for as long as the
 * agent's placement is alive, and a node whose free capacity falls below the cost is no longer a
 * candidate for it.
 *
 * A profile comes in two readings. What an agent declares ({@see none()}, {@see costs()}) is its
 * project cost and nothing more. What placement charges is {@see forPlacement()}: the project
 * cost plus one {@see RESOURCE_SLOTS} — the slot every placed agent takes, so a node that
 * declares `slots=N` runs at most N of them, and an agent with no project cost is still counted.
 * The slot is placement's to add, never the author's: a profile that names it is refused, so the
 * mandatory unit can be neither canceled nor charged twice.
 *
 * The cost is read on the leader's master loop, every time a node is chosen, so it must be
 * derived only from the agent type, its index and constants — never from the database, a file
 * or the network (docs/agents/antipatterns/heavy-work-in-master.md). An agent that does not know
 * its appetite in advance declares a reservation, not a measurement.
 *
 * The default is {@see none()}: an agent that declares nothing adds no project cost, and placement
 * charges it the one slot alone.
 */
final class ResourceProfile
{
    /** @var string Resource every placed agent consumes one unit of, added by placement and never declared by an agent */
    public const string RESOURCE_SLOTS = 'slots';

    /** @var float Slots one placed agent takes */
    private const float SLOTS_PER_AGENT = 1.0;

    /**
     * @param array<string, float> $costs Cost keyed by resource name, each value above zero
     */
    private function __construct(
        public readonly array $costs,
    ) {
    }

    /**
     * The empty profile: the agent adds no project cost to the slot placement charges.
     *
     * @return self Empty profile
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Builds a profile from the agent's cost per resource. A resource that costs zero is dropped,
     * so an empty map and a map of zeros mean the same agent with no project cost.
     *
     * @param array<string, float> $costs Cost keyed by resource name
     * @return self Resource profile
     * @throws LogicException When a cost is negative, or the map names the slot placement reserves for itself
     */
    public static function costs(array $costs): self
    {
        $kept = [];
        foreach ($costs as $key => $cost) {
            if ($key === self::RESOURCE_SLOTS) {
                throw new LogicException("Resource '{$key}' is reserved: placement charges every agent one, an agent declares none");
            }

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
     * Returns the cost placement charges for this profile: the project cost plus the one slot every
     * placed agent takes. A profile that already carries the slot is returned as it is, so
     * normalizing twice never charges two.
     *
     * @return self Effective placement cost
     */
    public function forPlacement(): self
    {
        if (isset($this->costs[self::RESOURCE_SLOTS])) {
            return $this;
        }

        return new self($this->costs + [self::RESOURCE_SLOTS => self::SLOTS_PER_AGENT]);
    }

    /**
     * Reports whether the profile consumes nothing at all.
     *
     * @return bool True when no resource carries a cost; never for a profile placement charges
     */
    public function isEmpty(): bool
    {
        return $this->costs === [];
    }
}
