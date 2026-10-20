<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Bootstrap\EntrypointPrelude;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use mysqli;
use ReflectionProperty;

/**
 * A fresh mysqli handle runs in UTC, including one opened while PHP is on another clock.
 *
 * This case starts the process in Europe/Kyiv. The prelude then puts PHP on UTC.
 * The session clock is set by the connection itself and does not follow PHP.
 */
final class UtcDatabaseConnectionIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string PROCESS_ZONE = 'Europe/Kyiv';

    private const string SHIFTED_ZONE = '+05:00';

    private const string OTHER_CHARSET = 'latin1';

    private const int EXTRA_INDEX = 1;

    private const string SESSION_CLOCK_SQL = 'SELECT @@session.time_zone AS session_zone, '
        . 'NOW() = UTC_TIMESTAMP() AS same_instant';

    private string $timezoneBefore = TimeConstants::UTC_TIME_ZONE;

    /**
     * @throws DatabaseException When the primary connection cannot be opened
     * @throws EnvException When DB env variables are missing or invalid
     */
    protected function setUp(): void
    {
        $this->timezoneBefore = date_default_timezone_get();
        date_default_timezone_set(self::PROCESS_ZONE);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            date_default_timezone_set($this->timezoneBefore);
        }
    }

    /**
     * @throws DatabaseException When a probe query fails
     * @throws EnvException When the isolated prelude cannot read its env file
     */
    public function testPrimarySessionIsUtcWhilePhpStartsInKyiv(): void
    {
        $this->assertNotSame('+00:00', date('P'));
        $this->assertSessionUtc();
        $this->assertSame(DatabaseConnectionDefaults::CHARSET, $this->connectionCharset());
        $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());

        $globalZone = $this->globalZone();
        $root = sys_get_temp_dir() . '/hilos-utc-db-' . bin2hex(random_bytes(4));
        $previousEnv = Hilos::$env;
        $previousLlm = Hilos::$llm;
        $previousCluster = Hilos::$cluster;
        $previousAppClass = Hilos::appClass();
        $migrationPath = new ReflectionProperty(Migration::class, 'migrationListPath');
        $migrationName = new ReflectionProperty(Migration::class, 'migrationName');
        $previousMigrationPath = $migrationPath->getValue();
        $previousMigrationName = $migrationName->getValue();
        $replaced = false;

        try {
            if (!mkdir($root) && !is_dir($root)) {
                $this->fail("Cannot create {$root}");
            }
            if (!mkdir($root . '/tests') && !is_dir($root . '/tests')) {
                $this->fail("Cannot create {$root}/tests");
            }
            if (file_put_contents($root . '/.env', "HILOS_PROJECT_TIME_ZONE=Europe/Kyiv\n") === false) {
                $this->fail('Cannot write .env');
            }
            if (file_put_contents($root . '/tests/.env', "APP_ENV=test\n") === false) {
                $this->fail('Cannot write tests/.env');
            }

            Hilos::$env = null;
            $replaced = true;
            EntrypointPrelude::initEnvironment(Hilos::class, $root);

            $this->assertSame(TimeConstants::UTC_TIME_ZONE, date_default_timezone_get());
            $this->assertSame('+00:00', date('P'));
            $second = time();
            $this->assertContains(date('Y-m-d H:i:s'), [
                gmdate('Y-m-d H:i:s', $second - 1),
                gmdate('Y-m-d H:i:s', $second),
                gmdate('Y-m-d H:i:s', $second + 1),
            ]);
        } finally {
            if ($replaced) {
                Hilos::$env = $previousEnv;
                Hilos::$llm = $previousLlm;
                Hilos::$cluster = $previousCluster;
                new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $previousAppClass);
                $migrationPath->setValue(null, $previousMigrationPath);
                $migrationName->setValue(null, $previousMigrationName);
            }
            $this->removePreludeRoot($root);
        }

        $this->assertSessionUtc();
        $this->assertSame($globalZone, $this->globalZone());
        $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
    }

    /**
     * @throws DatabaseException When the extra connection or a probe query fails
     */
    public function testNonDefaultCharsetIndexIsUtc(): void
    {
        $this->assertNotSame(DatabaseConnectionDefaults::CHARSET, self::OTHER_CHARSET);
        $primary = Database::getConnectionConfig(DatabaseConnectionDefaults::PRIMARY_INDEX);
        $globalZone = $this->globalZone();

        Database::configure(
            index: self::EXTRA_INDEX,
            host: $primary->host,
            user: $primary->user,
            password: $primary->password,
            database: $primary->database,
            port: $primary->port,
            charset: self::OTHER_CHARSET,
        );

        try {
            Database::connect(self::EXTRA_INDEX);
            Database::useConnection(self::EXTRA_INDEX);
            $this->assertSessionUtc();
            $this->assertSame(self::OTHER_CHARSET, $this->connectionCharset());
        } finally {
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
            if (Database::isConnected(self::EXTRA_INDEX)) {
                Database::close(self::EXTRA_INDEX);
            }
        }

        $this->assertFalse(Database::isConnected(self::EXTRA_INDEX));
        $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
        $this->assertSessionUtc();
        $this->assertSame($globalZone, $this->globalZone());
    }

    /**
     * @throws DatabaseException When close, connect, or a probe query fails
     */
    public function testManualZoneDoesNotSurviveCloseAndConnect(): void
    {
        $globalZone = $this->globalZone();
        $previousId = $this->currentConnectionId();
        Database::sql("SET SESSION time_zone = '" . self::SHIFTED_ZONE . "'");
        $this->assertSessionZone(self::SHIFTED_ZONE, 0);

        Database::close(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::connect(DatabaseConnectionDefaults::PRIMARY_INDEX);

        $this->assertSessionUtc();
        $this->assertNotSame($previousId, $this->currentConnectionId());
        $this->assertSame($globalZone, $this->globalZone());
        $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
    }

    /**
     * @throws DatabaseException When the reconnect or a probe query fails
     * @throws EnvException When DB env variables are missing or invalid
     */
    public function testManualZoneDoesNotSurviveReconnect(): void
    {
        $globalZone = $this->globalZone();
        $killedId = $this->currentConnectionId();
        Database::sql("SET SESSION time_zone = '" . self::SHIFTED_ZONE . "'");
        $this->assertSessionZone(self::SHIFTED_ZONE, 0);
        $this->killConnection($killedId);

        $this->assertSessionUtc();
        $this->assertNotSame($killedId, $this->currentConnectionId());
        $this->assertSame($globalZone, $this->globalZone());
        $this->assertSame(DatabaseConnectionDefaults::PRIMARY_INDEX, Database::getCurrentIndex());
    }

    /**
     * @throws DatabaseException When the probe query fails
     */
    private function assertSessionUtc(): void
    {
        $this->assertSessionZone('+00:00', 1);
    }

    /**
     * @param string $zone Expected @@session.time_zone
     * @param int $sameInstant 1 when NOW() equals UTC_TIMESTAMP()
     * @throws DatabaseException When the probe query fails
     */
    private function assertSessionZone(string $zone, int $sameInstant): void
    {
        Database::sql(self::SESSION_CLOCK_SQL);
        $row = Database::row();
        $this->assertNotNull($row);
        $this->assertSame($zone, $row['session_zone']);
        $this->assertSame($sameInstant, (int) $row['same_instant']);
    }

    /**
     * @return string @@character_set_connection of the current link
     * @throws DatabaseException When the probe query fails
     */
    private function connectionCharset(): string
    {
        Database::sql('SELECT @@character_set_connection AS charset');

        return (string) Database::field('charset');
    }

    /**
     * @return string @@global.time_zone, read and never written
     * @throws DatabaseException When the probe query fails
     */
    private function globalZone(): string
    {
        Database::sql('SELECT @@global.time_zone AS global_zone');

        return (string) Database::field('global_zone');
    }

    /**
     * @return int Thread id of the connection Database is currently using
     * @throws DatabaseException When the probe query fails
     */
    private function currentConnectionId(): int
    {
        Database::sql('SELECT CONNECTION_ID() AS id');
        $row = Database::row();
        $this->assertNotNull($row);

        return (int) $row['id'];
    }

    /**
     * Kills a server thread from a connection of its own, so the layer under test sees the
     * loss exactly as it would see a server-side timeout.
     *
     * @param int $connectionId Thread id to kill
     * @throws EnvException When DB env variables are missing or invalid
     */
    private function killConnection(int $connectionId): void
    {
        $killer = new mysqli(
            Hilos::$env[EnvConstants::DB_HOST]->string(),
            Hilos::$env[EnvConstants::DB_USERNAME]->string(),
            Hilos::$env[EnvConstants::DB_PASSWORD]->string(),
            Hilos::$env[EnvConstants::DB_DATABASE]->string(),
            Hilos::$env[EnvConstants::DB_PORT]->int(),
        );
        $killer->query('KILL ' . $connectionId);
        $killer->close();
    }

    private function removePreludeRoot(string $root): void
    {
        $testEnv = $root . '/tests/.env';
        if (is_file($testEnv)) {
            unlink($testEnv);
        }
        if (is_dir($root . '/tests')) {
            rmdir($root . '/tests');
        }
        if (is_file($root . '/.env')) {
            unlink($root . '/.env');
        }
        if (is_dir($root)) {
            rmdir($root);
        }
    }
}
