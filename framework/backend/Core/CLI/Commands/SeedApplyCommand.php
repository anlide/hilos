<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Seed;

/**
 * Seed Apply Command.
 *
 * Applies database seeds from configured seed directory.
 * Requires Seed::setSeedPath() to be called before execution (typically in CLI bootstrap).
 */
class SeedApplyCommand implements CommandInterface
{
    /**
     * Get command name.
     *
     * @return string Command identifier (db:seed:apply)
     */
    public function getName(): string
    {
        return CliCommands::SEED_APPLY;
    }

    /**
     * Declares the departure: this write happens in the CLI process, and only while the daemon is down.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite(
            'seeds the schema the daemon boots on, from the same process that migrated it and before the daemon starts',
        );
    }

    /**
     * Get short command description.
     *
     * @return string Description for help output
     */
    public function getDescription(): string
    {
        return 'Apply database seeds';
    }

    /**
     * Get detailed help text.
     *
     * @return string Multi-line help including usage, options, examples
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: db:seed:apply

Description:
  Apply a specific seed file. Without <seed> argument, lists available seeds only.
  Seeds are idempotent and safe to run multiple times.
  Disabled when APP_ENV is PROD or STAGING (see AppEnv constants).

Usage:
  php cli.php db:seed:apply <seed> [options]

Arguments:
  <seed>   Seed identifier: numeric prefix (001) or basename (001_some_name)
           Omit to list available seeds without executing

Options:
  --db-index=<N>     Database connection index (default: 0)

Examples:
  php cli.php db:seed:apply
  php cli.php db:seed:apply 001
  composer run db:seed:apply -- 001
HELP;
    }

    /**
     * Execute seed apply command.
     *
     * @param array<string, mixed> $options Parsed CLI options (e.g. db-index)
     * @param list<string> $args Positional arguments (seed identifier)
     * @return int Exit code (0 = success)
     * @throws DatabaseException If database connection or seed execution fails
     */
    public function execute(array $options, array $args): int
    {
        echo "\n=== Database Seed ===\n\n";

        $dbIndex = isset($options['db-index']) ? (int)$options['db-index'] : 0;
        if ($dbIndex !== 0) {
            Database::useConnection($dbIndex);
            echo "Using database connection index: {$dbIndex}\n\n";
        }

        $seeds = Seed::getAvailableSeeds();

        if ($seeds === []) {
            $path = Seed::getSeedPath();
            if ($path === null) {
                echo "○ Seed path not configured. Call Seed::setSeedPath() in CLI bootstrap.\n\n";
            } else {
                $unmatched = Seed::getUnmatchedSqlBasenames();
                if ($unmatched !== []) {
                    echo "○ No seed files match pattern NNN_name.sql (numeric prefix required).\n";
                    echo "  Found: " . implode(', ', $unmatched) . "\n\n";
                } else {
                    echo "○ No .sql files in seed directory.\n\n";
                }
            }
            return ExitCode::SUCCESS;
        }

        $seedArg = $args[0] ?? null;

        if ($seedArg === null || $seedArg === '') {
            echo "Available seeds:\n";
            foreach ($seeds as $file) {
                echo "  • " . basename($file, '.sql') . "\n";
            }
            $first = basename($seeds[0], '.sql');
            $firstShort = preg_match('/^\d+/', $first, $m) ? $m[0] : $first;
            echo "\nTo apply a seed, specify it explicitly. Example:\n";
            echo "  composer run db:seed:apply -- {$firstShort}\n\n";
            return ExitCode::SUCCESS;
        }

        try {
            Seed::applyOne($seedArg);
        } catch (DatabaseException $e) {
            echo "✗ " . $e->getMessage() . "\n\n";
            return ExitCode::ERROR;
        }

        echo "✓ Seed applied successfully\n\n";
        return ExitCode::SUCCESS;
    }
}
