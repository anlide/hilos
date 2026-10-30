<?php

declare(strict_types=1);

namespace Hilos\Files\Upload;

use Countable;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Files\Upload\DTO\UploadSnapshotData;
use IteratorAggregate;
use Traversable;

/**
 * Immutable ordered identities of the uploads admitted together for one attempt.
 *
 * @implements IteratorAggregate<int, UploadSnapshotData>
 */
final readonly class UploadSnapshotCollection implements Countable, IteratorAggregate
{
    /** @var list<UploadSnapshotData> Original identities in attachment order */
    private array $snapshots;

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
        $this->snapshots = array_values($snapshots);
    }

    /** @return int Number of admitted uploads */
    public function count(): int
    {
        return count($this->snapshots);
    }

    /** @return Traversable<int, UploadSnapshotData> Independent walk in attachment order */
    public function getIterator(): Traversable
    {
        yield from $this->snapshots;
    }

    /** @return list<array<string, string>> Serialized identities in attachment order */
    public function toArray(): array
    {
        return array_map(static fn (UploadSnapshotData $snapshot): array => $snapshot->toArray(), $this->snapshots);
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
}
