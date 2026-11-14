<?php

declare(strict_types=1);

namespace Hilos\Runtime\Stream;

use Generator;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\Snapshot\RtWork;
use LogicException;

/**
 * One ordinary RT operation as its stream carries it: the logical object whose bytes the parts
 * of one sequence hold, in the sender's field order {@see self::FIELDS}.
 *
 * The row and the previous values stay segmented both ways: the sender reads them from the
 * carrier its write produced, and the receiver's carrier holds what the decoder built.
 */
final class RtSyncStreamOperation
{
    /** @var string Field: RT sync signal type */
    public const string FIELD_SIGNAL_TYPE = 'signalType';
    /** @var string Field: the sender's committed revision in the stream's domain */
    public const string FIELD_REVISION = 'revision';
    /** @var string Field: collection key */
    public const string FIELD_COLLECTION_KEY = 'collectionKey';
    /** @var string Field: state id */
    public const string FIELD_STATE_ID = 'stateId';
    /** @var string Field: accept key of the writing connection */
    public const string FIELD_ORIGIN = 'origin';
    /** @var string Field: request id of the action behind the write */
    public const string FIELD_ORIGIN_REQUEST_ID = 'originRequestId';
    /** @var string Field: the peer node the write happened on */
    public const string FIELD_ORIGIN_NODE_ID = 'originNodeId';
    /** @var string Field: whether the origin holds only part of the right over the collection */
    public const string FIELD_PARTIAL_OWNER = 'partialOwner';
    /** @var string Field: full row on create, patch on update, old row or nothing on delete */
    public const string FIELD_ROW = 'row';
    /** @var string Field: previous values of the changed fields, update only */
    public const string FIELD_PREVIOUS = 'previous';

    /** @var list<string> Fields in the order the sender writes them; previous closes an update only */
    public const array FIELDS = [
        self::FIELD_SIGNAL_TYPE,
        self::FIELD_REVISION,
        self::FIELD_COLLECTION_KEY,
        self::FIELD_STATE_ID,
        self::FIELD_ORIGIN,
        self::FIELD_ORIGIN_REQUEST_ID,
        self::FIELD_ORIGIN_NODE_ID,
        self::FIELD_PARTIAL_OWNER,
        self::FIELD_ROW,
        self::FIELD_PREVIOUS,
    ];

    /** @var list<string> The only signal types an ordinary RT stream carries */
    public const array SIGNAL_TYPES = [
        SignalTypeConstants::RT_SYNC_CREATED,
        SignalTypeConstants::RT_SYNC_UPDATED,
        SignalTypeConstants::RT_SYNC_DELETED,
    ];

    /** @var int Longest identity or origin text a header carries */
    private const int MAX_HEADER_BYTES = 4096;

    /**
     * @param string $signalType One of {@see self::SIGNAL_TYPES}
     * @param int $revision Sender's committed revision in the stream's domain, from one
     * @param string $collectionKey Collection key
     * @param string $stateId State id
     * @param ?string $origin Accept key of the writing connection, or null when unattended
     * @param ?string $originRequestId Request id of the action behind the write, or null
     * @param ?string $originNodeId Peer node the write happened on, or null on worker IPC
     * @param bool $partialOwner Whether the origin holds only part of the right over the collection
     * @param RtRowValues $row Full row, patch, or old row
     * @param ?RtRowValues $previous Previous values of the changed fields, update only
     * @throws InvalidFormatException When the signal type is no RT sync one, or previous values
     *     come with anything but an update or an update comes without them
     */
    public function __construct(
        public readonly string $signalType,
        public readonly int $revision,
        public readonly string $collectionKey,
        public readonly string $stateId,
        public readonly ?string $origin,
        public readonly ?string $originRequestId,
        public readonly ?string $originNodeId,
        public readonly bool $partialOwner,
        public readonly RtRowValues $row,
        public readonly ?RtRowValues $previous,
    ) {
        if (!in_array($signalType, self::SIGNAL_TYPES, true)) {
            throw new InvalidFormatException('An ordinary RT stream carries RT sync operations only');
        }
        if (($signalType === SignalTypeConstants::RT_SYNC_UPDATED) !== ($previous !== null)) {
            throw new InvalidFormatException('Previous values come with an RT update and with nothing else');
        }
        if ($revision < 1) {
            throw new InvalidFormatException('An RT stream revision starts at one');
        }
    }

