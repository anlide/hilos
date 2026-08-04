<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;

/**
 * DbSyncClearedSignalData - DB sync signal data for a whole-collection truncate.
 *
 * Unlike create/update/delete this fact is collection-scoped: it carries no row
 * id and means "every row in this collection was removed". Queued by
 * Objects::deleteAll().
 *
 * Two identities travel with it and must not be confused: `origin` is the accept
 * key of the connection whose write caused the truncate, while `emitter` is the
 * identity of the process that broadcast the signal — the field a receiver
 * compares with its own identity to drop its own echo.
 */
class DbSyncClearedSignalData extends BaseDTO implements SyncSignalDataInterface
{
    /**
     * Creates DB sync cleared signal data.
     *
     * @param string $collectionKey Collection key whose rows were all removed
     * @param ?string $origin Accept key of the writing connection, or null when unattended
     * @param ?string $emitter Identity of the process that sent this clear, or null when unstamped
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly ?string $origin = null,
        public readonly ?string $emitter = null,
    ) {
    }

    /**
     * Returns a copy stamped with the identity of the sending process.
     *
     * Called on the send path so a receiver can recognize its own echo by
     * comparing the stamp with its own identity.
     *
     * @param string $emitter Identity of the sending process
     * @return static Copy carrying the emitter stamp
     */
    public function withEmitter(string $emitter): static
    {
        return new static(
            collectionKey: $this->collectionKey,
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
            SyncSignalDataKey::ORIGIN => $this->origin,
            SyncSignalDataKey::EMITTER => $this->emitter,
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
        $emitter = $data[SyncSignalDataKey::EMITTER] ?? null;

        return new static(
            collectionKey: $data[SyncSignalDataKey::COLLECTION_KEY] ?? '',
            origin: is_string($origin) && $origin !== '' ? $origin : null,
            emitter: is_string($emitter) && $emitter !== '' ? $emitter : null,
        );
    }
}
