<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerRtSyncCreatedMessageDTO - RT sync created message from daemon to worker.
 */
class WorkerRtSyncCreatedMessageDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_RT_SYNC_CREATED;

    /**
     * Creates RT sync created message DTO.
     */
    public function __construct(
        public readonly RtSyncCreatedSignalData $signalData,
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
            signalData: RtSyncCreatedSignalData::fromArray(is_array($signalData) ? $signalData : []),
        );
    }
}
