<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;

/**
 * Migration Up Command.
 *
 * Applies pending database migrations to advance schema version.
 */
class MigrationUpCommand implements CommandInterface
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (e.g. db:migration:up)
     */
    public function getName(): string
    {
        return CliCommands::MIGRATION_UP;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Apply pending database migrations';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: db:migration:up

Description:
  Apply all pending database migrations.

Usage:
  php cli.php db:migration:up [options]

Options:
  --db-index=<N>     Database connection index (default: 0)
  --to=<version>     Migrate up to specific version (optional)
  --force            Force migration even if there are failures (use with caution)

Examples:
  php cli.php db:migration:up
  php cli.php db:migration:up --to=5
  php cli.php db:migration:up --db-index=0
  php cli.php db:migration:up --force
  composer run db:migration:up

The migration system will:
  1. Initialize migration table if not exists
  2. Check for failed migrations (will stop if found)
  3. Apply all pending migrations in order
  4. Mark each migration as successful or failed

If a migration fails:
  - It will be marked as failed in the database
  - Subsequent runs will refuse to continue
  - You must fix the issue manually and retry
HELP;
    }

    /**
     * Executes database migration process.
     *
     * Initializes migration system, applies pending migrations, handles failed migrations.
     *
     * @param array<string, mixed> $options Parsed options (db-index, to, force)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws DatabaseException If database connection or migration fails
     */
    public function execute(array $options, array $args): int
    {
        echo "\n=== Database Migration UP ===\n\n";

        // Parse arguments from options
        $dbIndex = isset($options['db-index']) ? (int)$options['db-index'] : 0;
        $targetVersion = $options['to'] ?? null;
        $force = isset($options['force']);

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
        echo "  Latest available: {$status['latest_available']}\n";
        echo "  Pending migrations: {$status['pending_count']}\n";

        // Check for failed migrations
        if (!empty($status['failed_migrations']) && !$force) {
            echo "\n✗ ERROR: Found failed migrations: " . implode(', ', $status['failed_migrations']) . "\n";
            echo "\nThese migrations failed previously and must be fixed manually.\n";
            echo "To retry a failed migration after fixing:\n";
            echo "  php cli.php db:migration:retry <version>\n";
            echo "\nOr use --force to ignore failures (not recommended):\n";
            echo "  php cli.php db:migration:up --force\n\n";
            return ExitCode::ERROR;
        }

        if ($status['pending_count'] === 0) {
            echo "\n✓ All migrations are up to date!\n\n";
            return ExitCode::SUCCESS;
        }

        // Show migrations to apply
        echo "\nMigrations to apply:\n";
        foreach ($status['available_migrations'] as $index) {
            if ($index > $status['current_index']) {
                $willApply = ($targetVersion === null || $index <= (int)$targetVersion);
                $marker = $willApply ? '→' : '○';
                echo "  {$marker} Migration {$index}\n";
            }
        }
        echo "\n";

        // Apply migrations
        echo "Applying migrations...\n\n";
        
        $target = $targetVersion !== null ? (int)$targetVersion : null;
        $applied = Migration::migrateUp($target);

        if ($applied > 0) {
            echo "\n✓ Successfully applied {$applied} migration(s)\n\n";
        } else {
            echo "\n○ No migrations were applied\n\n";
        }
        return ExitCode::SUCCESS;
    }
}

