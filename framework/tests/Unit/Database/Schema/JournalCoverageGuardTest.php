<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Schema;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Entity\Item\Identity;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\Entity\Item\ModerationRule;
use Hilos\Database\Entity\Item\SecondFactor;
use Hilos\Database\Entity\Item\Setting;
use Hilos\Database\Entity\Item\User;
use Hilos\Database\Schema\JournalCoverageGuard;
use Hilos\Database\Schema\JournaledTables;
use Hilos\Hilos;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** The journal catalog follows mounted Entities and ignores tables without one. */
final class JournalCoverageGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        Hilos::$db = null;

        parent::tearDown();
    }

    public function testNoMountedTablesNeedNoSchemaRead(): void
    {
        $this->assertSame([], JournaledTables::mounted());
        JournalCoverageGuard::assertMountedTablesPlaced();
    }

    #[RunInSeparateProcess]
    public function testNoJournalConnectionLeavesMountedTablesUnjudged(): void
    {
        Database::configure(DatabaseConnectionDefaults::PRIMARY_INDEX, database: 'unused');
        Hilos::$db = new JournalFrameworkDbContext();
        Hilos::$db->configure();

        $this->assertNotSame([], JournaledTables::mounted());
        JournalCoverageGuard::assertMountedTablesPlaced();
        $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
    }

    #[RunInSeparateProcess]
    public function testNoJournalConnectionAlsoLeavesRemoteTablesUnjudged(): void
    {
        Database::configure(database: 'main_schema');
        Database::configure(index: 2, host: 'remote.invalid', database: 'remote_schema');
        Database::registerDatabase('coverage_remote', 2);
        Database::useConnection(2);
        Hilos::$db = new RemoteCoverageContext();
        Hilos::$db->configure();
        $this->assertSame(['remote' => RemoteCoverageEntity::class], JournaledTables::mounted());
        JournalCoverageGuard::assertMountedTablesPlaced();
        $this->assertSame(2, Database::getCurrentIndex());
        $this->assertFalse(Database::isConnected(0));
        $this->assertFalse(Database::isConnected(2));
    }

    #[RunInSeparateProcess]
    public function testAnEmptyCatalogDoesNotReadAnyConfiguredSchema(): void
    {
        Database::configure(database: 'main_schema');
        Database::configure(index: 1, database: 'main_schema-change-log');
        Database::useConnection(1);
        JournalCoverageGuard::assertMountedTablesPlaced();
        $this->assertSame(1, Database::getCurrentIndex());
        $this->assertFalse(Database::isConnected(0));
        $this->assertFalse(Database::isConnected(1));
    }

    public function testTheFrameworkCatalogContainsExactlyFiveDeclaredEntities(): void
    {
        Hilos::$db = new JournalFrameworkDbContext();
        Hilos::$db->configure();

        $this->assertSame([
            HilosDbContext::settings => Setting::class,
            HilosDbContext::identities => Identity::class,
            HilosDbContext::secondFactors => SecondFactor::class,
            HilosDbContext::moderationRules => ModerationRule::class,
            HilosDbContext::users => User::class,
        ], JournaledTables::mounted());
    }
}

final class JournalFrameworkDbContext extends HilosDbContext
{
}

final class RemoteCoverageEntity extends Entity
{
    public const string _table = 'coverage_fixture';
    public const string _database = 'coverage_remote';
    public const bool _journaled = true;
}

final class RemoteCoverageObject extends Object_
{
    public const string ENTITY_CLASS = RemoteCoverageEntity::class;
}

final class RemoteCoverageRows extends Objects
{
    public const string OBJECT_CLASS = RemoteCoverageObject::class;
}

final class RemoteCoverageContext extends HilosDbContext
{
    public function configure(): void
    {
        $this->_objectCollections['remote'] = RemoteCoverageRows::initEmpty();
    }
}
