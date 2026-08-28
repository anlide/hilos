<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Database\Context\DbContext;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerDbInterestReadyMessageDTO - the master's word that one DB collection is addressed here now.
 *
 * The database twin of {@see WorkerRtSnapshotMessageDTO}, and the difference between them is the
 * whole reason this one carries nothing: the rows of a DB collection sit in a database every
 * process shares, so a worker that has just asked to read one needs no copy handed to it. What it
 * does need is the moment after which frames about that collection are written to its link, and
 * that moment is this frame.
 *
 * Sent once per collection per worker, like the snapshot: the copy a worker keeps is worker-wide,
 * and a second page asking for a collection this worker already reads reads what is already there.
 *
 * It is what the worker drops its cached copy on ({@see DbContext::reHydrateCollection()}) before
 * answering any read. Everything written before the master wrote this worker into the map is in
 * the database and comes back with that re-read; everything written after it arrives as a frame.
 */
class WorkerDbInterestReadyMessageDTO extends WorkerDTO
{
    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_DB_INTEREST_READY;

    /** @var string Payload key: DB collection this confirmation is about */
    public const string FIELD_COLLECTION_KEY = 'collectionKey';

    /**
     * @param string $collectionKey DB collection the worker may now read
     */
    public function __construct(
        public readonly string $collectionKey,
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
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (collectionKey)
     * @return static DTO instance
     * @throws InvalidFormatException When the payload names no collection
     */
    public static function fromArray(array $data): static
    {
        return new static(collectionKey: self::requireString($data, self::FIELD_COLLECTION_KEY));
    }
}
