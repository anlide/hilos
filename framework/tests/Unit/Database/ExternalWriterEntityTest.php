<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Core\TruthSource\Exception\ExternalWriterException;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Direct Entity writes must refuse even without a mounted chain, before binding resolution or SQL. */
final class ExternalWriterEntityTest extends TestCase
{
    private ?HilosDbContext $previousDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        Hilos::$db = null;
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
        parent::tearDown();
    }

    /** @return iterable<string, array{string, bool}> Every public Entity write on new and hydrated rows */
    public static function writePaths(): iterable
    {
        foreach (['save', 'save columns', 'changed diff', 'unchanged diff', 'delete'] as $path) {
            yield "new {$path}" => [$path, false];
            yield "stored {$path}" => [$path, true];
        }
    }

    /**
     * @param string $path Direct Entity write entry point
     * @param bool $stored Whether fromRow() marked the Entity related
     */
    #[DataProvider('writePaths')]
    public function testExternalWriteRefusesBeforeBindingSqlOrSavedMemory(string $path, bool $stored): void
    {
        $entity = $stored
            ? InheritedExternalWriterEntity::fromRow(['id' => '1', 'owner_id' => '42'])
            : new InheritedExternalWriterEntity();
        self::assertSame($stored, $entity->isRelated());
        self::assertNull(Hilos::$db);
        $original = clone $entity;
        if ($path === 'changed diff') {
            $entity->owner_id = 7;
        }
        // Serialization includes the private relation flag and original row, not just current public values.
        $before = serialize($entity);
        $sql = new ReflectionProperty(Database::class, 'lastSql')->getValue();

        try {
            match ($path) {
                'save' => $entity->save(),
                'save columns' => $entity->save(['owner_id']),
                'changed diff', 'unchanged diff' => $entity->saveDiff($original),
                'delete' => $entity->delete(),
            };
            self::fail('Even an unmounted Entity must refuse external writes');
        } catch (ExternalWriterException $failure) {
            self::assertInstanceOf(WriteNotAllowedException::class, $failure);
            self::assertSame(
                "ORM write not allowed for collection/table 'external_writer_rows': it has an external database writer.",
                $failure->getMessage(),
            );
            self::assertSame($before, serialize($entity));
            self::assertSame($sql, new ReflectionProperty(Database::class, 'lastSql')->getValue());
        }
    }

    public function testMetadataDefaultsToAnApplicationWriterAndIsInherited(): void
    {
        self::assertSame('_externalWriter', Entity::META_EXTERNAL_WRITER);
        self::assertFalse(Entity::_externalWriter);
        self::assertFalse(ApplicationWriterEntity::_externalWriter);
        self::assertTrue(ExternalWriterEntity::_externalWriter);
        self::assertTrue(InheritedExternalWriterEntity::_externalWriter);
    }

    public function testExternalRowsStillHydrateAndCanBeRead(): void
    {
        $entity = ExternalWriterEntity::fromRow(['id' => '1', 'owner_id' => '42']);
        self::assertSame(['id' => 1, 'owner_id' => 42], $entity->toArray());
        self::assertTrue($entity->isRelated());
        self::assertFalse(ExternalWriterEntity::getEmpty()->isRelated());
    }

    public function testAnOrdinaryUnchangedDiffRetainsItsExistingSuccessContract(): void
    {
        $entity = ApplicationWriterEntity::fromRow(['id' => '1', 'owner_id' => '42']);
        $before = serialize($entity);
        $sql = new ReflectionProperty(Database::class, 'lastSql')->getValue();

        self::assertTrue($entity->saveDiff(clone $entity));
        self::assertSame($before, serialize($entity));
        self::assertSame($sql, new ReflectionProperty(Database::class, 'lastSql')->getValue());
    }
}

/** An ordinary Entity inherits the application-writer default. */
class ApplicationWriterEntity extends Entity
{
    public const string _table = 'external_writer_rows';
    public const string _primary = 'id';
    public const array _columns = ['id', 'owner_id'];
    public const array _types = ['id' => 'integer', 'owner_id' => 'integer'];

    public ?int $id = null;
    public ?int $owner_id = null;
}

/** The nonexistent binding proves that a refusal precedes even database resolution. */
class ExternalWriterEntity extends ApplicationWriterEntity
{
    public const bool _externalWriter = true;
    public const string _database = 'unit_external_unconfigured';
}

/** A subclass must retain its inherited external-writer restriction. */
final class InheritedExternalWriterEntity extends ExternalWriterEntity
{
}
