<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\RtStaleness;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerRtSnapshotMessageDTO - the initial state of one RT collection, daemon to worker.
 *
 * A worker that has just said it reads a collection holds nothing of it, and a delta is no use
 * to a process with no history to apply it to. The master always has one - it applies every
 * frame to its own replica - so it answers a new interest with the collection as it stands.
 *
 * Sent once per collection per worker, not per consumer: the copy is worker-wide, and a second
 * page asking for a collection this worker already holds is reading what is already there.
 *
 * It carries which of the rows are frozen along with the rows themselves (HIL-711), because a
 * worker that came up during a break would otherwise read a frozen copy as current: the frame
 * that froze them was sent before this worker existed, and nothing repeats it.
 */
class WorkerRtSnapshotMessageDTO extends WorkerDTO
{
    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_RT_SNAPSHOT;

    /** @var string Payload key: RT collection this snapshot is of */
    public const string FIELD_COLLECTION_KEY = 'collectionKey';

    /** @var string Payload key: rows of that collection, keyed by state id */
    public const string FIELD_ROWS = 'rows';

    /** @var string Payload key: those of the rows whose source is unreachable, and since when */
    public const string FIELD_STALE_ROWS = 'staleRows';

    /**
     * @param string $collectionKey RT collection the rows belong to
     * @param array<string, array<string, mixed>> $rows Rows by state id, as {@see RtSnapshot::rows()} reads them
     * @param array<string, float> $staleRows Frozen rows by state id, as {@see RtStaleness::staleRows()} reads them
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly array $rows,
        public readonly array $staleRows = [],
    ) {
    }

    /**
     * @return string Message type
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            self::TYPE => self::MESSAGE_TYPE,
            self::FIELD_COLLECTION_KEY => $this->collectionKey,
            self::FIELD_ROWS => $this->rows,
            self::FIELD_STALE_ROWS => $this->staleRows,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * An empty row set is a legitimate snapshot and not a missing one: a collection nobody has
     * written yet exists and is empty, and the reader waiting on it has to be let go.
     *
     * The frozen rows are optional on the wire for the reason every added field of a worker frame
     * is: nothing is frozen on a node that is not in a cluster, which is most of them.
     *
     * @param array<string, mixed> $data Source data (collectionKey, rows, staleRows)
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no collection
     */
    public static function fromArray(array $data): static
    {
        $rows = [];
        $raw = $data[self::FIELD_ROWS] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $stateId => $row) {
                if (is_array($row)) {
                    $rows[(string)$stateId] = $row;
                }
            }
        }

        $staleRows = [];
        $rawStale = $data[self::FIELD_STALE_ROWS] ?? [];
        if (is_array($rawStale)) {
            foreach ($rawStale as $stateId => $since) {
                if (is_numeric($since)) {
                    $staleRows[(string)$stateId] = (float)$since;
                }
            }
        }

        return new static(
            collectionKey: self::requireString($data, self::FIELD_COLLECTION_KEY),
            rows: $rows,
            staleRows: $staleRows,
        );
    }
}
