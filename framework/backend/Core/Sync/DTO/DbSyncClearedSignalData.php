<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Router\SignalDataInterface;

/**
 * DbSyncClearedSignalData - DB sync signal data for a whole-collection truncate.
 *
 * Unlike create/update/delete this fact is collection-scoped: it carries only
 * the collection key, with no row id, and means "every row in this collection
 * was removed". Queued by Objects::deleteAll().
 */
class DbSyncClearedSignalData extends BaseDTO implements SignalDataInterface
{
    /**
     * Creates DB sync cleared signal data.
     *
     * @param string $collectionKey Collection key whose rows were all removed
     */
    public function __construct(
        public readonly string $collectionKey,
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
        return new static(
            collectionKey: $data[SyncSignalDataKey::COLLECTION_KEY] ?? '',
        );
    }
}