    /**
     * Operation of an RT sync carrier, its row and previous values as the carrier holds them.
     *
     * @param RtSyncSignalDataInterface $data RT sync carrier
     * @param int $revision Sender's committed revision in the stream's domain
     * @param ?string $originNodeId Peer node the write happened on, or null on worker IPC
     * @param bool $partialOwner Whether the origin holds only part of the right over the collection
     * @return self Operation to stream
     * @throws InvalidFormatException When the carrier is no RT sync one, or its row holds a value a runtime row cannot carry
     * @throws LogicException When a segmented lease already closed
     */
    public static function fromSignalData(
        RtSyncSignalDataInterface $data,
        int $revision,
        ?string $originNodeId = null,
        bool $partialOwner = false,
    ): self {
        $signalType = match (true) {
            $data instanceof RtSyncCreatedSignalData => SignalTypeConstants::RT_SYNC_CREATED,
            $data instanceof RtSyncUpdatedSignalData => SignalTypeConstants::RT_SYNC_UPDATED,
            $data instanceof RtSyncDeletedSignalData => SignalTypeConstants::RT_SYNC_DELETED,
            default => throw new InvalidFormatException('An ordinary RT stream carries RT sync operations only'),
        };

        return new self(
            $signalType,
            $revision,
            $data->collectionKey,
            $data->stateId,
            $data->origin,
            $data->originRequestId,
            $originNodeId,
            $partialOwner,
            $data->rowValues(),
            $data instanceof RtSyncUpdatedSignalData ? $data->previousValues() : null,
        );
    }

    /**
     * Reads a decoded logical object in the sender's field order; nothing is taken on a guess.
     *
     * @param RtRowValues $object Decoded logical object
     * @return Generator<int, RtWork, int, self> Bounded header reads, then the operation
     * @throws InvalidFormatException When a field is missing, out of order, of the wrong kind or outside its domain
     * @throws LogicException When a segmented lease already closed
     */
    public static function read(RtRowValues $object): Generator
    {
        $reader = $object->reader();
        if ($reader->kind() !== RtRowKind::Map) {
            throw new InvalidFormatException('An RT stream operation is an object');
        }
        $cursor = $reader->entries();
        $values = [];
        foreach (self::FIELDS as $field) {
            $entry = yield from $cursor->advance();
            if ($entry === null) {
                break;
            }
            if (!(yield from $entry->key->reader()->equals($field))) {
                throw new InvalidFormatException("An RT stream operation carries its fields in order; expected {$field}");
            }
            $values[$field] = $entry->value;
        }
        if ((yield from $cursor->advance()) !== null || count($values) < count(self::FIELDS) - 1) {
            throw new InvalidFormatException('An RT stream operation carries exactly its fields');
        }

        return new self(
            (yield from self::text($values[self::FIELD_SIGNAL_TYPE])),
            (yield from self::integer($values[self::FIELD_REVISION])),
            (yield from self::text($values[self::FIELD_COLLECTION_KEY])),
            (yield from self::text($values[self::FIELD_STATE_ID])),
            (yield from self::nullableText($values[self::FIELD_ORIGIN])),
            (yield from self::nullableText($values[self::FIELD_ORIGIN_REQUEST_ID])),
            (yield from self::nullableText($values[self::FIELD_ORIGIN_NODE_ID])),
            (yield from self::boolean($values[self::FIELD_PARTIAL_OWNER])),
            self::map($values[self::FIELD_ROW]),
            isset($values[self::FIELD_PREVIOUS]) ? self::map($values[self::FIELD_PREVIOUS]) : null,
        );
    }

