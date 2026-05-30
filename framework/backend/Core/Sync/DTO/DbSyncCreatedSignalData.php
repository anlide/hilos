<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * DbSyncCreatedSignalData - DB sync signal data for created row.
 *
 * Full row data (all columns).
 */
class DbSyncCreatedSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates DB sync created signal data.
     *
     * @param string $collectionKey Collection key (e.g. ChatDbContext::events)
     * @param string $idString Row ID from Object::getIdString()
     * @param array<string, mixed> $row Full row data (all columns)
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
            SyncSignalDataKey::COLLECTION_KEY => $this->collectionKey,
            SyncSignalDataKey::ID_STRING => $this->idString,
            SyncSignalDataKey::ROW => $this->row,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (collectionKey, idString, row)
     * @return static DTO instance
     */
    public static function fromArray(array $data): static
    {
        return new self(
            collectionKey: $data[SyncSignalDataKey::COLLECTION_KEY] ?? '',
            idString: $data[SyncSignalDataKey::ID_STRING] ?? '',
            row: $data[SyncSignalDataKey::ROW] ?? [],
        );
    }
}
