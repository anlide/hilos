<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\Exception\AnonymizationConfigException;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\ChangeLogTable;
use Hilos\Database\Entity\Item\ChangeLogField;
use Hilos\Database\Entity\Item\ChangeLogReceipt;
use Hilos\Database\Entity\Item\ChangeLogEntry;
use Hilos\Database\Entity\Item\ChangeLogChange;
use Hilos\Database\Entity\Item\ChangeLogValue;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use ReflectionProperty;
use Hilos\Database\Entity\Item\Identity;
use Hilos\Database\Entity\Item\Session;
use Hilos\Database\Schema\TablesWithoutEntityProvider;
use Hilos\Hilos;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the collected PII registry.
 *
 * The thing worth pinning here is that the collection walks what an installation already
 * declared - the collections a DbContext mounted and the classes naming its tables
 * outside the ORM - rather than a list somebody keeps in step by hand. A verdict that
 * says opposite things about one column is refused rather than resolved, and the empty
 * column map stays a classification rather than an absence.
 *
 * The framework's own declaration is checked here too, on the one axis that needs no
 * database: that it is a well-formed registry at all. Whether it covers a real archive
 * is a question about a schema, and demo/chat asks it against a live one.
 */
final class PiiRegistryTest extends TestCase
{
    /**
     * Lower bound on what the framework classifies, so a verdict list that lost its
     * contents cannot pass as a healthy one. Eleven Entities of the framework's own plus
     * the twenty-nine tables it ships outside the ORM; it is a floor, not the exact
     * number, because a new framework table adds a row here and must not fail this test
     * for it.
     */
    private const int MIN_FRAMEWORK_ROWS = 40;

