<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Database\Database;
use Hilos\Database\Migration;

/**
 * Migration Status Command.
 *
 * CLI command that displays current database migration status.
 * Shows applied, pending and failed migrations.
 */
class MigrationStatusCommand implements CommandInterface
{
    /** @var int Width of the horizontal rule that frames the migration list, in characters */
    private const int TABLE_RULE_WIDTH = 50;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (e.g. db:migration:status)
     */
    public function getName(): string
    {
        return CliCommands::MIGRATION_STATUS;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Show current migration status';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: db:migration:status

Description:
  Display the current status of database migrations.

Usage:
  php cli.php db:migration:status [options]

Options:
  --db-index=<N>     Database connection index (default: 0)

Examples:
  php cli.php db:migration:status
  php cli.php db:migration:status --db-index=0
  composer run db:migration:status
HELP;
    }

    /**
     * Executes migration status command and outputs status to console.
     *
     * @param array<string, mixed> $options Parsed options (e.g. db-index)
     * @param list<string> $args Positional arguments (unused)
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        echo "\n=== Migration Status ===\n\n";

        // Parse arguments from options
        $dbIndex = isset($options['db-index']) ? (int)$options['db-index'] : 0;

        // Set database connection index if specified
        if ($dbIndex !== 0) {
            Database::useConnection($dbIndex);
            echo "Using database connection index: {$dbIndex}\n\n";
        }

        // Initialize migration system
        Migration::initialize();

        // Get status
        $status = Migration::getStatus();
        
        echo "Current version:    {$status['current_index']}\n";
        echo "Latest available:   {$status['latest_available']}\n";
        echo "Pending migrations: {$status['pending_count']}\n";
        
        if (!empty($status['failed_migrations'])) {
            echo "Failed migrations:  " . implode(', ', $status['failed_migrations']) . " ⚠\n";
        }
        
        echo "\nMigration list:\n";
        echo str_repeat('-', self::TABLE_RULE_WIDTH) . "\n";
        
        foreach ($status['available_migrations'] as $index) {
            $isCurrent = $index === $status['current_index'];
            $isApplied = $index <= $status['current_index'];
            $isFailed = in_array($index, $status['failed_migrations'], true);
            
            if ($isFailed) {
                $marker = '✗';
                $statusText = 'FAILED';
            } elseif ($isApplied) {
                $marker = '✓';
                $statusText = 'Applied';
            } else {
                $marker = '○';
                $statusText = 'Pending';
            }
            
            $current = $isCurrent ? ' (current)' : '';
            
            printf("  %s Migration %03d - %-10s%s\n", $marker, $index, $statusText, $current);
        }
        
        echo str_repeat('-', self::TABLE_RULE_WIDTH) . "\n";
        
        // Overall status
        echo "\nOverall status: ";
        if (!empty($status['failed_migrations'])) {
            echo "⚠ HAS FAILURES\n";
            echo "\nAction required:\n";
            echo "  - Fix failed migrations manually\n";
            echo "  - Retry with: php cli.php db:migration:retry <version>\n";
        } elseif ($status['pending_count'] > 0) {
            echo "○ PENDING\n";
            echo "\nAction:\n";
            echo "  php cli.php db:migration:up\n";
        } else {
            echo "✓ UP TO DATE\n";
        }
        
        echo "\n";
        return ExitCode::SUCCESS;
    }

}

