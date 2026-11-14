<?php

declare(strict_types=1);

namespace Hilos\Core\Sync\DTO;

use Hilos\BaseDTO;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\RtRowValues;
use LogicException;

/**
 * RtSyncUpdatedSignalData - RT sync signal data for updated state.
 *
 * Only changed fields.
 */
class RtSyncUpdatedSignalData extends BaseDTO implements RtSyncSignalDataInterface
{
    /** @var array<string, mixed> Only changed fields */
    public array $row {
        get => RtRowValues::nativeMap($this->rowSource);
    }

    /** @var array<string, mixed> Previous values of the changed fields */
    public array $previous {
        get => RtRowValues::nativeMap($this->previousSource);
    }

    /** @var array<string, mixed>|RtRowValues Row as the writer handed it over */
    private readonly array|RtRowValues $rowSource;
    /** @var array<string, mixed>|RtRowValues Previous values as the writer handed them over */
    private readonly array|RtRowValues $previousSource;

    /**
     * Creates RT sync updated signal data.
     *
     * @param string $collectionKey Collection key
     * @param string $stateId State ID
     * @param array<string, mixed>|RtRowValues $row Only changed fields, segmented by a local writer
     * @param ?string $origin Accept key of the writing connection, or null when unattended
     * @param ?string $originRequestId Request id of the action behind the write, or null when no action is behind it
     * @param array<string, mixed>|RtRowValues $previous Previous values of the changed fields
     */
    public function __construct(
        public readonly string $collectionKey,
        public readonly string $stateId,
        array|RtRowValues $row,
        public readonly ?string $origin = null,
        public readonly ?string $originRequestId = null,
        array|RtRowValues $previous = [],
    ) {
        $this->rowSource = $row;
        $this->previousSource = $previous;
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
     * @internal Hands the previous values on in the form this payload holds them.
     *
     * @return array<string, mixed>|RtRowValues Previous values as the writer or the wire handed them over
     */
    public function carriedPrevious(): array|RtRowValues
    {
        return $this->previousSource;
    }

    /**
     * Segmented row: forwarding, filtering and repairing a cache read the fields they need here.
     *
     * @return RtRowValues Only changed fields
     * @throws InvalidFormatException When a row handed over as an array holds a value a runtime row cannot carry
     * @throws LogicException When a segmented lease already closed
     */
    public function rowValues(): RtRowValues
    {
        return RtRowValues::carried($this->rowSource);
    }

    /**
     * Segmented previous values, read without materializing the notification.
     *
     * @return RtRowValues Previous values of the changed fields
     * @throws InvalidFormatException When previous values handed over as an array hold a value a runtime row cannot carry
     * @throws LogicException When a segmented lease already closed
     */
    public function previousValues(): RtRowValues
    {
        return RtRowValues::carried($this->previousSource);
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
            SyncSignalDataKey::PREVIOUS => $this->previous,
            SyncSignalDataKey::ORIGIN => $this->origin,
            SyncSignalDataKey::ORIGIN_REQUEST_ID => $this->originRequestId,
        ];
    }

    /**
     * Creates DTO from array.
     *
     * @param array<string, mixed> $data Source data
     * @return static DTO instance
     * @throws InvalidFormatException When the payload carries no collection key, state id, row or previous values
     */
    public static function fromArray(array $data): static
    {
        return new static(
            collectionKey: self::requireString($data, SyncSignalDataKey::COLLECTION_KEY),
            stateId: self::requireString($data, SyncSignalDataKey::STATE_ID),
            row: self::requireArray($data, SyncSignalDataKey::ROW),
            origin: self::optionalString($data, SyncSignalDataKey::ORIGIN),
            originRequestId: self::optionalString($data, SyncSignalDataKey::ORIGIN_REQUEST_ID),
            previous: self::requireArray($data, SyncSignalDataKey::PREVIOUS),
        );
    }
}