    protected function tearDown(): void
    {
        // Restore the unmounted context and the base facade class for later cases.
        Hilos::$db = null;
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testJournalMountIsTheOnlySourceOfItsOriginalVerdictsOnSchemaOne(): void
    {
        $previous = [];
        foreach (['configurations', 'databaseBindings'] as $property) {
            $previous[$property] = new ReflectionProperty(Database::class, $property)->getValue();
        }
        try {
            Database::configure(database: 'journal_pii_unit');
            Database::configure(index: 1, database: ChangeLogDatabase::configuredName());
            JournalPiiHilos::initBrowser();
            Hilos::$db = new FrameworkPiiTestDbContext();
            Hilos::$db->configure();
            $registry = PiiRegistry::collect();
            foreach (self::journalVerdicts() as [$entityClass, $personal, $notPersonal]) {
                self::assertSame($personal, $registry->strategiesFor(1, $entityClass::_table));
                self::assertSame($notPersonal, $registry->notPersonalColumns(1, $entityClass::_table));
                self::assertContains($entityClass::_table, $registry->declaredTables(1));
                self::assertNotContains($entityClass::_table, $registry->declaredTables(0));
                self::assertSame(1, $entityClass::getDatabase()->schemaIndex);
                self::assertSame(0, $entityClass::getDatabase()->connectionIndex);
            }
            self::assertCount(6, array_intersect(FrameworkSchemaCatalog::CHANGE_LOG_TABLES, $registry->declaredTables(1)));
            self::assertFalse(Database::isConnected(0));
            self::assertFalse(Database::isConnected(1));
        } finally {
            foreach ($previous as $property => $value) {
                new ReflectionProperty(Database::class, $property)->setValue(null, $value);
            }
        }
    }

    /** @return iterable<string, array{class-string<Entity>, array<string, AnonymizationStrategy>, list<string>}> Original journal verdicts */
    public static function journalVerdicts(): iterable
    {
        yield 'Table' => [ChangeLogTable::class, [], [
            'id',
            'name',
        ]];
        yield 'Field' => [ChangeLogField::class, [], [
            'id',
            'table_id',
            'name',
        ]];
        yield 'Receipt' => [ChangeLogReceipt::class, [
            'actor_user_id' => AnonymizationStrategy::NULLIFY,
            'subject_user_id' => AnonymizationStrategy::NULLIFY,
            'session_id' => AnonymizationStrategy::NULLIFY,
            'agent' => AnonymizationStrategy::MASK,
            'source' => AnonymizationStrategy::MASK,
        ], [
            'id',
            'created_at',
            'channel',
            'action',
            'open_handovers',
        ]];
        yield 'Entry' => [ChangeLogEntry::class, [
            'record_key' => AnonymizationStrategy::MASK,
            'record_key_hash' => AnonymizationStrategy::NULLIFY,
        ], [
            'id',
            'created_at',
            'receipt_id',
            'table_id',
            'mutation_type',
        ]];
        yield 'Change' => [ChangeLogChange::class, [
            'old_value' => AnonymizationStrategy::MASK,
            'new_value' => AnonymizationStrategy::MASK,
        ], [
            'id',
            'created_at',
            'log_id',
            'field_id',
            'kind',
            'old_present',
            'new_present',
        ]];
        yield 'Value' => [ChangeLogValue::class, [
            'old_value' => AnonymizationStrategy::MASK,
            'new_value' => AnonymizationStrategy::MASK,
        ], [
            'id',
            'created_at',
            'change_id',
        ]];
    }

    public function testTheFrameworkCollectsAWellFormedRegistryOfItsOwn(): void
    {
        Hilos::$db = new FrameworkPiiTestDbContext();
        Hilos::$db->configure();

        $registry = PiiRegistry::collect();
        $tables = $registry->declaredTables(0);

        $this->assertGreaterThanOrEqual(
            self::MIN_FRAMEWORK_ROWS,
            count($tables),
            'The framework lost verdicts; an unclassified framework table is one every project '
            . 'would then have to classify for it',
        );
        foreach ($tables as $table) {
            $this->assertStringNotContainsString(
                '\\',
                $table,
                "Declared table [{$table}] stayed a class name, so a collection resolved to no table",
            );
        }
    }

    public function testAVerdictIsCollectedOffTheEntityOfAMountedCollection(): void
    {
        Hilos::$db = new FrameworkPiiTestDbContext();
        Hilos::$db->configure();

        $registry = PiiRegistry::collect();

        $this->assertSame(
            [
                Identity::identifier => AnonymizationStrategy::FAKE_EMAIL,
                Identity::secret => AnonymizationStrategy::NULLIFY,
            ],
            $registry->strategiesFor(0, Identity::_table),
        );
        $this->assertContains(
            Identity::provider,
            $registry->notPersonalColumns(0, Identity::_table) ?? [],
            'The non-personal half of the verdict must come out of the registry too',
        );
    }

    #[RunInSeparateProcess]
    public function testEntityVerdictsFollowSchemaIndicesEvenWhenRowsSharePrimary(): void
    {
        Database::configure(database: 'pii_primary');
        Database::configure(index: 1, database: ChangeLogDatabase::configuredName());
        Database::configure(index: 2, host: 'remote.invalid', database: 'pii_primary');
        Database::registerDatabase('pii_remote', 2);
        Database::useConnection(2);
        Hilos::$db = new NamedPiiContext();
        Hilos::$db->configure();

        $registry = PiiRegistry::collect();

        $this->assertSame(['label' => AnonymizationStrategy::MASK], $registry->strategiesFor(0, 'pii_same_table'));
        $this->assertSame(['label' => AnonymizationStrategy::HASH], $registry->strategiesFor(1, 'pii_same_table'));
        $this->assertSame(['id'], $registry->notPersonalColumns(0, 'pii_same_table'));
        $this->assertSame(['id', 'local_only'], $registry->notPersonalColumns(1, 'pii_same_table'));
        $this->assertFalse($registry->isPurged(0, 'pii_same_table'));
        $this->assertFalse($registry->isPurged(1, 'pii_same_table'));
        $this->assertTrue($registry->isPurged(2, 'pii_same_table'));
        $this->assertSame([], $registry->strategiesFor(2, 'pii_same_table'));
        $this->assertSame([], $registry->notPersonalColumns(2, 'pii_same_table'));
        $this->assertSame(0, NamedLocalPiiEntity::getDatabase()->connectionIndex);
        $this->assertSame(1, NamedLocalPiiEntity::getDatabase()->schemaIndex);
        $this->assertSame(2, Database::getCurrentIndex());
        foreach ([0, 1, 2] as $index) {
            $this->assertFalse(Database::isConnected($index));
        }
    }

    public function testAPurgedTableCarriesNoColumnWorkOfEitherHalf(): void
    {
        Hilos::$db = new FrameworkPiiTestDbContext();
        Hilos::$db->configure();

        $registry = PiiRegistry::collect();

        $this->assertTrue($registry->isPurged(0, Session::_table));
        $this->assertSame([], $registry->strategiesFor(0, Session::_table));
        $this->assertSame([], $registry->notPersonalColumns(0, Session::_table));
    }

    public function testAnUnclassifiedTableIsToldApartFromOneDeclaredClean(): void
    {
        $registry = PiiRegistry::collect();

        $this->assertNull($registry->strategiesFor(0, 'never_mentioned'));
        $this->assertNull($registry->notPersonalColumns(0, 'never_mentioned'));
        $this->assertSame([], $registry->strategiesFor(0, 'migration'));
        $this->assertSame(
            ['index', 'failed'],
            $registry->notPersonalColumns(0, 'migration'),
        );
    }

    public function testTheProjectTablesWithoutEntityAreCollectedAfterTheFrameworkOnes(): void
    {
        ProjectTablesWithoutEntityHilos::initBrowser();

        $registry = PiiRegistry::collect();
        $tables = $registry->declaredTables(0);

        $this->assertSame(
            ['label' => AnonymizationStrategy::MASK],
            $registry->strategiesFor(0, 'project_probe'),
        );
        $this->assertSame(['id'], $registry->notPersonalColumns(0, 'project_probe'));
        // Declaration order is pass order, and the framework's own tables lead it.
        $this->assertGreaterThan(
            array_search('migration', $tables, true),
            array_search('project_probe', $tables, true),
        );
    }

    public function testAColumnCalledBothPersonalAndNotIsRefused(): void
    {
        ContradictoryTablesWithoutEntityHilos::initBrowser();

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('project_probe.label');

        PiiRegistry::collect();
    }

    public function testACatalogNamingSomethingElseThanAProviderIsRefused(): void
    {
        NotAProviderHilos::initBrowser();

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage(BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY);

        PiiRegistry::collect();
    }

    public function testAProviderOnASecondaryConnectionGivesVerdictsThereAndNotOnPrimary(): void
    {
        SecondaryDatabaseTablesWithoutEntityHilos::initBrowser();

        $registry = PiiRegistry::collect();

        $this->assertSame(
            ['token' => AnonymizationStrategy::MASK],
            $registry->strategiesFor(1, 'secondary_probe'),
        );
        $this->assertSame(['id'], $registry->notPersonalColumns(1, 'secondary_probe'));
        $this->assertNull($registry->strategiesFor(0, 'secondary_probe'));
        $this->assertNull($registry->notPersonalColumns(0, 'secondary_probe'));
        $this->assertContains('secondary_probe', $registry->declaredTables(1));
        $this->assertNotContains('secondary_probe', $registry->declaredTables(0));
    }

    public function testFrameworkTablesArePresentOnSecondaryConnectionWithProviderAndAbsentWithout(): void
    {
        SecondaryDatabaseTablesWithoutEntityHilos::initBrowser();
        $registryWithSecondary = PiiRegistry::collect();

        $this->assertContains('migration', $registryWithSecondary->declaredTables(1));

        Hilos::resetBrowser();
        ProjectTablesWithoutEntityHilos::initBrowser();
        $registryWithoutSecondary = PiiRegistry::collect();

        $this->assertNotContains('migration', $registryWithoutSecondary->declaredTables(1));
    }

    public function testALegacyStringClassProviderIsRefused(): void
    {
        LegacyStringTablesWithoutEntityHilos::initBrowser();

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage(BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY);

        PiiRegistry::collect();
    }

    public function testANonIntegerConnectionIndexKeyIsRefused(): void
    {
        NonIntegerIndexTablesWithoutEntityHilos::initBrowser();

        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage(BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY);

        PiiRegistry::collect();
    }

    public function testAnEntityKeyResolvesToItsTable(): void
    {
        $registry = PiiRegistry::fromDeclarations([
            0 => [PiiEntityFixture::class => ['email' => AnonymizationStrategy::FAKE_EMAIL]],
        ]);

        $this->assertSame(['pii_entity'], $registry->declaredTables(0));
        $this->assertSame(
            ['email' => AnonymizationStrategy::FAKE_EMAIL],
            $registry->strategiesFor(0, 'pii_entity'),
        );
    }

    public function testARawTableNameIsTakenVerbatim(): void
    {
        $registry = PiiRegistry::fromDeclarations([
            0 => ['hilos_change_log' => ['payload' => AnonymizationStrategy::MASK]],
        ]);

        $this->assertSame(['hilos_change_log'], $registry->declaredTables(0));
        $this->assertSame(
            ['payload' => AnonymizationStrategy::MASK],
            $registry->strategiesFor(0, 'hilos_change_log'),
        );
    }

    public function testADeclaredCleanTableIsToldApartFromAnAbsentOne(): void
    {
        $registry = PiiRegistry::fromDeclarations([0 => ['clean_table' => []]]);

        $this->assertSame([], $registry->strategiesFor(0, 'clean_table'));
        $this->assertNull($registry->strategiesFor(0, 'never_mentioned'));
        $this->assertNull($registry->strategiesFor(1, 'clean_table'), 'Rows belong to one connection');
    }

    public function testRowsOfDifferentTablesAreKeptSideBySide(): void
    {
        $registry = PiiRegistry::fromDeclarations(
            [0 => ['framework_only' => ['a' => AnonymizationStrategy::MASK]]],
            [0 => ['project_only' => ['b' => AnonymizationStrategy::MASK]], 1 => ['other_connection' => []]],
        );

        $this->assertSame(['framework_only', 'project_only'], $registry->declaredTables(0));
        $this->assertSame(['other_connection'], $registry->declaredTables(1));
    }

    public function testAPurgedTableIsDeclaredAndCarriesNoColumnWork(): void
    {
        $registry = PiiRegistry::fromDeclarations([
            0 => ['hilos_session' => AnonymizationStrategy::PURGE],
        ]);

        $this->assertTrue($registry->isPurged(0, 'hilos_session'));
        $this->assertSame([], $registry->strategiesFor(0, 'hilos_session'));
        $this->assertFalse($registry->isPurged(0, 'never_mentioned'));
    }

    public function testAKeyNamingAClassThatIsNoTableIsRefused(): void
    {
        $this->expectException(AnonymizationConfigException::class);

        PiiRegistry::fromDeclarations([0 => [self::class => []]]);
    }

    public function testATableLevelStrategyOtherThanPurgeIsRefused(): void
    {
        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('table-level');

        PiiRegistry::fromDeclarations([0 => ['hilos_session' => AnonymizationStrategy::HASH]]);
    }

    public function testPurgeOnAColumnIsRefused(): void
    {
        $this->expectException(AnonymizationConfigException::class);
        $this->expectExceptionMessage('hilos_session.token');

        PiiRegistry::fromDeclarations([
            0 => ['hilos_session' => ['token' => AnonymizationStrategy::PURGE]],
        ]);
    }

    public function testAValueThatNamesNoStrategyIsRefused(): void
    {
        $this->expectException(AnonymizationConfigException::class);

        PiiRegistry::fromDeclarations([0 => ['hilos_session' => ['token' => 'hash']]]);
    }

    public function testARowThatIsNeitherShapeIsRefused(): void
    {
        $this->expectException(AnonymizationConfigException::class);

        PiiRegistry::fromDeclarations([0 => ['hilos_session' => 'purge']]);
    }
}

/**
 * Minimal Entity fixture exposing only the table name the registry resolves keys to.
 */
final class PiiEntityFixture extends Entity
{
    public const string _table = 'pii_entity';
}

/**
 * DB context fixture mounting the framework's own collections and nothing else.
 */
final class FrameworkPiiTestDbContext extends HilosDbContext
{
}

/**
 * Tables outside the ORM a project might ship, classified the way one should be.
 */
final class ProjectTablesWithoutEntity implements TablesWithoutEntityProvider
{
    /**
     * @return list<string> Table names of the fixture project
     */
    public static function tables(): array
    {
        return array_keys(self::pii());
    }

