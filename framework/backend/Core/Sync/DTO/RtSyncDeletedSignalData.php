<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * RT sync signal data for deleted state.
 * Only state ID.
 */
class RtSyncDeletedSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly string $collectionKey,
        public readonly string $stateId,
        public readonly string $sourceAgentId,
    ) {
    }

    public function toArray(): array
    {
        return [
            'collectionKey' => $this->collectionKey,
            'stateId' => $this->stateId,
            'sourceAgentId' => $this->sourceAgentId,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            collectionKey: $data['collectionKey'] ?? '',
            stateId: $data['stateId'] ?? '',
            sourceAgentId: $data['sourceAgentId'] ?? '',
        );
    }
}
