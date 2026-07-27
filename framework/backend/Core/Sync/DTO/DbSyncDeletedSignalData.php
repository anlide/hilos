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
     * @param ?string $origin Accept key of the writing connection, or null when unattended
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly string $idString,
        public readonly array $row = [],
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
     */
    public static function fromArray(array $data): static
    {
        $origin = $data[SyncSignalDataKey::ORIGIN] ?? null;

        return new static(
            collectionKey: $data[SyncSignalDataKey::COLLECTION_KEY] ?? '',
            idString: $data[SyncSignalDataKey::ID_STRING] ?? '',
            row: isset($data[SyncSignalDataKey::ROW]) && is_array($data[SyncSignalDataKey::ROW])
                ? $data[SyncSignalDataKey::ROW]
                : [],
            origin: is_string($origin) && $origin !== '' ? $origin : null,
        );
    }
}
