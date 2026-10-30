<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Files\Upload;

use Error;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Files\Upload\DTO\UploadSnapshotData;
use Hilos\Files\Upload\UploadSnapshotCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UploadSnapshotTest extends TestCase
{
    public function testPreservesOrderAcrossSerializationAndIndependentWalks(): void
    {
        $first = new UploadSnapshotData('0', str_repeat('a', 32), hash('sha256', 'first'));
        $second = new UploadSnapshotData('01', str_repeat('b', 32), hash('sha256', 'second'));
        $snapshots = new UploadSnapshotCollection($second, $first);
        $rows = $snapshots->toArray();
        $restored = UploadSnapshotCollection::fromArray($rows);
        $rows[0]['tmpIndex'] = str_repeat('c', 32);

        self::assertCount(2, $restored);
        self::assertSame($snapshots->toArray(), $restored->toArray());
        self::assertSame(['01', '0'], array_map(static fn (UploadSnapshotData $s): string => $s->clientUploadId, iterator_to_array($restored)));
        foreach ($restored as $outer) {
            self::assertCount(2, iterator_to_array($restored));
            self::assertInstanceOf(UploadSnapshotData::class, $outer);
        }
        self::assertSame($first->toArray(), UploadSnapshotData::fromArray($first->toArray())->toArray());
        self::assertSame([], new UploadSnapshotCollection()->toArray());
    }

    public function testAnIdentityCannotBeEditedAfterAdmission(): void
    {
        $snapshot = new UploadSnapshotData('u1', str_repeat('a', 32), hash('sha256', 'bytes'));
        $this->expectException(Error::class);
        $snapshot->tmpIndex = str_repeat('b', 32);
    }

    /** @param array<array-key, mixed> $rows Malformed serialized snapshot list */
    #[DataProvider('invalidSnapshots')]
    public function testRefusesMalformedOrAmbiguousSnapshots(array $rows): void
    {
        $this->expectException(InvalidFormatException::class);
        UploadSnapshotCollection::fromArray($rows);
    }

    /** @return iterable<string, array{array<array-key, mixed>}> Invalid identities and collection shapes */
    public static function invalidSnapshots(): iterable
    {
        $valid = ['clientUploadId' => 'u1', 'tmpIndex' => str_repeat('a', 32), 'contentHash' => hash('sha256', 'bytes')];
        yield 'map' => [['u1' => $valid]];
        yield 'scalar entry' => [['u1']];
        yield 'object entry' => [[new UploadSnapshotData(...array_values($valid))]];
        yield 'duplicate id' => [[$valid, $valid]];
        foreach (array_keys($valid) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            yield 'missing ' . $key => [[$missing]];
            foreach ([null, false, 1, [], ''] as $index => $value) {
                yield $key . ' wrong type or empty ' . $index => [[array_replace($valid, [$key => $value])]];
            }
        }
        foreach (['bad/id', str_repeat('x', 65), 'a|b'] as $index => $id) {
            yield 'bad id ' . $index => [[array_replace($valid, ['clientUploadId' => $id])]];
        }
        foreach (['../file', '/tmp/file', 'a/b', 'a\\b', '.', '..', "a\0b", str_repeat('A', 32)] as $index => $name) {
            yield 'bad tmp name ' . $index => [[array_replace($valid, ['tmpIndex' => $name])]];
        }
        yield 'uppercase hash' => [[array_replace($valid, ['contentHash' => str_repeat('A', 64)])]];
        yield 'short hash' => [[array_replace($valid, ['contentHash' => 'abc'])]];
    }
}
