<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Pages\Daemon\HilosDaemonDatabaseReader;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** The infrastructure read preserves the active connection and never initializes a schema. */
final class HilosDaemonDatabaseReaderTest extends TestCase
{
    /** @var array<string, mixed> Static driver state restored after each case */
    private array $previousDatabase = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['configurations', 'currentIndex', 'connections', 'resultSets', 'lastSql', 'closedByHand'] as $name) {
            $this->previousDatabase[$name] = (new ReflectionProperty(Database::class, $name))->getValue();
        }
        (new ReflectionProperty(Database::class, 'configurations'))->setValue(null, []);
        (new ReflectionProperty(Database::class, 'connections'))->setValue(null, []);
        (new ReflectionProperty(Database::class, 'currentIndex'))->setValue(null, DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::configure(9, database: 'secondary');
        Database::useConnection(9);
    }

    protected function tearDown(): void
    {
        foreach ($this->previousDatabase as $name => $value) {
            (new ReflectionProperty(Database::class, $name))->setValue(null, $value);
        }
        parent::tearDown();
    }

    public function testMissingPrimaryConfigReturnsNullWithoutQueryingOrChangingConnection(): void
    {
        $reader = new DaemonDatabaseReaderProbe(['max_index' => 10]);

        self::assertNull($reader->read());
        self::assertSame([], $reader->queries);
        self::assertSame(9, Database::getCurrentIndex());
    }

    public function testPrimaryAddressAndCommittedMigrationAreReadWithoutCredentials(): void
    {
        $this->configurePrimary();
        $reader = new DaemonDatabaseReaderProbe(['max_index' => '1388']);

        self::assertSame([
            'host' => 'primary.example', 'port' => 3307, 'name' => 'primary-db',
            'socket' => '/run/mysql.sock', 'migrationIndex' => 1388,
        ], $reader->read()?->toArray());
        self::assertSame([[
            DatabaseConnectionDefaults::PRIMARY_INDEX,
            'SELECT MAX(`index`) AS max_index FROM `migration` WHERE `failed` = 0',
        ]], $reader->queries);
        self::assertSame(9, Database::getCurrentIndex());
    }

    public function testEmptySuccessfulHistoryIsZeroButNoRowOrFieldIsUnknown(): void
    {
        $this->configurePrimary();
        self::assertSame(0, (new DaemonDatabaseReaderProbe(['max_index' => null]))->read()?->migrationIndex);
        self::assertSame(0, (new DaemonDatabaseReaderProbe(['max_index' => 0]))->read()?->migrationIndex);
        self::assertNull((new DaemonDatabaseReaderProbe(null))->read()?->migrationIndex);
        self::assertNull((new DaemonDatabaseReaderProbe([]))->read()?->migrationIndex);
        self::assertSame(9, Database::getCurrentIndex());
    }

    public function testQueryRefusalKeepsAddressAndRestoresActiveConnectionWithNoRetry(): void
    {
        $this->configurePrimary();
        $reader = new DaemonDatabaseReaderProbe(null, fails: true);

        self::assertSame([
            'host' => 'primary.example', 'port' => 3307, 'name' => 'primary-db',
            'socket' => '/run/mysql.sock', 'migrationIndex' => null,
        ], $reader->read()?->toArray());
        self::assertCount(1, $reader->queries);
        self::assertSame(9, Database::getCurrentIndex());
    }

    public function testDefaultQueryDoesNotConnectAnUnopenedPrimaryOrInitializeMigrationTables(): void
    {
        $this->configurePrimary();
        $connections = (new ReflectionProperty(Database::class, 'connections'))->getValue();
        self::assertNull((new HilosDaemonDatabaseReader())->read()?->migrationIndex);
        self::assertSame($connections, (new ReflectionProperty(Database::class, 'connections'))->getValue());
        self::assertSame(9, Database::getCurrentIndex());

        // The driver is static. The seam above verifies the SQL request; this checks the
        // production adapter's no-reconnect flag without pretending a stub opened MySQL.
        $method = new ReflectionMethod(HilosDaemonDatabaseReader::class, 'query');
        $source = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1));
        self::assertStringContainsString('Database::sql($sql, null, false);', $source);
        self::assertStringContainsString('Database::row()', $source);
        self::assertStringNotContainsString('Migration::', $source);
    }

    private function configurePrimary(): void
    {
        Database::configure(DatabaseConnectionDefaults::PRIMARY_INDEX, 'primary.example', 'private-user', 'private-password',
            'primary-db', port: 3307, socket: '/run/mysql.sock');
    }
}

/** Supplies raw query outcomes only; configuration and connection switching remain real. */
final class DaemonDatabaseReaderProbe extends HilosDaemonDatabaseReader
{
    /** @var list<array{int, string}> Query attempts and their active database */
    public array $queries = [];

    /** @param ?array<string, mixed> $row Raw aggregate row; @param bool $fails Whether the query refuses */
    public function __construct(private readonly ?array $row, private readonly bool $fails = false)
    {
    }

    /** @return ?array<string, mixed> Stubbed row; @throws DatabaseException On an injected query refusal */
    protected function query(string $sql): ?array
    {
        $this->queries[] = [Database::getCurrentIndex(), $sql];
        if ($this->fails) {
            throw new DatabaseException('History is unavailable');
        }
        return $this->row;
    }
}
