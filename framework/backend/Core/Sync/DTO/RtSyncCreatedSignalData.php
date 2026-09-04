<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;

/**
 * RtSyncCreatedSignalData - RT sync signal data for created state.
 *
 * Full state data.
 */
class RtSyncCreatedSignalData extends BaseDTO implements RtSyncSignalDataInterface
{
    /**
     * Creates RT sync created signal data.
     *
     * @param string $collectionKey Collection key
     * @param string $stateId State ID
     * @param array<string, mixed> $row Full state data
     * @param ?string $origin Accept key of the writing connection, or null when unattended
     * @param ?string $originRequestId Request id of the action behind the write, or null when no action is behind it
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly string $stateId,
        public readonly array $row,
        public readonly ?string $origin = null,
        public readonly ?string $originRequestId = null,
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
            SyncSignalDataKey::STATE_ID => $this->stateId,
            SyncSignalDataKey::ROW => $this->row,
            SyncSignalDataKey::ORIGIN => $this->origin,
            SyncSignalDataKey::ORIGIN_REQUEST_ID => $this->originRequestId,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no collection key, no state id or no row
     */
    public static function fromArray(array $data): static
    {
        return new static(
            collectionKey: self::requireString($data, SyncSignalDataKey::COLLECTION_KEY),
            stateId: self::requireString($data, SyncSignalDataKey::STATE_ID),
            row: self::requireArray($data, SyncSignalDataKey::ROW),
            origin: self::optionalString($data, SyncSignalDataKey::ORIGIN),
            originRequestId: self::optionalString($data, SyncSignalDataKey::ORIGIN_REQUEST_ID),
        );
    }
}
