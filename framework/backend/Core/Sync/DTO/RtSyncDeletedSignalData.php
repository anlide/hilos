<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * RtSyncDeletedSignalData - RT sync signal data for deleted state.
 *
 * Carries state ID plus optional previous runtime row data.
 */
class RtSyncDeletedSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates RT sync deleted signal data.
     *
     * @param string $collectionKey Collection key
     * @param string $stateId State ID
     * @param array<string, mixed> $row Previous runtime row, when available
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly string $stateId,
        public readonly array $row = [],
    ) {
    }

    /**
     * Converts DTO to array for transport.
     *
     * @return array<string, mixed> DTO data as array
     */
    public function toArray(): array
    {
        return [
            'collectionKey' => $this->collectionKey,
            'stateId' => $this->stateId,
            'row' => $this->row,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            collectionKey: $data['collectionKey'] ?? '',
            stateId: $data['stateId'] ?? '',
            row: isset($data['row']) && is_array($data['row']) ? $data['row'] : [],
        );
    }
}
