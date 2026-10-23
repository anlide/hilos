<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Schema;

use Hilos\Database\ChangeLog\JournalTriggerGenerator;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\NamedDatabaseException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\Schema\EntityDatabaseStartupGuard;
use Hilos\Database\Schema\EntitySchemaAudit;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** The config-only gate needs no transport double, schema initialization or live database. */
#[RunTestsInSeparateProcesses]
final class EntityDatabaseStartupGuardTest extends TestCase
{
    /** Proves every case leaves all configured connections closed and no SQL attempted. */
    protected function tearDown(): void
    {
        foreach (Database::getConfiguredIndices() as $index) {
            self::assertFalse(Database::isConnected($index));
            self::assertNull(Database::getCachedResultSet($index));
        }
        foreach (new ReflectionProperty(Database::class, 'lastSql')->getValue() as $sql) {
            self::assertSame('', $sql);
        }
        Hilos::$db = null;
        parent::tearDown();
    }

    public function testDefaultPrimaryWithNoConfigurationIsMetadataOnly(): void
    {
        $this->mount(StartupPrimaryRows::class);
        EntityDatabaseStartupGuard::assertMountedBindings();
        self::assertSame([], Database::getConfiguredIndices());
        self::assertSame(0, Database::getCurrentIndex());
    }

    public function testLiveGuardWithNoMountsDoesNotOpenAConnection(): void
    {
        Hilos::$db = null;
        EntityDatabaseStartupGuard::assertMountedSchemasMatch();
        self::assertSame([], Database::getConfiguredIndices());
    }

    public function testMountedSubclassResolutionKeepsTheMetadataOnlyPrimaryFallback(): void
    {
        $this->mount(StartupDerivedPrimaryRows::class);
        self::assertSame(StartupDerivedPrimaryEntity::class, EntitySchemaAudit::mountedClassOf(StartupPrimaryEntity::class));
        self::assertSame([], Database::getConfiguredIndices());
    }

    public function testMountedSubclassResolutionRefusesAnUnknownExplicitAlias(): void
    {
        $this->mount(StartupRemoteRows::class);
        $this->expectException(NamedDatabaseException::class);
        EntitySchemaAudit::mountedClassOf(StartupPrimaryEntity::class);
    }

    public function testUnmountedEntityWithAnUnknownExplicitAliasIsStillRefused(): void
    {
        Hilos::$db = null;
        $this->expectException(NamedDatabaseException::class);
        EntitySchemaAudit::mountedClassOf(StartupRemoteEntity::class);
    }

    public function testMountedSubclassInAnotherDatabaseDoesNotReplacePrimary(): void
    {
        Database::configure(database: 'same_schema');
        Database::configure(index: 2, host: 'remote.invalid', database: 'same_schema');
        Database::registerDatabase('remote', 2);
        $this->mount(StartupRemoteRows::class);
        self::assertSame(StartupPrimaryEntity::class, EntitySchemaAudit::mountedClassOf(StartupPrimaryEntity::class));
    }

    public function testMountedSubclassWithAPhysicalAliasReplacesPrimary(): void
    {
        Database::configure(database: 'same_schema');
        Database::configure(index: 2, database: 'same_schema');
        Database::registerDatabase('remote', 2);
        $this->mount(StartupRemoteRows::class);
        self::assertSame(StartupRemoteEntity::class, EntitySchemaAudit::mountedClassOf(StartupPrimaryEntity::class));
    }

    public function testExplicitEmptyNameIsRefused(): void
    {
        $this->mount(StartupEmptyRows::class);
        $this->expectException(NamedDatabaseException::class);
        EntityDatabaseStartupGuard::assertMountedBindings();
    }

    public function testUnknownNameIsRefusedWithoutPrimaryFallback(): void
    {
        $this->mount(StartupRemoteRows::class);
        $this->expectException(NamedDatabaseException::class);
        $this->expectExceptionMessage('remote');
        EntityDatabaseStartupGuard::assertMountedBindings();
    }

    public function testRemoteUnjournaledEntityPreservesTheAmbientConnection(): void
    {
        Database::configure(database: 'primary_schema');
        Database::configure(index: 2, host: 'remote.invalid', database: 'primary_schema');
        Database::registerDatabase('remote', 2);
        Database::useConnection(2);
        $this->mount(StartupRemoteRows::class);
        EntityDatabaseStartupGuard::assertMountedBindings();
        self::assertSame(2, Database::getCurrentIndex());
    }

    public function testAReconfiguredEndpointInvalidatesASharedRowBinding(): void
    {
        Database::configure(database: 'primary_schema');
        Database::configure(index: 2, database: 'secondary_schema');
        Database::registerDatabase('remote', 2, 0);
        Database::configure(index: 2, host: 'remote.invalid', password: 'never-show-this', database: 'secondary_schema');
        Database::useConnection(2);
        $this->mount(StartupRemoteRows::class);
        try {
            EntityDatabaseStartupGuard::assertMountedBindings();
            self::fail('A stale shared row binding must be refused');
        } catch (NamedDatabaseException $failure) {
            self::assertStringContainsString('cannot share a row connection', $failure->getMessage());
            self::assertStringNotContainsString('never-show-this', $failure->getMessage());
            self::assertSame(2, Database::getCurrentIndex());
        }
    }