    /**
     * The carrier the receiver's further delivery takes, holding the segmented values.
     *
     * @return RtSyncSignalDataInterface RT sync carrier
     */
    public function signalData(): RtSyncSignalDataInterface
    {
        return match ($this->signalType) {
            SignalTypeConstants::RT_SYNC_CREATED => new RtSyncCreatedSignalData(
                $this->collectionKey, $this->stateId, $this->row, $this->origin, $this->originRequestId,
            ),
            SignalTypeConstants::RT_SYNC_UPDATED => new RtSyncUpdatedSignalData(
                $this->collectionKey, $this->stateId, $this->row, $this->origin, $this->originRequestId, $this->previous,
            ),
            default => new RtSyncDeletedSignalData(
                $this->collectionKey, $this->stateId, $this->row, $this->origin, $this->originRequestId,
            ),
        };
    }

    /**
     * The signal a master's dispatch carries for this operation, named after its type.
     *
     * @param ?RtSyncSignalDataInterface $data Carrier already built from this operation, or null to build one
     * @return SignalDTO RT sync signal
     * @throws InvalidArgumentException Never: the name is one of the RT sync names
     */
    public function signal(?RtSyncSignalDataInterface $data = null): SignalDTO
    {
        return new SignalDTO(
            signalSource: new SignalSource(SignalSource::RT),
            signalType: new SignalType($this->signalType),
            signalName: new SignalName(match ($this->signalType) {
                SignalTypeConstants::RT_SYNC_CREATED => SignalConstants::RT_SYNC_CREATED,
                SignalTypeConstants::RT_SYNC_UPDATED => SignalConstants::RT_SYNC_UPDATED,
                default => SignalConstants::RT_SYNC_DELETED,
            }),
            data: $data ?? $this->signalData(),
        );
    }

    /**
     * @param RtRowValues $value Header value
     * @return Generator<int, RtWork, int, string> Bounded text
     * @throws InvalidFormatException When the value is not text within the header bound
     * @throws LogicException When a segmented lease already closed
     */
    private static function text(RtRowValues $value): Generator
    {
        return yield from $value->reader()->boundedText(self::MAX_HEADER_BYTES);
    }

    /**
     * @param RtRowValues $value Header value
     * @return Generator<int, RtWork, int, ?string> Bounded text, or null
     * @throws InvalidFormatException When the value is neither null nor text within the header bound
     * @throws LogicException When a segmented lease already closed
     */
    private static function nullableText(RtRowValues $value): Generator
    {
        $reader = $value->reader();
        if ($reader->kind() === RtRowKind::Scalar && (yield from $reader->scalar()) === null) {
            return null;
        }

        return yield from $reader->boundedText(self::MAX_HEADER_BYTES);
    }

    /**
     * @param RtRowValues $value Header value
     * @return Generator<int, RtWork, int, int> Integer
     * @throws InvalidFormatException When the value is not an integer
     * @throws LogicException When a segmented lease already closed
     */
    private static function integer(RtRowValues $value): Generator
    {
        $scalar = yield from $value->reader()->scalar();

        return is_int($scalar) ? $scalar : throw new InvalidFormatException('An RT stream revision is an integer');
    }

    /**
     * @param RtRowValues $value Header value
     * @return Generator<int, RtWork, int, bool> Boolean
     * @throws InvalidFormatException When the value is not a boolean
     * @throws LogicException When a segmented lease already closed
     */
    private static function boolean(RtRowValues $value): Generator
    {
        $scalar = yield from $value->reader()->scalar();

        return is_bool($scalar) ? $scalar : throw new InvalidFormatException('An RT stream owner mark is a boolean');
    }

    /**
     * @param RtRowValues $value Row or previous values
     * @return RtRowValues The same value, a field map
     * @throws InvalidFormatException When the value is not an object
     * @throws LogicException When a segmented lease already closed
     */
    private static function map(RtRowValues $value): RtRowValues
    {
        return $value->reader()->kind() === RtRowKind::Map
            ? $value
            : throw new InvalidFormatException('An RT stream row is an object');
    }
}
