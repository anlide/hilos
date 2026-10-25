<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\ChangeLog\ChangeLogPartitions;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseSql;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\Migration;
use Hilos\Database\MysqlExceptionMapper;
use Hilos\Database\Seed;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use mysqli_sql_exception;

/**
 * DB Test Reset Command.
 *
 * Resets test database: DROP DATABASE, CREATE DATABASE, migrate up, apply seeds.
 * Requires Migration and Seed paths to be configured in CLI bootstrap.
 * Uses DB_* and APP_ENV from environment. Seeds are allowed when APP_ENV=test.
 * CHANGE_LOG recreates the derived journal before primary migrations and prepares
 * its partitions after seeds; projects without the feature reset only primary.
 *
 * Database-free by contract: it opens its own server-level connection, because the
 * database named by DB_DATABASE is the one it drops and recreates — and on a fresh volume
 * does not exist yet.
 *
 * Test-only by contract too, and that is why it extends {@see TestOnlyCommand}: dropping the
 * database is the most destructive thing this CLI can do, and until HIL-566 it was the one
 * such command with no environment guard at all - `APP_ENV` only reached it through the seed
 * step, long after the DROP had already run. It stays CLI-only and never reaches the wire.
 */
class DbTestResetCommand extends TestOnlyCommand implements DatabaseFreeCommand
{
    private const string DEFAULT_TEST_DATABASE = 'hilos_demo_test';

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (e.g. test:db:reset)
     */
    public function getName(): string
    {
        return CliCommands::DB_TEST_RESET;
    }

    /**
     * Declares the departure: this write happens in the CLI process, and only while the daemon is down.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite(
            'drops and recreates the very database a running daemon would be holding its connections to',
        );
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Reset test database (DROP, migrate, seed)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:db:reset

Description:
  Drops the database, recreates it, runs migrations, and applies all seeds.
  Intended for test environments. Seeds are blocked when APP_ENV is PROD or STAGING.

Usage:
  php cli.php test:db:reset

Examples:
  php cli.php test:db:reset
  composer run test:db-reset
HELP;
    }

    /**
     * Resets the primary and optional journal through the project's primary migration track.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws EnvException When DB env variables are missing or invalid, or CLUSTER_NODE_ID for the rollout claim
     * @throws DatabaseConnectionException When database connect, session time zone, or SET NAMES fails
     * @throws CantConnectToMysqlServerException When MySQL is unreachable
     * @throws DatabaseRuntimeException When SET NAMES query fails
     * @throws DatabaseException When migration or seed execution fails
     * @throws HilosException When the migration receipt cannot be opened or closed
     */
    protected function run(array $options, array $args): int
    {
        $host = Hilos::$env[EnvConstants::DB_HOST]->string();
        $port = Hilos::$env[EnvConstants::DB_PORT]->int();
        $user = Hilos::$env[EnvConstants::DB_USERNAME]->string();
        $pass = Hilos::$env[EnvConstants::DB_PASSWORD]->string();
        $database = Hilos::$env[EnvConstants::DB_DATABASE]->string();
        if ($database === '') {
            $database = self::DEFAULT_TEST_DATABASE;
        }
        $journalDatabase = Hilos::hasFeature(HilosFeature::CHANGE_LOG) ? ChangeLogDatabase::name($database) : null;
        $journalIdentifier = $journalDatabase !== null ? ChangeLogDatabase::identifier($journalDatabase) : null;

        echo "\n=== Test DB Reset ===\n\n";

        // Connect without database to run DROP/CREATE
        try {
            $conn = mysqli_connect($host, $user, $pass, '', $port);
        } catch (mysqli_sql_exception $e) {
            MysqlExceptionMapper::connectionException($e->getCode(), $e->getMessage());
        }

        try {
            mysqli_query($conn, DatabaseSql::SESSION_TIME_ZONE_UTC);
        } catch (mysqli_sql_exception $failure) {
            mysqli_close($conn);
            MysqlExceptionMapper::connectionException($failure->getCode(), $failure->getMessage());
        }

        try {
            $dbEscaped = '`' . mysqli_real_escape_string($conn, $database) . '`';
            mysqli_query($conn, "DROP DATABASE IF EXISTS {$dbEscaped}");
            mysqli_query($conn, "CREATE DATABASE {$dbEscaped} " . DatabaseConnectionDefaults::createDatabaseCharsetClause());
            if ($journalIdentifier !== null) {
                mysqli_query($conn, 'DROP DATABASE IF EXISTS ' . $journalIdentifier);
                mysqli_query($conn, 'CREATE DATABASE ' . $journalIdentifier . ' ' . DatabaseConnectionDefaults::createDatabaseCharsetClause());
            }
        } catch (mysqli_sql_exception $failure) {
            throw new DatabaseException("Cannot reset test database {$database}: " . $failure->getMessage(), 0, $failure);
        } finally {
            mysqli_close($conn);
        }

        echo "Database reset: {$database}\n";

        // Configure and connect base Database for migrations and seeds
        Database::configure(
            index: DatabaseConnectionDefaults::PRIMARY_INDEX,
            host: $host,
            user: $user,
            password: $pass,
            database: $database,
            port: $port,
            charset: DatabaseConnectionDefaults::CHARSET,
        );
        Database::connect(DatabaseConnectionDefaults::PRIMARY_INDEX);
        Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);

        Migration::initialize();
        $applied = Migration::migrateUp();
        echo "Migrations applied: {$applied}\n";

        $seedCount = Seed::apply();
        echo "Seeds applied: {$seedCount}\n";

        if ($journalDatabase !== null) {
            Database::configure(
                index: ChangeLogDatabase::CONNECTION_INDEX,
                host: $host,
                user: $user,
                password: $pass,
                database: $journalDatabase,
                port: $port,
                charset: DatabaseConnectionDefaults::CHARSET,
            );
            Database::connect(ChangeLogDatabase::CONNECTION_INDEX);
            ChangeLogPartitions::ensure();
            echo "Change log database reset: {$journalDatabase}\n";
        }

        echo "\n✓ Done.\n\n";
        return ExitCode::SUCCESS;
    }
}
