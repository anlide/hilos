<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * Sets the test-only offset applied to this node's cluster clock probe answers.
 *
 * The CLI sends the request; the master keeps the shift in its process-local clock.
 */
class ClusterTestClockShiftCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @var list<string> Option names this command reads, without the leading dashes */
    public const array OPTIONS = [
        self::OPTION_MS,
    ];

    /** Option naming the signed clock shift in milliseconds. */
    private const string OPTION_MS = 'ms';

    /**
     * @return string Command name (test:cluster:clock:shift)
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TEST_CLOCK_SHIFT;
    }

    /**
     * @return string One-line command description
     */
    public function getDescription(): string
    {
        return 'Shift this node\'s cluster clock probe answers (test-only)';
    }

    /**
     * @return string Command usage and examples
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:cluster:clock:shift

Description:
  Shift this node's cluster clock probe answers for clock-offset tests. The shift is held only
  in the master's memory and is cleared when that process ends. Refuses on a production-like env.

Options:
  --ms=<N>       Signed whole number of milliseconds to apply (default: 0; one day at most)

Usage:
  php cli.php test:cluster:clock:shift [--ms=<N>]

Examples:
  php cli.php test:cluster:clock:shift --ms=1200
  php cli.php test:cluster:clock:shift --ms=-1200
  php cli.php test:cluster:clock:shift
HELP;
    }

    /**
     * Validates the requested integer, sends it to the master and prints the answered shift.
     *
     * @param array<string, mixed> $options Parsed options: --ms
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        $rawShiftMs = array_key_exists(self::OPTION_MS, $options) ? $options[self::OPTION_MS] : '0';
        if (!is_string($rawShiftMs) || preg_match('/^[+-]?\d+$/', $rawShiftMs) !== 1) {
            echo "Error: --ms must be a whole number of milliseconds\n";

            return ExitCode::ERROR;
        }

        $payload = [CommandConstants::FIELD_SHIFT_MS => (int)$rawShiftMs];

        try {
            $result = $this->sendCommand(CliCommands::CLUSTER_TEST_CLOCK_SHIFT, $payload);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CliCommands::CLUSTER_TEST_CLOCK_SHIFT);
        }

        $reply = $result->reply;
        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        $shiftMs = $reply->payload[CommandConstants::FIELD_SHIFT_MS] ?? null;
        if (!is_int($shiftMs)) {
            echo "Command failed: the reply names no cluster clock shift\n";

            return ExitCode::ERROR;
        }

        echo "Cluster clock test shift: {$shiftMs} ms\n";

        return ExitCode::SUCCESS;
    }
}
