<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\BackupConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * BackupTestPruneCommand - force a backup retention prune through the live agent (test-only).
 *
 * Retention normally rotates only after a successful scheduled create. To assert rotation on
 * demand, this drives the running {@see BackupAgent} over the command
 * channel ({@see BackupConstants::PRUNE_COMMAND}): the agent rescans storage and applies the
 * retention policy synchronously, then replies with the number of backups pruned. Going through
 * the agent (rather than scanning the disk from the CLI) keeps the runtime index consistent
 * with storage truth. Test-only ({@see TestOnlyCommand} via {@see AbstractCommandChannelTestCommand}).
 */
class BackupTestPruneCommand extends AbstractCommandChannelTestCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:backup:prune)
     */
    public function getName(): string
    {
        return CliCommands::BACKUP_TEST_PRUNE;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Force a backup retention prune through the live agent (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:backup:prune

Description:
  Force a retention rotation now through the running backup agent. The agent rescans
  storage and applies the retention policy synchronously, then reports how many backups
  were pruned. Drives rotation in tests without a scheduled create. Refuses on a
  production-like environment.

Usage:
  php cli.php test:backup:prune
HELP;
    }

    /**
     * Sends the prune command to the agent and prints the pruned count.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        try {
            $reply = $this->sendCommand(BackupConstants::PRUNE_COMMAND, []);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($reply === null) {
            echo "No reply from daemon (is it running?)\n";
            return ExitCode::ERROR;
        }

        if (!$reply->isOk()) {
            $detail = (string)($reply->payload[CommandConstants::FIELD_MESSAGE] ?? 'unknown error');
            echo "Command failed: {$detail}\n";
            return ExitCode::ERROR;
        }

        $pruned = (int)($reply->payload[BackupConstants::FIELD_PRUNED_COUNT] ?? 0);
        echo "Pruned {$pruned} backup(s)\n";

        return ExitCode::SUCCESS;
    }
}
