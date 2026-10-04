<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Database\DatabaseSql;
use Hilos\Database\MysqlClientErrorCode;
use PHPUnit\Framework\TestCase;

/**
 * The reconnect policy's error and read classifications do not need a live server.
 */
final class DatabaseReconnectPolicyTest extends TestCase
{
    public function testReconnectWaitsOutOnlyTemporaryClientFailures(): void
    {
        $this->assertSame([2006, 2013, 2002, 2003], MysqlClientErrorCode::retriedOnReconnectValues());
        foreach (MysqlClientErrorCode::retriedOnReconnectValues() as $error) {
            $this->assertTrue(MysqlClientErrorCode::isRetriedOnReconnect($error));
        }
        $this->assertFalse(MysqlClientErrorCode::isRetriedOnReconnect(1045));
        $this->assertFalse(MysqlClientErrorCode::isRetriedOnReconnect(1049));
    }

    public function testOnlySelectAndShowArePureReads(): void
    {
        $this->assertTrue(DatabaseSql::onlyReads("  select * from users"));
        $this->assertTrue(DatabaseSql::onlyReads("\nSHOW TABLES"));
        $this->assertFalse(DatabaseSql::onlyReads('SELECTED value'));
        $this->assertFalse(DatabaseSql::onlyReads('UPDATE users SET name = ?'));
        $this->assertFalse(DatabaseSql::onlyReads('WITH selected AS (SELECT 1) INSERT INTO t VALUES (?)'));
    }
}
