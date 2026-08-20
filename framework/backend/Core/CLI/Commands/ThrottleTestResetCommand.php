<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\ThrottleCommandConstants;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * ThrottleTestResetCommand - forget every anti-abuse counter and block (test-only).
 *
 * The anti-abuse layer (HIL-420) remembers an address across connections and outlasts any
 * one test: a spec that drove a key into a block would leave the next spec refused from the
 * same address, for something it never did. This hands it a clean slate by driving the
 * running {@see AuthThrottleAgent} over the command channel
 * ({@see CliCommands::THROTTLE_TEST_RESET}), which is the only process that may clear
 * either half - the counters are its runtime collection and the blocks are its table.
 * Test-only ({@see TestOnlyCommand} via {@see AbstractCommandChannelTestCommand}), and the
 * agent refuses it again on its own side.
 */
class ThrottleTestResetCommand extends AbstractCommandChannelTestCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:throttle:reset)
     */
    public function getName(): string
    {
        return CliCommands::THROTTLE_TEST_RESET;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Forget every anti-abuse counter and stored block (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:throttle:reset

Description:
  Drop every anti-abuse attempt counter and delete every stored block through the
  running throttle agent, so the next test starts from an address nothing is held
  against. Reports how many counters and how many blocks went. Refuses on a
  production-like environment.

Usage:
  php cli.php test:throttle:reset
HELP;
    }

    /**
     * Sends the reset to the agent and prints what each half of the state gave up.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        try {
            $reply = $this->sendCommand(CliCommands::THROTTLE_TEST_RESET, []);
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

        $counters = (int)($reply->payload[ThrottleCommandConstants::FIELD_COUNTERS_CLEARED] ?? 0);
        $blocks = (int)($reply->payload[ThrottleCommandConstants::FIELD_BLOCKS_CLEARED] ?? 0);
        echo "Cleared {$counters} counter(s) and {$blocks} block(s)\n";

        return ExitCode::SUCCESS;
    }
}
