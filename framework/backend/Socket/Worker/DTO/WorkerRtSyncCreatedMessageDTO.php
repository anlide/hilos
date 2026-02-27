<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * RT sync created message from daemon to worker.
 */
class WorkerRtSyncCreatedMessageDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_RT_SYNC_CREATED;

    public function __construct(
        /** @param array<string, mixed> collectionKey, stateId, row, sourceAgentId */
        public readonly array $signalData,
    ) {
    }

    public function getType(): string
    {
        return self::MESSAGE_TYPE;
    }

    public function toArray(): array
    {
        return [
            self::TYPE => $this->getType(),
            'signalData' => $this->signalData,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            signalData: $data['signalData'] ?? [],
        );
    }
}
