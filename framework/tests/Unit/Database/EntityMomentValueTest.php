<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\PhpType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Moment declarations do not reinterpret values on the existing numeric ORM path. */
final class EntityMomentValueTest extends TestCase
{
    /** @return iterable<string, array{int, ?int}> Required and optional epoch-millisecond values */
    public static function momentValues(): iterable
    {
        yield 'absent' => [0, null];
        yield 'epoch' => [0, 0];
        yield 'before epoch' => [-1, -1];
        yield 'negative milliseconds' => [-1234567890123, -1234567890123];
        yield 'after 2038' => [2147483648001, 2147483648001];
        yield 'millisecond remainder' => [1767225600123, 1767225600123];
    }

    /**
     * @param int $required Required moment
     * @param ?int $optional Nullable moment
     */
    #[DataProvider('momentValues')]
    public function testFromRowHydratesIntegersAndNumericStringsWithoutRescaling(int $required, ?int $optional): void
    {
        $expected = ['id' => 7, 'occurred_at' => $required, 'optional_at' => $optional];
        foreach ([
            $expected,
            ['id' => '7', 'occurred_at' => (string)$required, 'optional_at' => $optional === null ? null : (string)$optional],
        ] as $row) {
            $row[NumericMomentValueEntity::db_only_at] = 2147483648001;
            $entity = NumericMomentValueEntity::fromRow($row);

            self::assertSame($expected, $entity->toArray());
            self::assertTrue($entity->isRelated());
            self::assertFalse(property_exists($entity, NumericMomentValueEntity::db_only_at));
        }

        foreach ([(string)$required, '1969-12-31 23:59:59.999'] as $legacyValue) {
            $legacy = LegacyMomentValueEntity::fromRow(['id' => 7, 'occurred_at' => $legacyValue]);
            self::assertSame($legacyValue, $legacy->occurred_at);
            $legacy->applySyncPartialRow(['occurred_at' => $legacyValue]);
            self::assertSame(['id' => 7, 'occurred_at' => $legacyValue], $legacy->toArray());
        }
    }

    /**
     * @param int $required Required moment
     * @param ?int $optional Nullable moment
     */
    #[DataProvider('momentValues')]
    public function testRepeatedPartialUpdatesPreserveExactValuesAndUntouchedColumns(int $required, ?int $optional): void
    {
        $entity = NumericMomentValueEntity::fromRow(['id' => 7, 'occurred_at' => 42, 'optional_at' => 42]);
        foreach ([$required, (string)$required, $required] as $value) {
            $entity->applySyncPartialRow(['occurred_at' => $value, 'db_only_at' => 99]);
            self::assertSame(['id' => 7, 'occurred_at' => $required, 'optional_at' => 42], $entity->toArray());
            self::assertFalse(property_exists($entity, NumericMomentValueEntity::db_only_at));
        }
        foreach ([$optional, $optional === null ? null : (string)$optional, $optional] as $value) {
            $entity->applySyncPartialRow(['optional_at' => $value]);
            self::assertSame(['id' => 7, 'occurred_at' => $required, 'optional_at' => $optional], $entity->toArray());
        }
        $entity->applySyncPartialRow([]);
        self::assertSame(['id' => 7, 'occurred_at' => $required, 'optional_at' => $optional], $entity->toArray());
        self::assertTrue($entity->isRelated());
    }

    /**
     * @param int $required Required moment
     * @param ?int $optional Nullable moment
     */
    #[DataProvider('momentValues')]
    public function testObjectFromEntityAndSyncRelatedKeepTheNumericBaseline(int $required, ?int $optional): void
    {
        $entity = NumericMomentValueEntity::fromRow(['id' => 7, 'occurred_at' => 42, 'optional_at' => 42]);
        $object = NumericMomentValueObject::fromEntity($entity);
        self::assertSame($entity->toArray(), $object->toArray());
        self::assertSame([], $object->getChangedColumns());

        $entity->applySyncPartialRow(['occurred_at' => $required, 'optional_at' => $optional]);
        self::assertSame(['occurred_at', 'optional_at'], $object->getChangedColumns());
        $object->syncRelated();
        self::assertSame(['id' => 7, 'occurred_at' => $required, 'optional_at' => $optional], $object->toArray());
        self::assertSame([], $object->getChangedColumns());

        $entity->occurred_at = 42;
        $entity->optional_at = 42;
        $object->revert();
        self::assertSame(['id' => 7, 'occurred_at' => $required, 'optional_at' => $optional], $object->toArray());
        self::assertSame([], $object->getChangedColumns());
    }
}

/** Numeric mapped moments beside a moment that only exists in the physical schema. */
final class NumericMomentValueEntity extends Entity
{
    public const string id = 'id';
    public const string occurred_at = 'occurred_at';
    public const string optional_at = 'optional_at';
    public const string db_only_at = 'db_only_at';

    public const string _table = 'numeric_moment_value_fixture';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::occurred_at, self::optional_at];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::occurred_at => PhpType::INTEGER->value,
        self::optional_at => PhpType::INTEGER->value,
    ];
    public const array _moments = [self::occurred_at, self::optional_at, self::db_only_at];

    public int $id;
    public int $occurred_at;
    public ?int $optional_at;
}

/** Legacy metadata declares semantics without migrating its string storage. */
final class LegacyMomentValueEntity extends Entity
{
    public const string id = 'id';
    public const string occurred_at = 'occurred_at';

    public const string _table = 'legacy_moment_value_fixture';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::occurred_at];
    public const array _types = [self::id => PhpType::INTEGER->value, self::occurred_at => PhpType::STRING->value];
    public const array _moments = [self::occurred_at];

    public int $id;
    public string $occurred_at;
}

/** @extends Object_<NumericMomentValueEntity> */
final class NumericMomentValueObject extends Object_
{
    public const string ENTITY_CLASS = NumericMomentValueEntity::class;
}
