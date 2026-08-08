<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Database\Migration;
use Hilos\Database\MysqlExceptionMapper;
use Hilos\Database\Seed;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use mysqli_sql_exception;

/**
 * DB Test Reset Command.
 *
 * Resets test database: DROP DATABASE, CREATE DATABASE, migrate up, apply seeds.
 * Requires Migration and Seed paths to be configured in CLI bootstrap.
 * Uses DB_* and APP_ENV from environment. Seeds are allowed when APP_ENV=test.
 */
class DbTestResetCommand implements CommandInterface
{
    private const string DEFAULT_TEST_DATABASE = 'hilos_demo_test';

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (e.g. db:test:reset)
     */
    public function getName(): string
    {
        return CliCommands::DB_TEST_RESET;
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
Command: db:test:reset

Description:
  Drops the database, recreates it, runs migrations, and applies all seeds.
  Intended for test environments. Seeds are blocked when APP_ENV is PROD or STAGING.

Usage:
  php cli.php db:test:reset

Examples:
  php cli.php db:test:reset
  composer run test:db-reset
HELP;
    }

    /**
     * Resets test database: drops, recreates, migrates and seeds.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws EnvException When DB env variables are missing or invalid
     * @throws DatabaseConnectionException When database connect or SET NAMES fails
     * @throws CantConnectToMysqlServerException When MySQL is unreachable
     * @throws DatabaseRuntimeException When SET NAMES query fails
     * @throws DatabaseException When migration or seed execution fails
     */
    public function execute(array $options, array $args): int
    {
        $host = Hilos::$env[EnvConstants::DB_HOST];
        $port = Hilos::$env->int(EnvConstants::DB_PORT);
        $user = Hilos::$env[EnvConstants::DB_USERNAME];
        $pass = Hilos::$env[EnvConstants::DB_PASSWORD];
        $database = Hilos::$env[EnvConstants::DB_DATABASE];
        if ($database === '') {
            $database = self::DEFAULT_TEST_DATABASE;
        }

        echo "\n=== Test DB Reset ===\n\n";

        // Connect without database to run DROP/CREATE
        try {
            $conn = mysqli_connect($host, $user, $pass, '', $port);
        } catch (mysqli_sql_exception $e) {
            MysqlExceptionMapper::connectionException($e->getCode(), $e->getMessage());
        }

        $dbEscaped = '`' . mysqli_real_escape_string($conn, $database) . '`';
        mysqli_query($conn, "DROP DATABASE IF EXISTS {$dbEscaped}");
        mysqli_query($conn, "CREATE DATABASE {$dbEscaped} " . DatabaseConnectionDefaults::createDatabaseCharsetClause());
        mysqli_close($conn);

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
        Database::sql(DatabaseConnectionDefaults::setNamesSql());

        Migration::initialize();
        $applied = Migration::migrateUp();
        echo "Migrations applied: {$applied}\n";

        $seedCount = Seed::apply();
        echo "Seeds applied: {$seedCount}\n";

        echo "\n✓ Done.\n\n";
        return ExitCode::SUCCESS;
    }
}
