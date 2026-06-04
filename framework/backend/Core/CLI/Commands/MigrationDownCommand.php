<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Database\Database;
use Hilos\Database\Migration;

/**
 * Migration Down Command.
 *
 * Rolls back database migrations to specified target version.
 */
class MigrationDownCommand implements CommandInterface
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (e.g. db:migration:down)
     */
    public function getName(): string
    {
        return CliCommands::MIGRATION_DOWN;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Rollback database migrations';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: db:migration:down

Description:
  Rollback migrations to a specific version.

Usage:
  php cli.php db:migration:down <version> [options]

Arguments:
  <version>          Target version to rollback to (required)

Options:
  --db-index=<N>     Database connection index (default: 0)
  --force            Force rollback even if there are failures (use with caution)

Examples:
  php cli.php db:migration:down 1
  php cli.php db:migration:down 0
  php cli.php db:migration:down 1 --force
  php cli.php db:migration:down 1 --db-index=0
  composer run db:migration:down

Warning:
  Rollback operations can result in data loss!
  Always backup your database before rolling back.
HELP;
    }

    /**
     * Executes migration down command and rolls back to target version.
     *
     * @param array<string, mixed> $options Parsed options (e.g. db-index, force)
     * @param list<string> $args Positional args (target version required)
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        echo "\n=== Database Migration DOWN ===\n\n";

        // Parse arguments from options
        $dbIndex = isset($options['db-index']) ? (int)$options['db-index'] : 0;
        $force = isset($options['force']);
        
        // Get target version from positional args
        $targetVersion = !empty($args) && is_numeric($args[0]) ? (int)$args[0] : null;

        if ($targetVersion === null) {
            echo "✗ ERROR: Target version is required\n";
            echo "\nUsage: php cli.php db:migration:down <version>\n";
            echo "Example: php cli.php db:migration:down 1\n\n";
            return ExitCode::ERROR;
        }

        // Set database connection index if specified
        if ($dbIndex !== 0) {
            Database::useConnection($dbIndex);
            echo "Using database connection index: {$dbIndex}\n\n";
        }

        // Initialize migration system
        echo "Initializing migration system...\n";
        Migration::initialize();
        echo "✓ Migration system initialized\n\n";

        // Get current status
        $status = Migration::getStatus();
        
        echo "Current status:\n";
        echo "  Current version: {$status['current_index']}\n";
        echo "  Target version: {$targetVersion}\n";

        if ($status['current_index'] <= $targetVersion) {
            echo "\n○ Already at version {$targetVersion} or below\n";
            echo "No rollback needed.\n\n";
            return ExitCode::SUCCESS;
        }

        // Check for failed migrations
        if (!empty($status['failed_migrations']) && !$force) {
            echo "\n⚠ WARNING: Found failed migrations: " . implode(', ', $status['failed_migrations']) . "\n";
            echo "These migrations failed previously.\n";
            echo "Use --force to proceed anyway (not recommended).\n\n";
            return ExitCode::ERROR;
        }

        // Calculate migrations to rollback
        $toRollback = [];
        foreach ($status['available_migrations'] as $index) {
            if ($index > $targetVersion && $index <= $status['current_index']) {
                $toRollback[] = $index;
            }
        }
        rsort($toRollback);

        echo "\nMigrations to rollback:\n";
        foreach ($toRollback as $index) {
            echo "  ← Migration {$index}\n";
        }
        echo "\n";

        // Warning
        echo "⚠ WARNING: Rollback may result in DATA LOSS!\n";
        echo "Make sure you have a database backup.\n\n";

        // Rollback migrations
        echo "Rolling back migrations...\n\n";
        
        $rolledBack = Migration::migrateDown($targetVersion);

        if ($rolledBack > 0) {
            echo "\n✓ Successfully rolled back {$rolledBack} migration(s)\n\n";
        } else {
            echo "\n○ No migrations were rolled back\n\n";
        }
        return ExitCode::SUCCESS;
    }

}

