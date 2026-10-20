<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\ThrowsTree\Integration\Tests;

use Hilos\Database\Database as Db;
use Hilos\Testing\IntegrationTest;
use mysqli;
use PDO as NativeConnection;

#[IntegrationTest]
final class SqlCases extends MiddleEnvironment
{
    private const string DDL = 'RENAME TABLE fixture TO moved';

    public function exercise(NativeConnection $native, mysqli $wire, string $table): void
    {
        Db::sqlRun('CREATE TABLE ' . $table . ' (id INT)');
        Db::sqlRun('/* ordinary comment */ DROP TABLE fixture');
        Db::sqlRun('SELECT 1; COMMIT');
        $sql = 'ALTER TABLE fixture ADD flag INT';
        Db::sqlRun($sql);
        Db::sqlRun(self::DDL);
        Db::sqlRun(sql: 'START TRANSACTION');
        Db::sqlRun(<<<'SQL'
            TRUNCATE TABLE fixture
            SQL);
        Db::sqlRun('SET autocommit = 1');
        Db::sqlRun('/*! COMMIT */');
        $callback = static fn() => Db::transactionCommit();
        $callback();
        $native->commit();
        $native->beginTransaction();
        $native->exec('DROP TABLE fixture');
        $wire?->autocommit(true);
        mysqli_query($wire, 'SELECT 1');
        new NativeConnection('mysql:host=localhost');
        new mysqli();
    }
}

#[IntegrationTest]
final class SqlLookAlikes extends MiddleEnvironment
{
    public function exercise(UserlandWriter $writer): void
    {
        $sql = 'DROP TABLE fixture';
        self::assertSame('DROP TABLE fixture', $sql);
        Db::sqlRun('INSERT INTO fixture (id) VALUES (1)');
        Db::sql("SELECT 'COMMIT; CREATE TABLE fixture'");
        Db::sql('SELECT 1 /* DROP TABLE fixture */');
        Db::sql('SELECT 1; -- COMMIT' . "\n" . 'SELECT 2');
        $writer->commit();
        $writer->query('DROP TABLE is a search term');
        Db::sql("SELECT 'tail\\'; SELECT 2");
        Db::sql('SELECT 1 AS "tail\\"; SELECT 2');
        Db::sql('SELECT \'tail\\\' AS "tail\\"; SELECT 2');
        Db::sql("SELECT 'it\\'s; COMMIT'; SELECT 2");
    }
}

final class UserlandWriter
{
    public function commit(): void
    {
    }

    public function query(string $term): void
    {
        strlen($term);
    }
}
