<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerDbSyncDeletedMessageDTO - DB sync deleted message from daemon to worker.
 */
class WorkerDbSyncDeletedMessageDTO extends WorkerDTO implements WorkerDbSyncMessageInterface
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_DB_SYNC_DELETED;

    /**
     * @var string Payload key: node the write happened on.
     *
     * Absent when the fact is this node's own, which is how the applying side tells a row that
     * arrived over the mesh from one this node produced (HIL-670).
     */
    public const string FIELD_ORIGIN_NODE_ID = 'originNodeId';

    /**
     * Creates worker DB sync deleted message.
     *
     * @param DbSyncDeletedSignalData $signalData Sync fact this frame carries
     * @param ?string $originNodeId Node the write happened on, or null when it was this one
     */
    public function __construct(
        public readonly DbSyncDeletedSignalData $signalData,
        public readonly ?string $originNodeId = null,
    ) {
    }

    /**
     * Returns message type.
     *
     * @return string Message type identifier.
     */
    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array.
     */
    public function toArray(): array
    {
        return [
            self::TYPE => $this->getType(),
            'signalData' => $this->signalData->toArray(),
            self::FIELD_ORIGIN_NODE_ID => $this->originNodeId,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data with signalData key.
     * @return static DTO instance.
     * @throws InvalidFormatException When the payload carries no sync fact of the shape this message wraps
     */
    public static function fromArray(array $data): static
    {
        $signalData = $data['signalData'] ?? [];
        $originNodeId = $data[self::FIELD_ORIGIN_NODE_ID] ?? null;

        return new static(
            signalData: DbSyncDeletedSignalData::fromArray(is_array($signalData) ? $signalData : []),
            originNodeId: is_string($originNodeId) ? $originNodeId : null,
        );
    }
}