    /**
     * @return array<string, array<string, AnonymizationStrategy>|AnonymizationStrategy> Verdict per table
     */
    public static function pii(): array
    {
        return ['project_probe' => ['label' => AnonymizationStrategy::MASK]];
    }

    /**
     * @return array<string, list<string>> Non-personal columns per table
     */
    public static function piiNotPersonal(): array
    {
        return ['project_probe' => ['id']];
    }
}

/**
 * The same tables with one column called personal and not personal at once.
 */
final class ContradictoryTablesWithoutEntity implements TablesWithoutEntityProvider
{
    /**
     * @return list<string> Table names of the fixture project
     */
    public static function tables(): array
    {
        return array_keys(self::pii());
    }

    /**
     * @return array<string, array<string, AnonymizationStrategy>|AnonymizationStrategy> Verdict per table
     */
    public static function pii(): array
    {
        return ['project_probe' => ['label' => AnonymizationStrategy::MASK]];
    }

    /**
     * @return array<string, list<string>> Non-personal columns per table
     */
    public static function piiNotPersonal(): array
    {
        return ['project_probe' => ['id', 'label']];
    }
}

/**
 * Backup catalog fixture naming a well-formed provider.
 */
final class ProjectTablesWithoutEntityCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, mixed> Backup catalog of the fixture project
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => [
                DatabaseConnectionDefaults::PRIMARY_INDEX => ProjectTablesWithoutEntity::class,
            ],
        ];
    }
}

