<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Countable;
use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Files\ContentHash;
use Hilos\Files\Upload\DTO\UploadSnapshotData;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\Snapshot\RtWorkDriver;
use IteratorAggregate;
use LogicException;
use Throwable;
use Traversable;

/**
 * Immutable ordered identities of the uploads admitted together for one attempt.
 *
 * @implements IteratorAggregate<int, UploadSnapshotData>
 */
final readonly class UploadSnapshotCollection implements Countable, IteratorAggregate
{
    /** Snapshot identities have intrinsically bounded fields. */
    private const array FIELD_BYTES = [
        UploadSnapshotData::clientUploadId => UploadFrame::MAX_ID_LENGTH,
        UploadSnapshotData::tmpIndex => 32,
        UploadSnapshotData::contentHash => ContentHash::LENGTH,
    ];

    /** Original identities in attachment order, with no retained native list. */
    private RtRowValues $snapshots;
    private int $snapshotCount;

    /**
     * @param UploadSnapshotData ...$snapshots Distinct uploads in attachment order
     * @throws InvalidFormatException When an upload id appears more than once
     */
    public function __construct(UploadSnapshotData ...$snapshots)
    {
        $ids = [];
        foreach ($snapshots as $snapshot) {
            if (in_array($snapshot->clientUploadId, $ids, true)) {
                throw new InvalidFormatException('Snapshot clientUploadId values must be distinct.');
            }
            $ids[] = $snapshot->clientUploadId;
        }
        if ($snapshots !== []) {
            try {
                $this->snapshots = RtRowValues::fromNative(array_map(
                    static fn (UploadSnapshotData $snapshot): array => $snapshot->toArray(), array_values($snapshots),
                ));
            } catch (Throwable $failure) {
                throw new InvalidFormatException('The upload snapshot identities could not be prepared.', 0, $failure);
            }
            $this->snapshotCount = count($snapshots);
        }
    }

    /**
     * @internal Independent holder without walking the attachment list.
     *
     * @param RtRowRole $role New holder role
     * @return self Same immutable identities under an independent lease
     * @throws InvalidFormatException When upload identities are malformed
     * @throws LogicException When the backing lease has closed
     */
    public function retain(RtRowRole $role): self
    {
        $copy = new self();
        if (isset($this->snapshots)) {
            $copy->snapshots = $this->snapshots->retain($role);
            $copy->snapshotCount = $this->snapshotCount;
        }

        return $copy;
    }

    /**
     * @internal Moves this holder's lease to another memory role in constant work.
     *
     * @param RtRowRole $role New holder role
     */
    public function reclassify(RtRowRole $role): void
    {
        if (isset($this->snapshots)) {
            $this->snapshots->reclassify($role);
        }
    }

    /** @return int Number of admitted uploads */
    public function count(): int
    {
        return $this->snapshotCount ?? 0;
    }

    /**
     * @return Traversable<int, UploadSnapshotData> Independent application walk in attachment order
     * @throws InvalidFormatException When stored identity pages cannot be read
     * @throws LogicException When the backing lease has closed
     */
    public function getIterator(): Traversable
    {
        if (!isset($this->snapshots)) {
            return;
        }
        $cursor = $this->snapshots->reader()->entries();
        while (true) {
            try {
                $entry = RtWorkDriver::runSynchronously($cursor->advance());
            } catch (Throwable $failure) {
                throw new InvalidFormatException('The upload snapshot identities could not be read.', 0, $failure);
            }
            if ($entry === null) {
                return;
            }
            yield UploadSnapshotData::fromArray($entry->value->toNative());
        }
    }

    /**
     * @return list<array<string, string>> Serialized identities in attachment order
     * @throws LogicException When the backing lease has closed
     */
    public function toArray(): array
    {
        return isset($this->snapshots) ? $this->snapshots->toNative() : [];
    }

    /**
     * @param array<array-key, mixed> $rows Serialized snapshot list
     * @return self Immutable ordered identities
     * @throws InvalidFormatException When the list, an identity or its uniqueness is malformed
     */
    public static function fromArray(array $rows): self
    {
        if (!array_is_list($rows)) {
            throw new InvalidFormatException('Upload snapshots must be an ordered list.');
        }
        $snapshots = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidFormatException('An upload snapshot must be an object.');
            }
            $snapshots[] = UploadSnapshotData::fromArray($row);
        }

        return new self(...$snapshots);
    }

    /**
     * Bounded identity records are validated by their existing DTO. The enclosing list and
     * uniqueness walk stay segmented, including cancellation and last-reference release.
     *
     * @param RtRowReader $input Ordered serialized upload identities
     * @return Generator<int, RtWork, int, self> Immutable ordered snapshots with segmented backing
     * @throws InvalidFormatException When the list, a record or its uniqueness is malformed
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public static function fromValues(RtRowReader $input): Generator
    {
        $cursor = $input->entries();
        $writer = new RtRowWriter($input->arena(), RtRowRole::Candidate);
        $position = 0;
        while (($entry = yield from $cursor->advance()) !== null) {
            if ($entry->key->reader()->kind() !== RtRowKind::Scalar
                || (yield from $entry->key->reader()->scalar()) !== $position) {
                throw new InvalidFormatException('Upload snapshots must be an ordered list.');
            }
            $record = $entry->value->reader();
            $identity = [];
            foreach (self::FIELD_BYTES as $key => $maxBytes) {
                yield new RtWork();
                $field = yield from $record->field($key);
                if ($field === null) {
                    throw new InvalidFormatException('Missing snapshot field: ' . $key);
                }
                $identity[$key] = yield from $field->reader()->boundedText($maxBytes);
            }
            $remaining = strlen($identity[UploadSnapshotData::clientUploadId])
                + strlen($identity[UploadSnapshotData::tmpIndex]) + strlen($identity[UploadSnapshotData::contentHash]);
            while ($remaining > 0) {
                $remaining -= yield new RtWork($remaining);
            }
            $snapshot = new UploadSnapshotData(
                $identity[UploadSnapshotData::clientUploadId],
                $identity[UploadSnapshotData::tmpIndex],
                $identity[UploadSnapshotData::contentHash],
            );
            $previous = $input->entries();
            for ($index = 0; $index < $position; ++$index) {
                $other = yield from $previous->advance();
                $otherId = yield from $other->value->reader()->field(UploadSnapshotData::clientUploadId);
                if (yield from $otherId->reader()->equals($snapshot->clientUploadId)) {
                    throw new InvalidFormatException('Snapshot clientUploadId values must be distinct.');
                }
            }
            // A single DTO has three bounded strings; no native collection is retained.
            yield from $writer->write($position++, yield from RtRowWriter::encode($snapshot->toArray(), $input->arena()));
        }

        $values = yield from $writer->finish();
        yield new RtWork();
        $collection = new self();
        $collection->snapshots = $values;
        $collection->snapshotCount = $position;

        return $collection;
    }

    /**
     * @internal Runtime serialization keeps this immutable collection segmented.
     *
     * @param RtRowArena $arena Destination storage owner
     * @return Generator<int, RtWork, int, RtRowValues> Segmented snapshot list
     * @throws InvalidFormatException When stored pages are malformed
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public function exportValues(RtRowArena $arena): Generator
    {
        if (!isset($this->snapshots)) {
            return yield from RtRowWriter::encode([], $arena);
        }

        return yield from RtRowWriter::copy($this->snapshots->reader(), $arena);
    }

    /**
     * @internal The snapshot list for a carrier of the row as it stands: the list this collection
     * holds, referenced at its root when the carrier is written in the same arena, and copied only
     * into another one.
     *
     * @param RtRowArena $arena Carrier's storage owner
     * @return Generator<int, RtWork, int, RtRowValues> Segmented snapshot list
     * @throws InvalidFormatException When stored pages are malformed
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public function carriedValues(RtRowArena $arena): Generator
    {
        if (isset($this->snapshots) && $this->snapshots->isHeldIn($arena)) {
            yield new RtWork();

            return $this->snapshots;
        }

        return yield from $this->exportValues($arena);
    }
}
