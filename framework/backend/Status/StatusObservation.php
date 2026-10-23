<?php

declare(strict_types=1);

namespace Hilos\Status;

use Hilos\Core\Agent\Config\AgentScope;
use Hilos\DaemonSection\ClusterDaemonPicture;

/** One component's slot verdict; null live/observed values cannot drive reconciliation. */
final readonly class StatusObservation
{
    /**
     * @param string $componentKey Declared component identity
     * @param int $expectedInstances Expected node slots, or one cluster slot
     * @param ?int $liveInstances Occupied slots, null until every online roster is usable
     * @param ?int $observedAt Local acceptance moment in epoch milliseconds UTC
     */
    private function __construct(
        public string $componentKey,
        public int $expectedInstances,
        public ?int $liveInstances,
        public ?int $observedAt,
    ) {
    }

    /**
     * Counts occupied slots rather than copies; another node's copy cannot fill a NODE slot.
     * Foreign report clocks establish identity only and are never compared with the local clock.
     *
     * @param StatusComponentDefinition $component Permanent, non-indexed catalog entry
     * @param ClusterDaemonPicture $picture Owner's whole node buffer, including retained offline members
     * @param bool $membershipComplete Whether a full snapshot has arrived in this observation lifetime
     * @param array<string, true> $freshNodes Online node ids with a usable report newer than their baseline
     * @param ?int $observedAt Local acceptance moment of the buffered facts, in milliseconds UTC
     * @return self Known slot counts, or an explicitly unknown observation
     */
    public static function fromPicture(
        StatusComponentDefinition $component,
        ClusterDaemonPicture $picture,
        bool $membershipComplete,
        array $freshNodes,
        ?int $observedAt,
    ): self {
        $nodes = $picture->nodes();
        $expected = $component->scope === AgentScope::CLUSTER ? 1 : max(1, count($nodes));
        $unknown = new self($component->componentKey, $expected, null, null);
        if (!$membershipComplete || $nodes === [] || $observedAt === null) {
            return $unknown;
        }

        $live = 0;
        foreach ($nodes as $node) {
            if (!$node->online) {
                continue;
            }
            $processes = $node->slot?->picture->processes;
            if ($processes === null || !isset($freshNodes[$node->nodeId])) {
                return $unknown;
            }
            foreach ($processes->workers as $worker) {
                foreach ($worker->agents as $agent) {
                    if ($agent->id === $component->agentType) {
                        ++$live;
                        continue 3;
                    }
                }
            }
        }

        return new self(
            $component->componentKey,
            $expected,
            $component->scope === AgentScope::CLUSTER ? min(1, $live) : $live,
            $observedAt,
        );
    }
}
