<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerRtSnapshotRequestDTO - a worker asking its master for one more snapshot of an RT collection
 * it reads.
 *
 * The first snapshot of a collection needs no request: the worker's word that it reads the
 * collection is the request ({@see WorkerSourceInterestDTO}). This one is for a copy that lost its
 * base while it was read - the stream held a row after refusing its operation, or a staged
 * snapshot was canceled before it was published - and asks the master to put a new snapshot of the
 * collection into the worker's stream ({@see RtSyncStreamChannel::sendSnapshot()}), cut where the
 * master takes the request. One request per collection waits at a time; a snapshot refused by
 * validation is not asked for again on its own, since the same cut would be refused again.
 */
class WorkerRtSnapshotRequestDTO extends WorkerDTO
{
    // Message type
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_RT_SNAPSHOT_REQUEST;

    /** @var string Payload key: RT collection the worker asks a snapshot of */
    public const string FIELD_COLLECTION_KEY = 'collectionKey';

    /**
     * @param string $collectionKey RT collection the worker asks a snapshot of
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