/**
 * Backup catalog fixture naming a provider that contradicts itself.
 */
final class ContradictoryTablesWithoutEntityCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, mixed> Backup catalog of the fixture project
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => [
                DatabaseConnectionDefaults::PRIMARY_INDEX => ContradictoryTablesWithoutEntity::class,
            ],
        ];
    }
}

/**
 * Backup catalog fixture naming a class that provides nothing of the sort.
 */
final class NotAProviderCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, mixed> Backup catalog of the fixture project
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => [
                DatabaseConnectionDefaults::PRIMARY_INDEX => PiiEntityFixture::class,
            ],
        ];
    }
}

/**
 * Project facade fixture naming the well-formed provider's catalog.
 */
final class ProjectTablesWithoutEntityHilos extends Hilos
{
    protected const ?string BACKUP_CATALOG = ProjectTablesWithoutEntityCatalog::class;

    /**
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new FrameworkPiiTestDbContext();
    }
}

/**
 * Project facade fixture naming the self-contradicting provider's catalog.
 */
final class ContradictoryTablesWithoutEntityHilos extends Hilos
{
    protected const ?string BACKUP_CATALOG = ContradictoryTablesWithoutEntityCatalog::class;

    /**
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new FrameworkPiiTestDbContext();
    }
}

/**
 * Project facade fixture naming a catalog whose provider key holds no provider.
 */
final class NotAProviderHilos extends Hilos
{
    protected const ?string BACKUP_CATALOG = NotAProviderCatalog::class;

