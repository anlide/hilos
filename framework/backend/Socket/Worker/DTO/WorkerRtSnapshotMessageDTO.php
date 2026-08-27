<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\RtSnapshot;
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
 */
class WorkerRtSnapshotMessageDTO extends WorkerDTO
{
    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_RT_SNAPSHOT;

    /** @var string Payload key: RT collection this snapshot is of */
    public const string FIELD_COLLECTION_KEY = 'collectionKey';

    /** @var string Payload key: rows of that collection, keyed by state id */
    public const string FIELD_ROWS = 'rows';

    /**
     * @param string $collectionKey RT collection the rows belong to
     * @param array<string, array<string, mixed>> $rows Rows by state id, as {@see RtSnapshot::rows()} reads them
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly array $rows,
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
        ];
    }

    /**
     * Creates DTO from array.
     *
     * An empty row set is a legitimate snapshot and not a missing one: a collection nobody has
     * written yet exists and is empty, and the reader waiting on it has to be let go.
     *
     * @param array<string, mixed> $data Source data (collectionKey, rows)
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

        return new static(
            collectionKey: self::requireString($data, self::FIELD_COLLECTION_KEY),
            rows: $rows,
        );
    }
}
