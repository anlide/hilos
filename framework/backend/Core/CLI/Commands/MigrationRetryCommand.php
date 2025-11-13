<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Database\Database;
use Hilos\Database\Migration;
use Hilos\Exception\DatabaseException;

/**
 * Migration Retry Command
 * Retries a failed migration
 */
class MigrationRetryCommand implements CommandInterface
{
    public function getName(): string
    {
        return CliCommands::MIGRATION_RETRY;
    }

    public function getDescription(): string
    {
        return 'Retry a failed migration';
    }

    public function getHelp(): string
    {
        return <<<HELP
Command: migration:retry

Description:
  Retry a migration that previously failed.

Usage:
  php cli.php migration:retry <version> [options]

Arguments:
  <version>          Version number of the failed migration to retry

Options:
  --db-index=<N>     Database connection index (default: 0)

Examples:
  php cli.php migration:retry 5
  php cli.php migration:retry 5 --db-index=0
  composer run migration:retry

This command:
  1. Removes the failed migration record
  2. Re-applies the migration
  3. Marks it as successful or failed again
HELP;
    }

    public function execute(array $options, array $args): int
    {
        echo "\n=== Retry Failed Migration ===\n\n";

        // Parse arguments from options
        $dbIndex = isset($options['db-index']) ? (int)$options['db-index'] : 0;
        
        // Get migration version from positional args
        $version = !empty($args) && is_numeric($args[0]) ? (int)$args[0] : null;

        if ($version === null) {
            echo "✗ ERROR: Migration version is required\n";
            echo "\nUsage: php cli.php migration:retry <version>\n";
            echo "Example: php cli.php migration:retry 5\n\n";
            return ExitCode::ERROR;
        }

        // Set database connection index if specified
        if ($dbIndex !== 0) {
            try {
                Database::useConnection($dbIndex);
                echo "Using database connection index: {$dbIndex}\n\n";
            } catch (\Throwable $e) {
                echo "✗ Failed to switch to database connection {$dbIndex}: {$e->getMessage()}\n\n";
                return ExitCode::ERROR;
            }
        }

        try{
            // Initialize migration system
            echo "Initializing migration system...\n";
            Migration::initialize();
            echo "✓ Migration system initialized\n\n";

            // Get status
            $status = Migration::getStatus();
            
            // Check if migration is actually failed
            if (!in_array($version, $status['failed_migrations'], true)) {
                echo "✗ ERROR: Migration {$version} is not marked as failed\n";
                echo "\nFailed migrations: " . (empty($status['failed_migrations']) ? 'none' : implode(', ', $status['failed_migrations'])) . "\n\n";
                return ExitCode::ERROR;
            }

            echo "Retrying migration {$version}...\n";
            echo "This will remove the failed record and re-apply the migration.\n\n";

            // Retry the migration
            Migration::retryFailed($version);

            echo "\n✓ Migration {$version} successfully applied\n\n";
            return ExitCode::SUCCESS;

        } catch (DatabaseException $e) {
            echo "\n✗ Migration retry failed!\n";
            echo "Error: {$e->getMessage()}\n";
            echo "MySQL Error Code: {$e->getMysqlErrorCode()}\n";
            echo "MySQL Error: {$e->getMysqlErrorMessage()}\n";
            if ($e->getQuery()) {
                echo "Query: {$e->getQuery()}\n";
            }
            echo "\nThe migration is still marked as failed.\n";
            echo "Fix the issue and try again.\n\n";
            return ExitCode::ERROR;
        } catch (\Throwable $e) {
            echo "\n✗ Unexpected error!\n";
            echo "Error: {$e->getMessage()}\n";
            echo "File: {$e->getFile()}:{$e->getLine()}\n\n";
            return ExitCode::ERROR;
        }
    }

}

