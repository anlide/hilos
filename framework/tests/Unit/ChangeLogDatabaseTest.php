<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\DatabaseException;
use PHPUnit\Framework\TestCase;

/**
 * The database name leaves configuration as an SQL identifier, not a bound value.
 */
final class ChangeLogDatabaseTest extends TestCase
{
    public function testDerivationPreservesSupportedNamesAndQuotesTheResult(): void
    {
        $name = ChangeLogDatabase::name('hilos_test%1');

        $this->assertSame('hilos_test%1-change-log', $name);
        $this->assertSame('`hilos_test%1-change-log`', ChangeLogDatabase::identifier($name));
    }

    public function testIdentifierRefusesSqlSyntaxInAnEnvironmentName(): void
    {
        $this->expectException(DatabaseException::class);

        ChangeLogDatabase::name('chat`; DROP DATABASE main; --');
    }

    public function testIdentifierLimitIncludesTheSuffix(): void
    {
        $this->expectException(DatabaseException::class);

        ChangeLogDatabase::name(str_repeat('a', 64));
    }
}
