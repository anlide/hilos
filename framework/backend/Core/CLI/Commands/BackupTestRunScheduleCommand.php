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
 * BackupTestRunScheduleCommand - force a scheduled backup through the live agent (test-only).
 *
 * A scheduled backup normally fires only when its cron expression matches wall-clock time,
 * and scheduling carries no stored timestamp to age (unlike retention's sidecar createdAt).
 * To assert scheduling on demand, this drives the running
 * {@see BackupAgent} over the command channel
 * ({@see BackupConstants::RUN_SCHEDULE_COMMAND}): the agent resolves the named schedule entry
 * to a scope and starts the backup through its single guarded create path, replying immediately
 * with the new backup id and scope (the capture then runs asynchronously in the child; a test
 * waits on runtime state for completion). A busy single-flight lock or an unknown name replies
 * with an error. Test-only ({@see TestOnlyCommand} via {@see AbstractCommandChannelTestCommand}).
 */
class BackupTestRunScheduleCommand extends AbstractCommandChannelTestCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:backup:run-schedule)
     */
    public function getName(): string
    {
        return CliCommands::BACKUP_TEST_RUN_SCHEDULE;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Force a scheduled backup through the live agent (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:backup:run-schedule [name]

Description:
  Force a scheduled backup now through the running backup agent. The agent resolves the
  named schedule entry to a scope and starts the backup, replying immediately with the new
  backup id and scope; the capture runs asynchronously. Omitting [name] uses the default
  daily-full entry. Refuses on a production-like environment.

Arguments:
  [name]   Schedule entry name to run (defaults to the fallback daily-full entry)

Usage:
  php cli.php test:backup:run-schedule
  php cli.php test:backup:run-schedule nightly-schema
HELP;
    }

    /**
     * Sends the run-schedule command to the agent and prints the started backup id and scope.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args: [0] optional schedule entry name
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the operator's command line, where naming no entry runs them all
        $name = $args[0] ?? '';
        $payload = $name !== '' ? [BackupConstants::FIELD_SCHEDULE_NAME => $name] : [];

        try {
            $reply = $this->sendCommand(BackupConstants::RUN_SCHEDULE_COMMAND, $payload);
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

        // Both fields are written on every successful reply by BackupAgent; a reply missing one
        // is an incomplete answer, and "Started scheduled backup  (scope=)" would read as success.
        $id = $reply->payload[BackupConstants::FIELD_BACKUP_ID] ?? null;
        $scope = $reply->payload[BackupConstants::FIELD_SCOPE] ?? null;
        if (!is_string($id) || !is_string($scope)) {
            echo "Command failed: the reply names no started backup\n";

            return ExitCode::ERROR;
        }

        echo "Started scheduled backup {$id} (scope={$scope})\n";

        return ExitCode::SUCCESS;
    }
}
