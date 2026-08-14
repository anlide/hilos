<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerDbSyncUpdatedMessageDTO - DB sync updated message from daemon to worker.
 */
class WorkerDbSyncUpdatedMessageDTO extends WorkerDTO implements WorkerDbSyncMessageInterface
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_DB_SYNC_UPDATED;

    /**
     * Creates worker DB sync updated message.
     */
    public function __construct(
        public readonly DbSyncUpdatedSignalData $signalData,
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

        return new static(
            signalData: DbSyncUpdatedSignalData::fromArray(is_array($signalData) ? $signalData : []),
        );
    }
}
