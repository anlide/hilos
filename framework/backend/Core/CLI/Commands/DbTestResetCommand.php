<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\Seed;
use Hilos\Utils\Env;

/**
 * DB Test Reset Command.
 *
 * Resets test database: DROP DATABASE, CREATE DATABASE, migrate up, apply seeds.
 * Requires Migration and Seed paths to be configured in CLI bootstrap.
 * Uses DB_* and APP_ENV from environment. Seeds are allowed when APP_ENV=test.
 */
class DbTestResetCommand implements CommandInterface
{
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
     * @throws DatabaseException If migration or seed fails
     */
    public function execute(array $options, array $args): int
    {
        $host = Env::get('DB_HOST', 'localhost');
        $port = (int) Env::get('DB_PORT', '3306');
        $user = Env::get('DB_USERNAME', 'root');
        $pass = Env::get('DB_PASSWORD', '');
        $database = Env::get('DB_DATABASE', 'hilos_demo_test');

        echo "\n=== Test DB Reset ===\n\n";

        // Connect without database to run DROP/CREATE
        $conn = @mysqli_connect($host, $user, $pass, '', $port);
        if ($conn === false) {
            fwrite(STDERR, "Cannot connect to MySQL: " . mysqli_connect_error() . "\n");
            return ExitCode::ERROR;
        }

        $dbEscaped = '`' . mysqli_real_escape_string($conn, $database) . '`';
        mysqli_query($conn, "DROP DATABASE IF EXISTS {$dbEscaped}");
        mysqli_query($conn, "CREATE DATABASE {$dbEscaped} CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
        mysqli_close($conn);

        echo "Database reset: {$database}\n";

        // Configure and connect base Database for migrations and seeds
        Database::configure(
            index: 0,
            host: $host,
            user: $user,
            password: $pass,
            database: $database,
            port: $port,
            charset: 'utf8mb4',
        );
        Database::connect(0);
        Database::sql("SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci");

        try {
            Migration::initialize();
            $applied = Migration::migrateUp();
            echo "Migrations applied: {$applied}\n";

            $seedCount = Seed::apply();
            echo "Seeds applied: {$seedCount}\n";
        } catch (DatabaseException $e) {
            echo "✗ " . $e->getMessage() . "\n\n";
            return ExitCode::ERROR;
        }

        echo "\n✓ Done.\n\n";
        return ExitCode::SUCCESS;
    }
}
