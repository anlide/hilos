<?php

declare(strict_types=1);

namespace Demo\Cluster\Runtime\State\Item;

use Demo\Cluster\Runtime\View\Context\ClusterRtContext;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Item\RtState;

/**
 * Runtime status of one fleet worker: what it has done, and what it can see.
 *
 * One row per fleet member, written by that member alone and replicated to every other node.
 * The fleet is what makes this demo's RT worth watching — several nodes writing rows of one
 * collection, each owning its own — and the acceptance scenarios read these rows back on
 * nodes that never wrote them.
 */
final class WorkerStatus extends RtState
{
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
     * Runtime collection key for fleet worker status rows.
     *
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return ClusterRtContext::workerStatuses;
    }

    /**
     * @param array<string, mixed> $diff Partial update
     * @throws InvalidFormatException When a field the diff does carry holds the wrong type
     */
    public function applyDiff(array $diff): void
    {
        if (array_key_exists(self::jobsDone, $diff)) {
            $this->jobsDone = self::requireInt($diff, self::jobsDone);
        }
        if (array_key_exists(self::rowsSeen, $diff)) {
            $this->rowsSeen = self::requireInt($diff, self::rowsSeen);
        }
        if (array_key_exists(self::updatedAt, $diff)) {
            $this->updatedAt = self::requireInt($diff, self::updatedAt);
        }
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

    /**
     * Reads one field of a runtime row or diff that the row cannot be built without.
     *
     * A runtime row is written by {@see toArray()} on another node, so a key that is absent or
     * holds another type is a row that lost the field on the way, not a row that never had it.
     * A cast would report a member with no index and no jobs done, which reads as a fleet member
     * that is idle rather than as a frame that is broken.
     *
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return string Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-string
     */
    private static function requireString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidFormatException('Runtime row carries no string under key ' . $key);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source Runtime row or diff
     * @param string $key Row key holding the field
     * @return int Value stored under the key
     * @throws InvalidFormatException When the key is absent or holds a non-integer
     */
    private static function requireInt(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidFormatException('Runtime row carries no integer under key ' . $key);
        }

        return $value;
    }
}
