<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

use Closure;

/**
 * Leader-side soft-state map of hosted, waiting and explicitly canceled agents, keyed by id.
 *
 * Only an elected leader writes this view; it is coordination state, never persisted,
 * and is rebuilt on a leadership change from real hosted reports and one complete retained
 * snapshot, after the rebuild/self-fence barrier. Keyed by agent
 * id so a placement is upserted and forgotten by the one key the agent manager uses.
 * Stopped tombstones count as tracked intent, so automatic declaration passes leave them alone;
 * they hold no capacity and are excluded from working placement inspection. Waiting records are
 * the source for a local, derived retry schedule, recreated by the new leader. Inheritance
 * is limited to the latest complete copy available from surviving nodes, not a durable log.
 */
final class PlacementRegistry
{
    /** @var array<string, PlacementRecord> Hosted, waiting and canceled agents keyed by agent id */
    private array $records = [];

    /** Changes to the tracked placement view, checked without walking its records. */
    private int $revision = 0;

    /** Releases of live reservations, independent of revisions to failure diagnoses. */
    private int $capacityReleaseRevision = 0;

    /** @param ?Closure(?string): void $changed Actual address change, null for a reset or released capacity */
    public function __construct(private readonly ?Closure $changed = null)
    {
    }

    /**
     * Upserts a placement record, replacing any earlier record for the same agent.
     *
     * A record equal by value to the one already held changes nothing, the revision included.
     * Equal means every field, the diagnosis among them (HIL-447): a second failure of the same
     * agent on the same node for ANOTHER reason is news and replaces the first.
     *
     * @param PlacementRecord $record Placement record to store
     */
    public function put(PlacementRecord $record): void
    {
        $id = $record->agentId();
        $old = $this->records[$id] ?? null;
        if ($old !== null && $old->sameAs($record)) {
            return;
        }
        $capacityReleased = $old !== null && $old->nodeId !== null && $old->state->hostsAgent()
            && (!$record->state->hostsAgent() || $record->nodeId !== $old->nodeId);
        if ($capacityReleased) {
            $this->capacityReleaseRevision++;
        }
        $this->records[$id] = $record;
        $this->revision++;
        ($this->changed)?->__invoke($id);
        if ($capacityReleased) {
            ($this->changed)?->__invoke(null);
        }
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
        $old = $this->records[$agentId];
        $capacityReleased = $old->nodeId !== null && $old->state->hostsAgent();
        if ($capacityReleased) {
            $this->capacityReleaseRevision++;
        }
        unset($this->records[$agentId]);
        $this->revision++;
        ($this->changed)?->__invoke($agentId);
        if ($capacityReleased) {
            ($this->changed)?->__invoke(null);
        }
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
     * Returns every tracked record, including cancellation tombstones.
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
        foreach ($this->records as $record) {
            if ($record->nodeId !== null && $record->state->hostsAgent()) {
                $this->capacityReleaseRevision++;
                break;
            }
        }
        $this->records = [];
        $this->revision++;
        ($this->changed)?->__invoke(null);
    }

    /** @return int Revision of this leader-side view */
    public function revision(): int
    {
        return $this->revision;
    }

    /** @return int Revision of released live capacity, never of diagnostic edits */
    public function capacityReleaseRevision(): int
    {
        return $this->capacityReleaseRevision;
    }

    /**
     * @return int Number of tracked records, including cancellation tombstones
     */
    public function count(): int
    {
        return count($this->records);
    }
}
