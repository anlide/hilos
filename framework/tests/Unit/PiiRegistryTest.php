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
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\Identity;
use Hilos\Database\Entity\Item\Session;
use Hilos\Database\Schema\TablesWithoutEntityProvider;
use Hilos\Hilos;
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
        $this->assertSame([], $registry->strategiesFor(0, 'hilos_change_log_table'));
        $this->assertSame(
            ['id', 'name'],
            $registry->notPersonalColumns(0, 'hilos_change_log_table'),
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
        return [BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => ProjectTablesWithoutEntity::class];
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
        return [BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => ContradictoryTablesWithoutEntity::class];
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
        return [BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => PiiEntityFixture::class];
    }
}

/**
 * Project facade fixture naming the well-formed provider's catalog.
 */
final class ProjectTablesWithoutEntityHilos extends Hilos
{
    protected const ?string BACKUP_CATALOG = ProjectTablesWithoutEntityCatalog::class;

    /**
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
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
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
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
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new FrameworkPiiTestDbContext();
    }
}
