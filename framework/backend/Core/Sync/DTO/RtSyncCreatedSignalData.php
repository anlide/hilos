<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * RT sync signal data for created state.
 * Full state data.
 */
class RtSyncCreatedSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly string $collectionKey,
        public readonly string $stateId,
        /** @var array<string, mixed> Full state data */
        public readonly array $row,
        public readonly string $sourceAgentId,
    ) {
    }

    public function toArray(): array
    {
        return [
            'collectionKey' => $this->collectionKey,
            'stateId' => $this->stateId,
            'row' => $this->row,
            'sourceAgentId' => $this->sourceAgentId,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            collectionKey: $data['collectionKey'] ?? '',
            stateId: $data['stateId'] ?? '',
            row: $data['row'] ?? [],
            sourceAgentId: $data['sourceAgentId'] ?? '',
        );
    }
}
