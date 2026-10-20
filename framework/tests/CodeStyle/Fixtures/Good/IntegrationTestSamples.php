<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use Hilos\Database\Database;
use Hilos\Testing\IntegrationTest;
use Hilos\Testing\RollbackIntegrationTestCase;
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
