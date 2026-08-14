<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * DbSyncUpdatedSignalData - DB sync signal data for updated row.
 *
 * Only changed columns.
 */
class DbSyncUpdatedSignalData extends BaseDTO implements DbSyncSignalDataInterface
{
    /**
     * Creates DB sync updated signal data.
     *
     * @param string $collectionKey Collection key
     * @param string $idString ID from Object::getIdString()
     * @param array<string, mixed> $row Changed columns and values
     * @param ?string $origin Accept key of the writing connection, or null when unattended
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly string $idString,
        public readonly array $row,
        public readonly ?string $origin = null,
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
            SyncSignalDataKey::ORIGIN => $this->origin,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no collection key, no row id or no row
     */
    public static function fromArray(array $data): static
    {
        return new static(
            collectionKey: self::requireString($data, SyncSignalDataKey::COLLECTION_KEY),
            idString: self::requireString($data, SyncSignalDataKey::ID_STRING),
            row: self::requireArray($data, SyncSignalDataKey::ROW),
            origin: self::optionalString($data, SyncSignalDataKey::ORIGIN),
        );
    }
}
