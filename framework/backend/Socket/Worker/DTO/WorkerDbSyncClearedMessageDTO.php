<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerDbSyncClearedMessageDTO - DB sync cleared message from daemon to worker.
 */
class WorkerDbSyncClearedMessageDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_DB_SYNC_CLEARED;

    /**
     * Creates worker DB sync cleared message.
     */
    public function __construct(
        public readonly DbSyncClearedSignalData $signalData,
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
            signalData: DbSyncClearedSignalData::fromArray(is_array($signalData) ? $signalData : []),
        );
    }
}
