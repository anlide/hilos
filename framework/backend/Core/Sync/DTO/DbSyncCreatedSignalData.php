<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * DbSyncCreatedSignalData - DB sync signal data for created row.
 *
 * Full row data (all columns).
 */
class DbSyncCreatedSignalData extends BaseDTO implements DbSyncSignalDataInterface
{
    /**
     * Creates DB sync created signal data.
     *
     * @param string $collectionKey Collection key (e.g. ChatDbContext::events)
     * @param string $idString Row ID from Object::getIdString()
     * @param array<string, mixed> $row Full row data (all columns)
     * @param ?string $origin Accept key of the writing connection, or null when unattended
     * @param ?string $emitter Identity of the process that sent this fact, or null when unstamped
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly string $idString,
        public readonly array $row,
        public readonly ?string $origin = null,
        public readonly ?string $emitter = null,
    ) {
    }

    /**
     * Returns a copy stamped with the identity of the sending process.
     *
     * @param string $emitter Identity of the sending process
     * @return static Copy carrying the emitter stamp
     */
    public function withEmitter(string $emitter): static
    {
        return new static(
            collectionKey: $this->collectionKey,
            idString: $this->idString,
            row: $this->row,
            origin: $this->origin,
            emitter: $emitter,
        );
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
            SyncSignalDataKey::EMITTER => $this->emitter,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data (collectionKey, idString, row)
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
            emitter: self::optionalString($data, SyncSignalDataKey::EMITTER),
        );
    }
}
