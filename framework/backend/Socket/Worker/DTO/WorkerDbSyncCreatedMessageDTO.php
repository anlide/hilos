<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker\DTO;

use Hilos\Constants\WorkerConstants;
use Hilos\Socket\Worker\WorkerDTO;

/**
 * DB sync created message from daemon to worker.
 */
class WorkerDbSyncCreatedMessageDTO extends WorkerDTO
{
    public const string MESSAGE_TYPE = WorkerConstants::MESSAGE_DB_SYNC_CREATED;

    /**
     * Creates worker DB sync created message.
     *
     * @param array<string, mixed> $signalData Signal data (collectionKey, idString, row)
     */
    public function __construct(
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
