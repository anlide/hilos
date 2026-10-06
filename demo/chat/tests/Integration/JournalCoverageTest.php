<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Hilos\Backup\Anonymization\LiveSchemaReader;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Entity\Item\Identity;
use Hilos\Database\Entity\Item\SecondFactor;
use Hilos\Database\Entity\Item\Setting;
use Hilos\Database\Entity\Item\User;
use Hilos\Database\Exception\UnplacedJournalColumnException;
use Hilos\Database\Schema\JournalColumnPolicy;
use Hilos\Database\Schema\JournalCoverageGuard;
use Hilos\Database\Schema\JournaledTables;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Hilos as HilosFacade;

/** The chat migration schema satisfies the Entity declarations the daemon judges. */
final class JournalCoverageTest extends IntegrationTestCase
{
    public function testTheFourFrameworkTablesPassOnTheLiveSchema(): void
    {
        $schemas = LiveSchemaReader::read(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $pii = PiiRegistry::collect();
        $mounted = JournaledTables::mounted();

        foreach ([User::class, Identity::class, SecondFactor::class, Setting::class] as $entityClass) {
            $this->assertContains($entityClass, $mounted);
            $this->assertArrayHasKey($entityClass::_table, $schemas);
            $placements = JournalColumnPolicy::forTable($entityClass, $schemas[$entityClass::_table], $pii);
            $this->assertCount(count($schemas[$entityClass::_table]->columns), $placements);
        }

        Database::useConnection(ChangeLogDatabase::CONNECTION_INDEX);
        try {
            JournalCoverageGuard::assertMountedTablesPlaced();
            $this->assertSame(ChangeLogDatabase::CONNECTION_INDEX, Database::getCurrentIndex());
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    public function testAllUnclassifiedSqlColumnsAreNamedBeforeStartup(): void
    {
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::sqlRun('ALTER TABLE `hilos_setting` ADD COLUMN `journal_coverage_probe` INT NULL');
        try {
            Database::sqlRun('ALTER TABLE `hilos_user` ADD COLUMN `journal_coverage_probe` INT NULL');
            try {
                try {
                    JournalCoverageGuard::assertMountedTablesPlaced();
                    $this->fail('The startup guard must reject DB-only columns without verdicts');
                } catch (UnplacedJournalColumnException $failure) {
                    $this->assertStringContainsString('hilos_setting.journal_coverage_probe', $failure->getMessage());
                    $this->assertStringContainsString('hilos_user.journal_coverage_probe', $failure->getMessage());
                }
                $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
            } finally {
                Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
                Database::sqlRun('ALTER TABLE `hilos_user` DROP COLUMN `journal_coverage_probe`');
            }
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            Database::sqlRun('ALTER TABLE `hilos_setting` DROP COLUMN `journal_coverage_probe`');
        }
    }

    public function testAnOptionalJournaledTableAbsentFromTheSchemaIsSkipped(): void
    {
        $original = HilosFacade::$db;
        HilosFacade::$db = new OptionalJournalDbContext();
        HilosFacade::$db->configure();
        try {
            $this->assertSame([OptionalJournalObjects::class => OptionalJournalEntity::class], JournaledTables::mounted());
            JournalCoverageGuard::assertMountedTablesPlaced();
        } finally {
            HilosFacade::$db = $original;
        }
    }
}

final class OptionalJournalDbContext extends HilosDbContext
{
    public function configure(): void
    {
        $this->_objectCollections[OptionalJournalObjects::class]
            = OptionalJournalObjects::initDB(Objects::LAZY_STRATEGY_KEY);
    }
}

final class OptionalJournalEntity extends Entity
{
    public const string _table = 'optional_journal_table';
    public const bool _journaled = true;
    public const array _pii = [];
    public const array _piiNotPersonal = [];
}

/** @extends Object_<OptionalJournalEntity> */
final class OptionalJournalObject extends Object_
{
    public const string ENTITY_CLASS = OptionalJournalEntity::class;
}

/** @extends Objects<OptionalJournalObject> */
final class OptionalJournalObjects extends Objects
{
    public const string OBJECT_CLASS = OptionalJournalObject::class;
}
