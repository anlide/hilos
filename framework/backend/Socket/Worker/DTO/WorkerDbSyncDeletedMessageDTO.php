<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerDbSyncDeletedMessageDTO - DB sync deleted message from daemon to worker.
 */
class WorkerDbSyncDeletedMessageDTO extends WorkerDTO implements WorkerDbSyncMessageInterface
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_DB_SYNC_DELETED;

    /**
     * Creates worker DB sync deleted message.
     */
    public function __construct(
        public readonly DbSyncDeletedSignalData $signalData,
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
     */
    public static function fromArray(array $data): static
    {
        $signalData = $data['signalData'] ?? [];

        return new static(
            signalData: DbSyncDeletedSignalData::fromArray(is_array($signalData) ? $signalData : []),
        );
    }
}
