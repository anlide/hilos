<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * DbSyncDeletedSignalData - DB sync signal data for deleted row.
 *
 * Carries idString plus optional previous row data.
 */
class DbSyncDeletedSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates DB sync deleted signal data.
     *
     * @param string $collectionKey Collection key
     * @param string $idString Row ID from Object::getIdString()
     * @param array<string, mixed> $row Previous row, when available
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly string $idString,
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
            'idString' => $this->idString,
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
            idString: $data['idString'] ?? '',
            row: isset($data['row']) && is_array($data['row']) ? $data['row'] : [],
        );
    }
}