    /**
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new FrameworkPiiTestDbContext();
    }
}

/**
 * Tables outside the ORM on a non-primary connection.
 */
final class SecondaryDatabaseTablesWithoutEntity implements TablesWithoutEntityProvider
{
    /**
     * @return list<string> Table names of the fixture project on the secondary connection
     */
    public static function tables(): array
    {
        return ['secondary_probe'];
    }

    /**
     * @return array<string, array<string, AnonymizationStrategy>|AnonymizationStrategy>
     */
    public static function pii(): array
    {
        return ['secondary_probe' => ['token' => AnonymizationStrategy::MASK]];
    }

    /**
     * @return array<string, list<string>> Non-personal columns per table
     */
    public static function piiNotPersonal(): array
    {
        return ['secondary_probe' => ['id']];
    }
}

/**
 * Backup catalog fixture naming a provider on a non-primary connection.
 */
final class SecondaryDatabaseTablesWithoutEntityCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, mixed> Backup catalog of the fixture project
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => [
                1 => SecondaryDatabaseTablesWithoutEntity::class,
            ],
        ];
    }
}

/**
 * Project facade fixture naming the secondary connection provider's catalog.
 */
final class SecondaryDatabaseTablesWithoutEntityHilos extends Hilos
{
    protected const ?string BACKUP_CATALOG = SecondaryDatabaseTablesWithoutEntityCatalog::class;

