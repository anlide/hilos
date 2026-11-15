<?php

declare(strict_types=1);

namespace Hilos\Runtime\Stream;

use Generator;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\Snapshot\RtSnapshotExport;
use Hilos\Runtime\Snapshot\RtSnapshotPortion;
use Hilos\Runtime\Snapshot\RtSnapshotScope;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use LogicException;

/**
 * One snapshot of a collection as an RT stream carries it: the logical object `rt_sync_snapshot`,
 * an operation of the same stream as the ordinary writes, its head in the sender's field order
 * {@see self::HEAD_FIELDS}, then its rows and the stale marks of the sender's copy.
 *
 * It stands among the writes where it was cut: the channel cuts the source when it takes the
 * snapshot ({@see RtSyncStreamChannel::sendSnapshot()}), so every write taken before reaches the
 * other end ahead of it and every write taken after is read there behind it. Its rows are never
 * held whole on either end: the sender reads them out of the cut on the allowance of the part
 * being written ({@see self::nextRows()}), and the receiver is handed each row once the decoder
 * closes it ({@see self::takeRows()}), to stage it into a generation within that generation's
 * reserve while the rest is still on its way.
 *
 * A master sends one to a worker for the collections the worker takes up to read, and for those
 * whose copy there lost its base; a node sends one to a linked node's master for each area it owns
 * of a collection, when the link comes up, when what it owns moves, and when that copy lost its base.
 */
final class RtSyncStreamSnapshot
{
    /** @var string Signal type the object's first field carries */
    public const string SIGNAL_TYPE = 'rt_sync_snapshot';
    /** @var string Field: source revision the rows were cut at */
    public const string FIELD_CUT_REVISION = 'cutRevision';
    /** @var string Field: rows the snapshot covers */
    public const string FIELD_SCOPE = 'scope';
    /** @var string Field of the scope: its width */
    public const string FIELD_WIDTH = 'width';
    /** @var string Field of the scope: the rows or sets it names, none for the whole source */
    public const string FIELD_KEYS = 'keys';
    /** @var string Field: rows of the cut, by id */
    public const string FIELD_ROWS = 'rows';
    /** @var string Field: moments the sender's copy of rows froze, by id; only the master's stream to a worker carries any */
    public const string FIELD_STALE_ROWS = 'staleRows';
    /** @var string Field: number of the worker's interest report the snapshot answers; null on a peer link */
    public const string FIELD_ANNOUNCEMENT = 'announcement';
    /** @var string Width: the whole source */
    public const string WIDTH_WHOLE = 'whole';
    /** @var string Width: the named rows */
    public const string WIDTH_ROWS = 'rows';
    /** @var string Width: the rows of the named sets */
    public const string WIDTH_SETS = 'sets';

    /** @var list<string> Fields of the head, in the order the sender writes them; the rows and the stale marks follow */
    public const array HEAD_FIELDS = [
        RtSyncStreamOperation::FIELD_SIGNAL_TYPE,
        RtSyncStreamOperation::FIELD_REVISION,
        RtSyncStreamOperation::FIELD_COLLECTION_KEY,
        self::FIELD_CUT_REVISION,
        self::FIELD_SCOPE,
        RtSyncStreamOperation::FIELD_ORIGIN_NODE_ID,
        RtSyncStreamOperation::FIELD_PARTIAL_OWNER,
        self::FIELD_ANNOUNCEMENT,
    ];

    /** @var int Longest identity, origin or scope key a head carries */
    private const int MAX_HEAD_BYTES = 4096;

    private bool $whole = false;
    private bool $lost = false;
    /** Whether the host last asked for rows and found none, while more were still to come */
    private bool $starved = false;
    private ?object $receipt = null;

