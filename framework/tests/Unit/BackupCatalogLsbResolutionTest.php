<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\PiiRegistry;
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
 * the catalog - the reference/seed registry, the schedule, and the PII registry this
 * ticket adds - therefore read an empty catalog in production, silently.
 *
 * The PII registry is the case that made it visible: an empty registry there is not a
 * quiet default but a refused restore, because a project that classified nothing must not
 * be told its data was anonymized.
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
        // The merged PII registry always carries the framework's own rows, so presence of
        // the project row - not the size of the list - is what tells the two accessors
        // apart. Taken before and after init, because "it is there afterwards" proves
        // nothing about where it came from unless it was absent before.
        self::assertNotContains('pii_entity', PiiRegistry::fromCatalog()->declaredTables(0));

        BackupCatalogTestHilos::initBrowser();

        self::assertContains(
            'pii_entity',
            PiiRegistry::fromCatalog()->declaredTables(0),
            'The PII registry must read what the project declared, not the base facade null',
        );
        self::assertSame(
            ['pii_entity'],
            BackupReferenceRegistry::fromCatalog()->tablesForConnection(0),
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
 * Backup catalog fixture declaring one table under both catalog-fed registries.
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
            BackupConstants::CATALOG_PII => [
                0 => [PiiEntityFixture::class => ['email' => AnonymizationStrategy::FAKE_EMAIL]],
            ],
        ];
    }
}