    public function testConfiguredPrimaryWithAnEmptyPhysicalSchemaIsRefused(): void
    {
        Database::configure(database: '');
        $this->mount(StartupPrimaryRows::class);
        $this->expectException(NamedDatabaseException::class);
        $this->expectExceptionMessage('physical schema name');
        EntityDatabaseStartupGuard::assertMountedBindings();
    }

    public function testASecondaryBindingWithAnEmptyPhysicalSchemaIsRefused(): void
    {
        Database::configure(database: 'primary_schema');
        Database::configure(index: 2, database: '');
        Database::registerDatabase('remote', 2);
        $this->mount(StartupRemoteRows::class);
        $this->expectException(NamedDatabaseException::class);
        EntityDatabaseStartupGuard::assertMountedBindings();
    }

    public function testPrimaryJournalAndAPhysicalAliasOnRowZeroAreSupported(): void
    {
        Database::configure(database: 'same_schema', password: 'row-secret');
        Database::configure(index: 2, database: 'same_schema', password: 'schema-secret');
        Database::registerDatabase('remote', 2, 0);
        Database::useConnection(2);
        $this->mount(StartupRemoteJournalRows::class);
        EntityDatabaseStartupGuard::assertMountedBindings();
        $this->mount(StartupPrimaryRows::class);
        EntityDatabaseStartupGuard::assertMountedBindings();
        self::assertSame(2, Database::getCurrentIndex());
    }

    public function testSamePhysicalSchemaOnAnotherRowConnectionCannotCarryTheReceipt(): void
    {
        Database::configure(database: 'same_schema');
        Database::configure(index: 2, database: 'same_schema');
        Database::registerDatabase('remote', 2);
        $this->mount(StartupRemoteJournalRows::class);
        $this->expectException(NamedDatabaseException::class);
        $this->expectExceptionMessage('primary row connection');
        EntityDatabaseStartupGuard::assertMountedBindings();
    }

    public function testGeneratorRefusesRemoteJournalBeforeMigrationOrLiveSchemaReads(): void
    {
        Database::configure(database: 'same_schema');
        Database::configure(index: 2, host: 'remote.invalid', database: 'same_schema');
        Database::registerDatabase('remote', 2);
        Database::useConnection(2);
        $this->mount(StartupRemoteJournalRows::class);
        try {
            JournalTriggerGenerator::plan();
            self::fail('Remote journal DDL must be refused before any SQL');
        } catch (NamedDatabaseException $failure) {
            self::assertStringContainsString('journal DDL is not supported', $failure->getMessage());
            self::assertSame(2, Database::getCurrentIndex());
        }
    }

    public function testSecondarySchemaOnRowZeroCannotGeneratePrimaryTriggers(): void
    {
        Database::configure(database: 'primary_schema');
        Database::configure(index: 2, database: 'other_schema');
        Database::registerDatabase('remote', 2, 0);
        $this->mount(StartupRemoteJournalRows::class);
        $this->expectException(NamedDatabaseException::class);
        $this->expectExceptionMessage('primary physical schema');
        JournalTriggerGenerator::plan();
    }

    /** @param class-string<Objects> $collection Mounted fixture chain */
    private function mount(string $collection): void
    {
        Hilos::$db = new StartupBindingContext($collection);
        Hilos::$db->configure();
    }
}

final class StartupBindingContext extends HilosDbContext
{
    /** @param class-string<Objects> $collection Mounted fixture chain */
    public function __construct(private readonly string $collection)
    {
        parent::__construct();
    }

    /** Mounts real Object/Entity links without loading rows. */
    public function configure(): void
    {
        $this->_objectCollections['fixture'] = $this->collection::initEmpty();
    }
}

class StartupPrimaryEntity extends Entity
{
    public const string _table = 'startup_fixture';
    public const bool _journaled = true;
}

final class StartupEmptyEntity extends StartupPrimaryEntity
{
    public const string _database = '';
}

final class StartupDerivedPrimaryEntity extends StartupPrimaryEntity
{
}

class StartupRemoteEntity extends StartupPrimaryEntity
{
    public const string _database = 'remote';
    public const bool _journaled = false;
}

final class StartupRemoteJournalEntity extends StartupRemoteEntity
{
    public const bool _journaled = true;
}

final class StartupPrimaryObject extends Object_
{
    public const string ENTITY_CLASS = StartupPrimaryEntity::class;
}

final class StartupEmptyObject extends Object_
{
    public const string ENTITY_CLASS = StartupEmptyEntity::class;
}

final class StartupDerivedPrimaryObject extends Object_
{
    public const string ENTITY_CLASS = StartupDerivedPrimaryEntity::class;
}

final class StartupRemoteObject extends Object_
{
    public const string ENTITY_CLASS = StartupRemoteEntity::class;
}

final class StartupRemoteJournalObject extends Object_
{
    public const string ENTITY_CLASS = StartupRemoteJournalEntity::class;
}

final class StartupPrimaryRows extends Objects
{
    public const string OBJECT_CLASS = StartupPrimaryObject::class;
}

final class StartupEmptyRows extends Objects
{
    public const string OBJECT_CLASS = StartupEmptyObject::class;
}

final class StartupDerivedPrimaryRows extends Objects
{
    public const string OBJECT_CLASS = StartupDerivedPrimaryObject::class;
}

final class StartupRemoteRows extends Objects
{
    public const string OBJECT_CLASS = StartupRemoteObject::class;
}

final class StartupRemoteJournalRows extends Objects
{
    public const string OBJECT_CLASS = StartupRemoteJournalObject::class;
}
