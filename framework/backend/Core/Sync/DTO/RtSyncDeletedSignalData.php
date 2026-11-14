<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\RtRowValues;
use LogicException;

/**
 * RtSyncDeletedSignalData - RT sync signal data for deleted state.
 *
 * Carries state ID plus optional previous runtime row data.
 */
class RtSyncDeletedSignalData extends BaseDTO implements RtSyncSignalDataInterface
{
    /** @var array<string, mixed> Previous runtime row, when available */
    public array $row {
        get => RtRowValues::nativeMap($this->rowSource);
    }

    /** @var array<string, mixed>|RtRowValues Row as the writer handed it over */
    private readonly array|RtRowValues $rowSource;

    /**
     * Creates RT sync deleted signal data.
     *
     * @param string $collectionKey Collection key
     * @param string $stateId State ID
     * @param array<string, mixed>|RtRowValues $row Previous runtime row, when available, segmented by a local writer
     * @param ?string $origin Accept key of the writing connection, or null when unattended
     * @param ?string $originRequestId Request id of the action behind the write, or null when no action is behind it
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly string $stateId,
        array|RtRowValues $row = [],
        public readonly ?string $origin = null,
        public readonly ?string $originRequestId = null,
    ) {
        $this->rowSource = $row;
    }

    /**
     * @internal Hands the row on in the form this payload holds it, for a carrier that forwards it unchanged.
     *
     * @return array<string, mixed>|RtRowValues Row as the writer or the wire handed it over
     */
    public function carriedRow(): array|RtRowValues
    {
        return $this->rowSource;
    }

    /**
     * Segmented row: forwarding, filtering and repairing a cache read the fields they need here.
     *
     * @return RtRowValues Previous runtime row, when available
     * @throws InvalidFormatException When a row handed over as an array holds a value a runtime row cannot carry
     * @throws LogicException When a segmented lease already closed
     */
    public function rowValues(): RtRowValues
    {
        return RtRowValues::carried($this->rowSource);
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
