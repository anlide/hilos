<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Cluster\Probe\FleetProbeAgent;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Collection\HilosProbeFleetStatuses;

/**
 * Runtime status of one member of the cluster probe fleet: what it has done, and what it can see.
 *
 * One row per fleet member ({@see FleetProbeAgent}), written by that member alone and replicated
 * to every other node. The fleet is what makes cross-node RT worth watching on a cluster stand —
 * several nodes writing rows of one collection, each owning its own — and the acceptance
 * scenarios read these rows back on nodes that never wrote them.
 *
 * Framework-owned runtime state mounted for every project ({@see HilosProbeFleetStatuses}), and
 * silent until a probe writes: nothing but the fleet does, and the fleet starts only on a
 * clustered node of a non-production environment.
 */
final class HilosProbeFleetStatus extends RtState
{
    /** Runtime collection key mounted by the framework and used for RT sync. */
    public const string RT_COLLECTION = 'hilosProbeFleetStatuses';

    public const string workerIndex = 'workerIndex';
    public const string jobsDone = 'jobsDone';
    public const string rowsSeen = 'rowsSeen';
    public const string updatedAt = 'updatedAt';

    /** Fleet member index this row belongs to, and its row id. */
    private(set) string $workerIndex = '';

    /** Synthetic jobs this member has finished since it started. */
    public int $jobsDone = 0;

    /** How many rows of this collection the member itself could see when it last reported. */
    public int $rowsSeen = 0;

    /** Unix time of the last report. */
    public int $updatedAt = 0;

    /**
     * @param string $workerIndex Fleet member index
     * @return static Fresh status row
     */
    public static function create(string $workerIndex): static
    {
        $instance = new static();
        $instance->workerIndex = $workerIndex;
        $instance->updatedAt = time();
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Hydrated status row
     * @throws InvalidFormatException When the row is missing a field the status is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->workerIndex = self::requireString($row, self::workerIndex);
        $instance->jobsDone = self::requireInt($row, self::jobsDone);
        $instance->rowsSeen = self::requireInt($row, self::rowsSeen);
        $instance->updatedAt = self::requireInt($row, self::updatedAt);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Runtime collection key for the probe fleet status rows.
     *
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @throws InvalidFormatException When a field the diff does carry holds the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->jobsDone = self::patchInt($diff, self::jobsDone, $this->jobsDone);
        $this->rowsSeen = self::patchInt($diff, self::rowsSeen, $this->rowsSeen);
        $this->updatedAt = self::patchInt($diff, self::updatedAt, $this->updatedAt);
    }

    /**
     * @return string Runtime row id, the fleet member index
     */
    public function getId(): string
    {
        return $this->workerIndex;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::workerIndex => $this->workerIndex,
            self::jobsDone => $this->jobsDone,
            self::rowsSeen => $this->rowsSeen,
            self::updatedAt => $this->updatedAt,
        ];
    }
}
