<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\BackupConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * BackupTestShipCommand - force a shipping pass through the live agent (test-only).
 *
 * Copies off the machine normally leave on their own, one per tick, which is fine for an
 * installation and useless for a test that has to assert the archive landed. This drives the
 * running {@see BackupAgent} over the command channel
 * ({@see BackupConstants::SHIP_COMMAND}): the agent runs the whole queue synchronously and
 * replies with what it moved. Going through the agent rather than shelling out from the CLI is
 * what keeps the runtime index and the sidecars agreeing about which backups are copied.
 * Test-only ({@see TestOnlyCommand} via {@see AbstractCommandChannelTestCommand}).
 */
class BackupTestShipCommand extends AbstractCommandChannelTestCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:backup:ship)
     */
    public function getName(): string
    {
        return CliCommands::BACKUP_TEST_SHIP;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Force a backup shipping pass through the live agent (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:backup:ship

Description:
  Copy every backup that is owed a copy to the configured destination now, through the
  running backup agent, and report how many made it. With no destination configured the
  pass moves nothing and reports zeros. Drives shipping in tests without waiting for a
  tick. Refuses on a production-like environment.

Usage:
  php cli.php test:backup:ship
HELP;
    }

    /**
     * Sends the shipping command to the agent and prints what the pass moved.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        try {
            $result = $this->sendCommand(BackupConstants::SHIP_COMMAND, []);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, BackupConstants::SHIP_COMMAND);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        $shipped = (int)($reply->payload[BackupConstants::FIELD_SHIPPED_COUNT] ?? 0);
        $failed = (int)($reply->payload[BackupConstants::FIELD_SHIP_FAILED_COUNT] ?? 0);
        echo "Shipped {$shipped} backup(s), {$failed} failed\n";

        return ExitCode::SUCCESS;
    }
}
