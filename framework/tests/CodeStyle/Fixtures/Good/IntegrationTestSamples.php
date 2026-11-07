<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use Hilos\Database\Database;
use Hilos\Testing\BreaksOrmState;
use Hilos\Testing\BreaksOrmState as OrmReset;
use Hilos\Testing\IntegrationTest;
use Hilos\Testing\RollbackIntegrationTestCase;
use Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Integration\Tests\DerivedOrmReset;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Vendor\IntegrationTest as ForeignIntegrationTest;

#[Group('integration')]
final class LegacyIntegration extends TestCase
{
    public function setUp(): void
    {
        Database::transactionCommit();
        Database::sqlRun('CREATE TABLE legacy (id INT)');
    }
}

#[ForeignIntegrationTest]
final class ForeignMarker extends TestCase
{
    public function tearDown(): void
    {
        Database::transactionCommit();
    }
}

#[IntegrationTest]
final class SafeIntegration extends RollbackIntegrationTestCase
{
    protected function setUpInTransaction(): void
    {
        $description = 'DROP TABLE is documentation, not SQL';
        self::assertSame($description, 'DROP TABLE is documentation, not SQL');
        Database::sqlRun("INSERT INTO notes (body) VALUES ('COMMIT; DROP TABLE notes')");
        Database::sql('SELECT "CREATE TABLE", "BEGIN"');
        Database::transactionStart();
        Database::transactionRollback();
    }
}

#[IntegrationTest]
final class DirectOrmReset extends RollbackIntegrationTestCase implements BreaksOrmState
{
}

#[IntegrationTest]
final class AliasedOrmReset extends RollbackIntegrationTestCase implements OrmReset
{
}

#[IntegrationTest]
class MarkedOrmCarrier extends RollbackIntegrationTestCase implements BreaksOrmState
{
}

#[IntegrationTest]
final class InheritedOrmReset extends MarkedOrmCarrier
{
}

#[IntegrationTest]
final class InheritedInterfaceReset extends RollbackIntegrationTestCase implements DerivedOrmReset
{
}