    /**
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new FrameworkPiiTestDbContext();
    }
}

/**
 * Backup catalog fixture naming the legacy string-class form.
 */
final class LegacyStringTablesWithoutEntityCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, mixed> Backup catalog of the fixture project
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => ProjectTablesWithoutEntity::class,
        ];
    }
}

/**
 * Project facade fixture naming a legacy string-class catalog.
 */
final class LegacyStringTablesWithoutEntityHilos extends Hilos
{
    protected const ?string BACKUP_CATALOG = LegacyStringTablesWithoutEntityCatalog::class;

    /**
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new FrameworkPiiTestDbContext();
    }
}

/**
 * Backup catalog fixture naming a provider with a non-integer connection index key.
 */
final class NonIntegerIndexTablesWithoutEntityCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, mixed> Backup catalog of the fixture project
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => [
                'not-an-int' => ProjectTablesWithoutEntity::class,
            ],
        ];
    }
}

/**
 * Project facade fixture naming a non-integer connection index catalog.
 */
final class NonIntegerIndexTablesWithoutEntityHilos extends Hilos
{
    protected const ?string BACKUP_CATALOG = NonIntegerIndexTablesWithoutEntityCatalog::class;

    /**
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new FrameworkPiiTestDbContext();
    }
}

/** Mounts three same-named tables whose verdicts must never replace one another. */
final class NamedPiiContext extends HilosDbContext
{
    public function configure(): void
    {
        foreach ([NamedPrimaryPiiRows::class, NamedLocalPiiRows::class, NamedRemotePiiRows::class] as $class) {
            $this->_objectCollections[$class] = $class::initEmpty();
        }
    }
}

class NamedPrimaryPiiEntity extends Entity
{
    public const string _table = 'pii_same_table';
    public const array _pii = ['label' => AnonymizationStrategy::MASK];
    public const array _piiNotPersonal = ['id'];
}

final class NamedLocalPiiEntity extends NamedPrimaryPiiEntity
{
    public const string _database = DatabaseNames::CHANGE_LOG;
    public const array _pii = ['label' => AnonymizationStrategy::HASH];
    public const array _piiNotPersonal = ['id', 'local_only'];
}

final class NamedRemotePiiEntity extends Entity
{
    public const string _table = NamedPrimaryPiiEntity::_table;
    public const string _database = 'pii_remote';
    public const AnonymizationStrategy _pii = AnonymizationStrategy::PURGE;
}

final class NamedPrimaryPiiObject extends Object_
{
    public const string ENTITY_CLASS = NamedPrimaryPiiEntity::class;
}

final class NamedPrimaryPiiRows extends Objects
{
    public const string OBJECT_CLASS = NamedPrimaryPiiObject::class;
}

final class NamedLocalPiiObject extends Object_
{
    public const string ENTITY_CLASS = NamedLocalPiiEntity::class;
}

final class NamedLocalPiiRows extends Objects
{
    public const string OBJECT_CLASS = NamedLocalPiiObject::class;
}

final class NamedRemotePiiObject extends Object_
{
    public const string ENTITY_CLASS = NamedRemotePiiEntity::class;
}

final class NamedRemotePiiRows extends Objects
{
    public const string OBJECT_CLASS = NamedRemotePiiObject::class;
}

/** Activates journal mounts without a tables-without-Entity provider. */
final class JournalPiiHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::CHANGE_LOG];

    /** @return HilosDbContext The real framework mounts */
    protected static function createDb(): HilosDbContext
    {
        return new FrameworkPiiTestDbContext();
    }
}
