<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * DB sync signal data for updated row.
 *
 * Only changed columns.
 */
class DbSyncUpdatedSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates DB sync updated signal data.
     *
     * @param string $collectionKey Collection key
     * @param string $idString ID from Object::getIdString()
     * @param array<string, mixed> $row Changed columns and values
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly string $idString,
        public readonly array $row,
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
            row: $data['row'] ?? [],
        );
    }
}
