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
     * @param ?string $originRequestId Request id of the action behind the write, or null when no action is behind it
     * @param ?string $emitter Identity of the process that sent this fact, or null when unstamped
     * @param array<string, mixed> $previous Previous values of the changed columns
     * @param ?int $receiptId Existing receipt primary key for the write, or null outside a receipt
     * @param ?string $writerAgentId Actual writing agent, or null without an active agent
     * @param ?DbSyncAdmissionData $admission Protected row revision and complete mapped snapshot
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly string $idString,
        public readonly array $row,
        public readonly ?string $origin = null,
        public readonly ?string $originRequestId = null,
        public readonly ?string $emitter = null,
        public readonly array $previous = [],
        public readonly ?int $receiptId = null,
        public readonly ?string $writerAgentId = null,
        public readonly ?DbSyncAdmissionData $admission = null,
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
            originRequestId: $this->originRequestId,
            emitter: $emitter,
            previous: $this->previous,
            receiptId: $this->receiptId,
            writerAgentId: $this->writerAgentId,
            admission: $this->admission,
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
            SyncSignalDataKey::ROW => $this->admission === null ? $this->row : DbSyncAdmissionData::encodeRow($this->collectionKey, $this->row),
            SyncSignalDataKey::PREVIOUS => $this->admission === null
                ? $this->previous : DbSyncAdmissionData::encodeRow($this->collectionKey, $this->previous),
            SyncSignalDataKey::ORIGIN => $this->origin,
            SyncSignalDataKey::ORIGIN_REQUEST_ID => $this->originRequestId,
            SyncSignalDataKey::EMITTER => $this->emitter,
            SyncSignalDataKey::RECEIPT_ID => $this->receiptId,
            SyncSignalDataKey::WRITER_AGENT_ID => $this->writerAgentId,
            SyncSignalDataKey::ADMISSION => $this->admission?->toArray($this->collectionKey),
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no collection key, row id, row or previous values
     */
    public static function fromArray(array $data): static
    {
        $collectionKey = self::requireString($data, SyncSignalDataKey::COLLECTION_KEY);
        $rawAdmission = self::optionalArray($data, SyncSignalDataKey::ADMISSION);
        $admission = $rawAdmission === null ? null : DbSyncAdmissionData::fromArray($rawAdmission, $collectionKey);
        $row = self::requireArray($data, SyncSignalDataKey::ROW);
        $previous = self::requireArray($data, SyncSignalDataKey::PREVIOUS);

        $row = $admission === null ? $row : DbSyncAdmissionData::decodeRow($collectionKey, $row);
        $previous = $admission === null ? $previous : DbSyncAdmissionData::decodeRow($collectionKey, $previous);
        if ($admission !== null) {
            $admission->validate($collectionKey, self::requireString($data, SyncSignalDataKey::ID_STRING),
                delete: false, row: $row, previous: $previous);
        }

        return new static(
            collectionKey: $collectionKey,
            idString: self::requireString($data, SyncSignalDataKey::ID_STRING),
            row: $row,
            origin: self::optionalString($data, SyncSignalDataKey::ORIGIN),
            originRequestId: self::optionalString($data, SyncSignalDataKey::ORIGIN_REQUEST_ID),
            emitter: self::optionalString($data, SyncSignalDataKey::EMITTER),
            previous: $previous,
            receiptId: self::optionalInt($data, SyncSignalDataKey::RECEIPT_ID),
            writerAgentId: self::optionalString($data, SyncSignalDataKey::WRITER_AGENT_ID),
            admission: $admission,
        );
    }
}