    /**
     * @param int $revision Sender's committed revision in the stream's domain, from one
     * @param string $collectionKey Collection key
     * @param int $cutRevision Source revision the rows were cut at
     * @param RtSnapshotScope $scope Rows the snapshot covers
     * @param ?string $originNodeId Peer node the rows come from, or null on worker IPC
     * @param bool $partialOwner Whether the origin holds only part of the right over the collection
     * @param ?int $announcement Number of the worker's interest report the snapshot answers, which the worker
     *     publishes it for; null on a peer link
     * @param array<string, float> $staleRows Moments the sender's copy of rows froze, by id; complete on the
     *     receiving end once the snapshot is whole
     * @param ?RtSnapshotExport $cut Sending end: the read of the cut, null on the receiving end
     * @param ?RtSyncStreamDecoder $decoder Receiving end: the reader its rows come out of, null on the sending end
     * @throws InvalidFormatException When the revision is not positive
     */
    private function __construct(
        public readonly int $revision,
        public readonly string $collectionKey,
        public readonly int $cutRevision,
        public readonly RtSnapshotScope $scope,
        public readonly ?string $originNodeId,
        public readonly bool $partialOwner,
        public readonly ?int $announcement,
        private array $staleRows,
        private readonly ?RtSnapshotExport $cut,
        private readonly ?RtSyncStreamDecoder $decoder,
    ) {
        if ($revision < 1) {
            throw new InvalidFormatException('An RT stream revision starts at one');
        }
    }

    /**
     * @internal The sending end's snapshot, cut now; the channel takes it as it is cut.
     *
     * @param int $revision Sender's committed revision in the stream's domain
     * @param string $collectionKey Collection to cut
     * @param RtSnapshotScope $scope Rows the snapshot covers
     * @param ?string $originNodeId Peer node the rows come from, or null on worker IPC
     * @param bool $partialOwner Whether the origin holds only part of the right over the collection
     * @param array<string, float> $staleRows Moments this end's copy of rows froze, by id
     * @param ?int $announcement Number of the worker's interest report the snapshot answers; null on a peer link
     * @return self Snapshot to stream, its rows still in the cut
     * @throws InvalidFormatException When a standalone row's set field is malformed or the revision is not positive
     * @throws LogicException When the source's version pages have closed
     */
    public static function cut(
        int $revision,
        string $collectionKey,
        RtSnapshotScope $scope,
        ?string $originNodeId,
        bool $partialOwner,
        array $staleRows,
        ?int $announcement = null,
    ): self {
        $cut = RtSnapshot::capture($collectionKey, $scope);

        return new self($revision, $collectionKey, $cut->revision(), $scope, $originNodeId, $partialOwner, $announcement, $staleRows, $cut, null);
    }

    /**
     * @internal The receiving end's snapshot, read off the head its decoder closed; its rows follow from the
     * same decoder. Nothing is taken on a guess.
     *
     * @param RtSyncStreamDecoder $decoder Reader of the object, its head closed
     * @return Generator<int, RtWork, int, self> Bounded head reads, then the snapshot
     * @throws InvalidFormatException When the head is missing, a field is missing, out of order, of the wrong kind
     *     or outside its domain
     * @throws LogicException When a segmented lease already closed
     */
    public static function read(RtSyncStreamDecoder $decoder): Generator
    {
        $head = $decoder->head() ?? throw new InvalidFormatException('An RT stream snapshot carries its head before its rows');
        $values = yield from self::fields($head, self::HEAD_FIELDS);
        if ((yield from self::text($values[RtSyncStreamOperation::FIELD_SIGNAL_TYPE])) !== self::SIGNAL_TYPE) {
            throw new InvalidFormatException('An RT stream snapshot carries the snapshot signal type');
        }

        return new self(
            (yield from self::integer($values[RtSyncStreamOperation::FIELD_REVISION])),
            (yield from self::text($values[RtSyncStreamOperation::FIELD_COLLECTION_KEY])),
            (yield from self::integer($values[self::FIELD_CUT_REVISION])),
            (yield from self::scopeOf($values[self::FIELD_SCOPE])),
            (yield from self::nullableText($values[RtSyncStreamOperation::FIELD_ORIGIN_NODE_ID])),
            (yield from self::boolean($values[RtSyncStreamOperation::FIELD_PARTIAL_OWNER])),
            (yield from self::nullableInteger($values[self::FIELD_ANNOUNCEMENT])),
            [],
            null,
            $decoder,
        );
    }

    /**
     * Rows read so far and not taken yet, for the generation they are staged into.
     *
     * @param int $maxBytes Logical bytes the portion holds at most; its first row is taken whatever its size
     * @return ?RtSnapshotPortion Rows in the order they came, null when none waits
     */
    public function takeRows(int $maxBytes = PHP_INT_MAX): ?RtSnapshotPortion
    {
        $rows = $this->decoder?->takeRows($maxBytes);
        $this->starved = $rows === null && !$this->whole;

        return $rows;
    }

