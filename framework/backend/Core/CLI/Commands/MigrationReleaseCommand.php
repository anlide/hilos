<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Migration;
use Hilos\Database\MigrationClaim;
use Hilos\Database\MigrationClaimRow;

/**
 * Migration Release Command.
 *
 * Removes the schema rollout claim a dead holder left behind (HIL-1228): the database keeps the
 * row after its holder died, and every node starting on that database waits for it without a
 * deadline. The operator names the holder the waiting line printed, so a claim that has changed
 * hands in the meantime is never the one removed.
 */
class MigrationReleaseCommand implements CommandInterface
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (db:migration:release)
     */
    public function getName(): string
    {
        return CliCommands::MIGRATION_RELEASE;
    }

    /**
     * Declares the departure: this write happens in the CLI process, and only while the daemon is down.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::cliOfflineWrite(
            'unblocks the schema rollout the daemon boots on, run in the container of a node that waits for it',
        );
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Release a schema rollout claim a dead holder left';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: db:migration:release

Description:
  Release the schema rollout claim a dead holder left in the database.
  Nodes starting on one database roll the schema out one at a time under
  that claim; a holder killed mid-rollout leaves it behind, and the rest
  wait for it without a deadline, naming the holder in their log.

Usage:
  php cli.php db:migration:release <holder> [options]

Arguments:
  <holder>           Holder name, as the waiting line or db:migration:status prints it

Options:
  --db-index=<N>     Database connection index (default: 0)

Examples:
  php cli.php db:migration:release node-3
  php cli.php db:migration:release node-3:4242 --db-index=0

The claim is removed only while the named holder still has it; a claim
held by anyone else is left in place and named in the error.
HELP;
    }

    /**
     * Releases the claim the named holder has.
     *
     * @param array<string, mixed> $options Parsed options (e.g. db-index)
     * @param list<string> $args Positional args (holder name required)
     * @return int Exit code (0 when released or when nobody holds the claim)
     * @throws DatabaseException If the connection cannot be selected or the claim cannot be read or released
     */
    public function execute(array $options, array $args): int
    {
        echo "\n=== Release Schema Rollout Claim ===\n\n";

        $dbIndex = isset($options['db-index']) ? (int)$options['db-index'] : 0;
        $holder = $args[0] ?? null;

        if ($holder === null || trim($holder) === '') {
            echo "✗ ERROR: Holder name is required\n";
            echo "\nUsage: php cli.php db:migration:release <holder>\n";
            echo "Example: php cli.php db:migration:release node-3\n\n";
            return ExitCode::ERROR;
        }

        if ($dbIndex !== 0) {
            Database::useConnection($dbIndex);
            echo "Using database connection index: {$dbIndex}\n\n";
        }

        Migration::initialize();

        $claim = MigrationClaim::current();
        if ($claim !== null && $claim->holder === $holder && MigrationClaim::releaseHeldBy($holder)) {
            echo "✓ Released the schema rollout claim held by {$holder} since {$claim->claimedAt}\n\n";
            return ExitCode::SUCCESS;
        }

        // Read again when the delete found nothing: the claim may have changed hands in between.
        return self::reportNotReleased($claim === null ? null : MigrationClaim::current(), $holder);
    }

    /**
     * @param ?MigrationClaimRow $claim The claim as it stands; null when nobody holds it
     * @param string $holder Holder the operator named
     * @return int Exit code: SUCCESS when nobody holds the claim, ERROR when someone else does
     */
    private static function reportNotReleased(?MigrationClaimRow $claim, string $holder): int
    {
        if ($claim === null) {
            echo "○ No schema rollout claim is held\n\n";
            return ExitCode::SUCCESS;
        }

        echo "✗ The schema rollout claim is held by {$claim->holder} since {$claim->claimedAt}, not by {$holder}\n\n";
        return ExitCode::ERROR;
    }
}
