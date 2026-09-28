<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * AdminViewModeTestCommand - turn the admin view mode of this node on or off until it restarts (HIL-1249).
 *
 * The mode is decided once, at the start of the node, from a variable the living process cannot
 * change, and a stand has e2e cases on both sides of it: a non-admin who may look, and a non-admin
 * who is turned away. This command switches the node's mode between them without a restart; the
 * next start decides from the variable again.
 *
 * A test-only command (extends {@see TestOnlyCommand}, so it refuses on a production-like env)
 * and database-free by contract: the master answers from its own runtime state and writes the
 * node's mode row, so the CLI process has nothing to read or write. The latch is not touched: a
 * stand has none, and production refuses the command.
 */
class AdminViewModeTestCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** Argument that turns the mode on. */
    private const string ARGUMENT_ON = 'on';

    /** Argument that turns the mode off. */
    private const string ARGUMENT_OFF = 'off';

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:admin-view-mode)
     */
    public function getName(): string
    {
        return CliCommands::ADMIN_VIEW_MODE_TEST;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Turn the admin view mode of this node on or off until it restarts (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:admin-view-mode

Description:
  Turn the admin view mode of this node on or off: with it on, a non-admin may open
  the admin section to look and change nothing. The node's master takes the switch
  and its workers follow it at once. It holds until the daemon restarts; the next
  start decides from HILOS_ADMIN_VIEW_MODE_ENABLED again. Refuses on a
  production-like environment.

Usage:
  php cli.php test:admin-view-mode on|off

Examples:
  php cli.php test:admin-view-mode on
  php cli.php test:admin-view-mode off
HELP;
    }

    /**
     * Sends on or off to the master and prints the mode it wrote.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the first is on or off
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        $enabled = match ($args[0] ?? null) {
            self::ARGUMENT_ON => true,
            self::ARGUMENT_OFF => false,
            default => null,
        };
        if ($enabled === null) {
            echo "Error: say on or off\n";

            return ExitCode::ERROR;
        }

        try {
            $result = $this->sendCommand(
                CliCommands::ADMIN_VIEW_MODE_TEST,
                [CommandConstants::FIELD_ENABLED => $enabled],
            );
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CliCommands::ADMIN_VIEW_MODE_TEST);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        // The master writes the mode on every successful reply; a reply without it is an
        // incomplete answer, and printing it would report a mode nobody set.
        $written = $reply->payload[CommandConstants::FIELD_ENABLED] ?? null;
        if (!is_bool($written)) {
            echo "Command failed: the reply names no admin view mode\n";

            return ExitCode::ERROR;
        }

        echo $written ? "Admin view mode on\n" : "Admin view mode off\n";

        return ExitCode::SUCCESS;
    }
}
