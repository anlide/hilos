<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupReferenceRegistry;
use Hilos\Backup\Exception\BackupException;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the per-connection reference-table registry.
 *
 * Covers class-to-table derivation for both accepted registration forms (Entity and
 * Object collection classes), the empty/unknown-connection contract, and the guard
 * against a class that is neither.
 */
final class BackupReferenceRegistryTest extends TestCase
{
    public function testEntityClassResolvesToItsTable(): void
    {
        $registry = new BackupReferenceRegistry([0 => [ReferenceEntityFixture::class]]);

        $this->assertSame(['reference_entity'], $registry->tablesForConnection(0));
    }

    public function testObjectCollectionClassResolvesToEntityTable(): void
    {
        $registry = new BackupReferenceRegistry([1 => [ReferenceObjectsFixture::class]]);

        $this->assertSame(['reference_entity'], $registry->tablesForConnection(1));
    }

    public function testTablesPreserveDeclarationOrder(): void
    {
        $registry = new BackupReferenceRegistry([
            0 => [ReferenceObjectsFixture::class, ReferenceEntityFixture::class],
        ]);

        $this->assertSame(['reference_entity', 'reference_entity'], $registry->tablesForConnection(0));
    }

    public function testUnknownConnectionIndexReturnsEmpty(): void
    {
        $registry = new BackupReferenceRegistry([0 => [ReferenceEntityFixture::class]]);

        $this->assertSame([], $registry->tablesForConnection(9));
    }

    public function testNonEntityNonObjectsClassThrows(): void
    {
        $registry = new BackupReferenceRegistry([0 => [self::class]]);

        $this->expectException(BackupException::class);

        $registry->tablesForConnection(0);
    }
}

/**
 * Minimal Entity fixture exposing only the table name the registry reads.
 */
final class ReferenceEntityFixture extends Entity
{
    public const string _table = 'reference_entity';
}

/**
 * Minimal Object item fixture pointing at the reference entity.
 */
final class ReferenceObjectFixture extends Object_
{
    public const string ENTITY_CLASS = ReferenceEntityFixture::class;
}

/**
 * Minimal Object collection fixture the registry resolves to the entity's table.
 */
final class ReferenceObjectsFixture extends Objects
{
    public const string OBJECT_CLASS = ReferenceObjectFixture::class;
}
