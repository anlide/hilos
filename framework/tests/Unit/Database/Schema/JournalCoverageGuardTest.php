<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database\Schema;

use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Entity\Item\Identity;
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

    public function testTheFrameworkCatalogContainsExactlyFourDeclaredEntities(): void
    {
        Hilos::$db = new JournalFrameworkDbContext();
        Hilos::$db->configure();

        $this->assertSame([
            HilosDbContext::settings => Setting::class,
            HilosDbContext::identities => Identity::class,
            HilosDbContext::secondFactors => SecondFactor::class,
            HilosDbContext::users => User::class,
        ], JournaledTables::mounted());
    }
}

final class JournalFrameworkDbContext extends HilosDbContext
{
}
