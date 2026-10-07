<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

/**
 * Leader-side soft-state map of every placed agent to the node hosting it.
 *
 * Only an elected leader keeps this view; it is coordination state, never persisted,
 * and is rebuilt from scratch on a leadership change by querying the nodes for the
 * agents they host (the same "coordination not persisted, rebuild from the mesh"
 * stance the membership registry and the consensus coordinator take). Keyed by agent
 * id so a placement is upserted and forgotten by the one key the agent manager uses.
 */
final class PlacementRegistry
{
    /** @var array<string, PlacementRecord> Placed agents keyed by agent id */
    private array $records = [];

    /** Changes to the tracked placement view, checked without walking its records. */
    private int $revision = 0;

    /**
     * Upserts a placement record, replacing any earlier record for the same agent.
     *
     * @param PlacementRecord $record Placement record to store
     */
    public function put(PlacementRecord $record): void
    {
        $id = $record->agentId();
        $old = $this->records[$id] ?? null;
        if (
            $old !== null
            && $old->nodeId === $record->nodeId
            && $old->state === $record->state
        ) {
            return;
        }
        $this->records[$id] = $record;
        $this->revision++;
    }

    /**
     * Drops the placement record for an agent id; a no-op when none is tracked.
     *
     * @param string $agentId Agent id to forget
     */
    public function forget(string $agentId): void
    {
        if (!isset($this->records[$agentId])) {
            return;
        }
        unset($this->records[$agentId]);
        $this->revision++;
    }

    /**
     * Returns the placement record for an agent id, or null when none is tracked.
     *
     * @param string $agentId Agent id to look up
     * @return ?PlacementRecord Placement record, or null
     */
    public function get(string $agentId): ?PlacementRecord
    {
        return $this->records[$agentId] ?? null;
    }

    /**
     * Returns every tracked placement record.
     *
     * @return list<PlacementRecord> Placement records
     */
    public function all(): array
    {
        return array_values($this->records);
    }

    /**
     * Discards the whole view; used before a leader rebuilds it from node reports.
     */
    public function clear(): void
    {
        if ($this->records === []) {
            return;
        }
        $this->records = [];
        $this->revision++;
    }

    /** @return int Revision of this leader-side view */
    public function revision(): int
    {
        return $this->revision;
    }

    /**
     * @return int Number of tracked placements
     */
    public function count(): int
    {
        return count($this->records);
    }
}