    /**
     * @internal Whether the host's staging waits for the next part: it asked for rows and none were
     *     there, and none has been read since while more are still to come. The stream does not advance
     *     the staging meanwhile, and the next part's arrival wakes it.
     *
     * @return bool Whether the staging waits for rows not read yet
     */
    public function awaitsRows(): bool
    {
        return $this->starved && !$this->whole && !$this->lost && !$this->hasRows();
    }

    /** @return bool Whether a row read waits to be taken */
    public function hasRows(): bool
    {
        return $this->decoder?->hasRows() ?? false;
    }

    /** @return bool Whether the whole object has been read: no row is still to come, and the stale marks are in */
    public function isWhole(): bool
    {
        return $this->whole;
    }

    /**
     * @return bool Whether the rest of the snapshot will never be read: a part of it did not read, or it was refused
     *     before its end; whatever was staged of it is to be canceled
     */
    public function isLost(): bool
    {
        return $this->lost;
    }

    /** @return array<string, float> Moments the sender's copy of rows froze, by id; complete once the snapshot is whole */
    public function staleRows(): array
    {
        return $this->staleRows;
    }

    /** @return ?object The host's receipt of the snapshot's final part, null before it was read or without one */
    public function receipt(): ?object
    {
        return $this->receipt;
    }

    /**
     * @internal The next rows of the cut, read on the allowance of the part being written.
     *
     * @param RtWorkBudget $budget Allowance of the current RT pass
     * @return ?RtSnapshotPortion Rows read on it, null when it ran out before a row was whole or the cut is read out
     * @throws InvalidFormatException When a stored row identity is malformed
     * @throws LogicException When the cut's pages closed under the read, or this is the receiving end
     * @throws InvalidArgumentException When the allowance refuses a request
     */
    public function nextRows(RtWorkBudget $budget): ?RtSnapshotPortion
    {
        $cut = $this->cut ?? throw new LogicException('Only the sending end of an RT stream snapshot reads its cut');

        return $cut->advance($budget);
    }

    /** @return bool Whether every row of the cut has been read out on the sending end */
    public function isReadOut(): bool
    {
        return $this->cut === null || $this->cut->isFinished();
    }

    /**
     * @internal Reads what follows the rows once the decoder closed the object; the snapshot is whole after it.
     *
     * @param ?object $receipt The host's receipt of the final part, null without one
     * @return Generator<int, RtWork, int, void> Bounded reads of the stale marks
     * @throws InvalidFormatException When the object does not close as a snapshot does
     * @throws LogicException When a segmented lease already closed, or this is the sending end
     */
    public function finishReading(?object $receipt): Generator
    {
        $decoder = $this->decoder ?? throw new LogicException('Only the receiving end of an RT stream snapshot is read');
        $values = yield from self::fields($decoder->finish(), [self::FIELD_ROWS, self::FIELD_STALE_ROWS]);
        if ($values[self::FIELD_ROWS]->reader()->kind() !== RtRowKind::Map || !$values[self::FIELD_ROWS]->isEmptyMap()) {
            throw new InvalidFormatException('An RT stream snapshot hands its rows over as they are read');
        }
        $staleRows = [];
        $reader = self::map($values[self::FIELD_STALE_ROWS])->reader();
        $cursor = $reader->entries();
        while (($entry = yield from $cursor->advance()) !== null) {
            $key = $entry->key->reader();
            $id = $key->kind() === RtRowKind::Text ? yield from $key->boundedText(self::MAX_HEAD_BYTES) : yield from $key->scalar();
            $since = yield from $entry->value->reader()->scalar();
            if (!is_int($since) && !is_float($since)) {
                throw new InvalidFormatException('An RT stream snapshot marks a stale row with a moment');
            }
            $staleRows[(string)$id] = (float)$since;
        }
        $this->staleRows = $staleRows;
        $this->receipt = $receipt;
        $this->whole = true;
    }

    /** @internal The rest of the snapshot will never be read; the host cancels what it staged of it. */
    public function lose(): void
    {
        $this->lost = true;
    }

    /** @return string Width of the scope as the object names it */
    public function width(): string
    {
        return match (true) {
            $this->scope->isWhole() => self::WIDTH_WHOLE,
            $this->scope->rowKeys() !== null => self::WIDTH_ROWS,
            default => self::WIDTH_SETS,
        };
    }

    /** @return list<string> Rows or sets the scope names, none for the whole source */
    public function scopeKeys(): array
    {
        return $this->scope->rowKeys() ?? $this->scope->setKeys() ?? [];
    }

