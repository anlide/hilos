<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * RT sync updated message from daemon to worker.
 */
class WorkerRtSyncUpdatedMessageDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_RT_SYNC_UPDATED;

    /**
     * Creates RT sync updated message DTO.
     *
     * @param array<string, mixed> $signalData Payload (collectionKey, stateId, row diff)
     */
    public function __construct(
        public readonly array $signalData,
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
            'signalData' => $this->signalData,
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
        return new self(
            signalData: $data['signalData'] ?? [],
        );
    }
}
