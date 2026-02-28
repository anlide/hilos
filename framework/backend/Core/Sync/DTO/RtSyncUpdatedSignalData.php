<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * RT sync signal data for updated state.
 * Only changed fields.
 */
class RtSyncUpdatedSignalData extends BaseDTO implements SignalDataInterface
{
    public function __construct(
        public readonly string $collectionKey,
        public readonly string $stateId,
        /** @var array<string, mixed> Only changed fields => values */
        public readonly array $row,
    ) {
    }

    public function toArray(): array
    {
        return [
            'collectionKey' => $this->collectionKey,
            'stateId' => $this->stateId,
            'row' => $this->row,
        ];
    }

    public static function fromArray(array $data): static
    {
        return new self(
            collectionKey: $data['collectionKey'] ?? '',
            stateId: $data['stateId'] ?? '',
            row: $data['row'] ?? [],
        );
    }
}