    /**
     * Reads an object's fields in the given order; nothing else is taken.
     *
     * @param RtRowValues $object Decoded object
     * @param list<string> $fields Its fields, in order
     * @return Generator<int, RtWork, int, array<string, RtRowValues>> The fields' values by name
     * @throws InvalidFormatException When the value is no object, or a field is missing or out of order
     * @throws LogicException When a segmented lease already closed
     */
    private static function fields(RtRowValues $object, array $fields): Generator
    {
        $cursor = self::map($object)->reader()->entries();
        $values = [];
        foreach ($fields as $field) {
            $entry = yield from $cursor->advance();
            if ($entry === null || !(yield from $entry->key->reader()->equals($field))) {
                throw new InvalidFormatException("An RT stream snapshot carries its fields in order; expected {$field}");
            }
            $values[$field] = $entry->value;
        }
        if ((yield from $cursor->advance()) !== null) {
            throw new InvalidFormatException('An RT stream snapshot carries exactly its fields');
        }

        return $values;
    }

    /**
     * @param RtRowValues $value Scope object
     * @return Generator<int, RtWork, int, RtSnapshotScope> The scope it names
     * @throws InvalidFormatException When the width is unknown, or the keys are no list of text or name nothing
     *     but the whole source would
     * @throws LogicException When a segmented lease already closed
     */
    private static function scopeOf(RtRowValues $value): Generator
    {
        $fields = yield from self::fields($value, [self::FIELD_WIDTH, self::FIELD_KEYS]);
        $width = yield from self::text($fields[self::FIELD_WIDTH]);
        $keys = [];
        $cursor = self::map($fields[self::FIELD_KEYS])->reader()->entries();
        while (($entry = yield from $cursor->advance()) !== null) {
            $keys[] = yield from self::text($entry->value);
        }

        return match ($width) {
            self::WIDTH_WHOLE => $keys === []
                ? RtSnapshotScope::whole()
                : throw new InvalidFormatException('An RT stream snapshot of the whole source names no keys'),
            self::WIDTH_ROWS => RtSnapshotScope::rows($keys),
            self::WIDTH_SETS => RtSnapshotScope::sets($keys),
            default => throw new InvalidFormatException('An RT stream snapshot is of the whole source, of rows or of sets'),
        };
    }

    /**
     * @param RtRowValues $value Head value
     * @return Generator<int, RtWork, int, string> Bounded text
     * @throws InvalidFormatException When the value is not text within the head bound
     * @throws LogicException When a segmented lease already closed
     */
    private static function text(RtRowValues $value): Generator
    {
        return yield from $value->reader()->boundedText(self::MAX_HEAD_BYTES);
    }

    /**
     * @param RtRowValues $value Head value
     * @return Generator<int, RtWork, int, ?string> Bounded text, or null
     * @throws InvalidFormatException When the value is neither null nor text within the head bound
     * @throws LogicException When a segmented lease already closed
     */
    private static function nullableText(RtRowValues $value): Generator
    {
        $reader = $value->reader();
        if ($reader->kind() === RtRowKind::Scalar && (yield from $reader->scalar()) === null) {
            return null;
        }

        return yield from $reader->boundedText(self::MAX_HEAD_BYTES);
    }

    /**
     * @param RtRowValues $value Head value
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
     * @param RtRowValues $value Head value
     * @return Generator<int, RtWork, int, ?int> A positive integer, or null
     * @throws InvalidFormatException When the value is neither null nor a positive integer
     * @throws LogicException When a segmented lease already closed
     */
    private static function nullableInteger(RtRowValues $value): Generator
    {
        $scalar = yield from $value->reader()->scalar();

        return $scalar === null || (is_int($scalar) && $scalar > 0)
            ? $scalar
            : throw new InvalidFormatException('An RT stream snapshot names the interest report it answers by a positive integer');
    }

    /**
     * @param RtRowValues $value Head value
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
     * @param RtRowValues $value Object value
     * @return RtRowValues The same value, a field map
     * @throws InvalidFormatException When the value is not an object
     * @throws LogicException When a segmented lease already closed
     */
    private static function map(RtRowValues $value): RtRowValues
    {
        return $value->reader()->kind() === RtRowKind::Map
            ? $value
            : throw new InvalidFormatException('An RT stream snapshot field is an object here');
    }
}
