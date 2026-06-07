<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * WorkerRtSyncDeletedMessageDTO - RT sync deleted message from daemon to worker.
 */
class WorkerRtSyncDeletedMessageDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_RT_SYNC_DELETED;

    /**
     * Creates RT sync deleted message DTO.
     */
    public function __construct(
        public readonly RtSyncDeletedSignalData $signalData,
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
     * @return array<string, mixed> DTO data as array
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
            signalData: RtSyncDeletedSignalData::fromArray(is_array($signalData) ? $signalData : []),
        );
    }
}
