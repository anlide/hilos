<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupReferenceRegistry;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Regression for the LSB loss on the backup catalog accessor (HIL-275).
 *
 * The same defect HIL-489 fixed for the notification registries, on the one accessor
 * every backup registry reads through: `Hilos::getBackupCatalogClass()` resolved
 * `static::BACKUP_CATALOG` from a bare `Hilos::` call-site, so it bound to the abstract
 * base and answered its own null however the project declared itself. Everything fed by
 * the catalog - the reference/seed registry and the schedule - therefore read an empty
 * catalog in production, silently.
 *
 * The reference registry is what the case is asked on now: a table missing from it is
 * quietly left out of the schema-seed scope, so the defect looks like a healthy backup
 * until the day it is restored. The PII verdict, which made the defect visible when it
 * still went through the catalog, is declared on the tables themselves since HIL-636.
 */
final class BackupCatalogLsbResolutionTest extends TestCase
{
    protected function tearDown(): void
    {
        // Restore the captured facade class to the base default for later cases.
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testTheBareAccessorResolvesTheProjectCatalogAfterInit(): void
    {
        // Sanity: without a project facade captured, the base accessor names no catalog.
        self::assertNull(Hilos::getBackupCatalogClass());

        BackupCatalogTestHilos::initBrowser();

        self::assertSame(BackupCatalogTestCatalog::class, Hilos::getBackupCatalogClass());
    }

    public function testTheCatalogFedRegistriesSeeTheProjectDeclarationAfterInit(): void
    {
        // Taken before and after init, because "it is there afterwards" proves nothing
        // about where it came from unless it was absent before.
        self::assertSame([], BackupReferenceRegistry::fromCatalog()->tablesForConnection(0));

        BackupCatalogTestHilos::initBrowser();

        self::assertSame(
            ['pii_entity'],
            BackupReferenceRegistry::fromCatalog()->tablesForConnection(0),
            'The reference registry must read what the project declared, not the base facade null',
        );
    }
}

/**
 * Project facade fixture naming a backup catalog of its own.
 */
final class BackupCatalogTestHilos extends Hilos
{
    protected const ?string BACKUP_CATALOG = BackupCatalogTestCatalog::class;

    /**
     * Creates a no-op DB context for the abstract facade contract.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new BackupCatalogTestDbContext();
    }
}

/**
 * No-op DB context so the abstract facade fixture is instantiable.
 */
final class BackupCatalogTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for the catalog resolution fixture.
     */
    public function configure(): void
    {
    }
}

/**
 * Backup catalog fixture declaring one table under the reference registry.
 */
final class BackupCatalogTestCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<int, mixed>> Backup catalog of the fixture project
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_REFERENCES => [0 => [PiiEntityFixture::class]],
        ];
    }
}
