<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusTestCommandConstants;

/**
 * Asks the live Status owner for a public heartbeat now (test-only, HIL-1856).
 *
 * The CLI half only: the owner ({@see StatusAgent}) releases the hold an aged heartbeat put on its
 * publication, makes the heartbeat due and runs its ordinary publication step at once, freeze or
 * no freeze. Printed is the moment it published, in epoch milliseconds.
 */
class StatusTestHeartbeatNowCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @return string Command name */
    public function getName(): string
    {
        return CliCommands::STATUS_TEST_HEARTBEAT_NOW;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Release the Status publication hold and publish a heartbeat now (test-only)';
    }

    /** @return string Multi-line help text */
    public function getHelp(): string
    {
        $stale = CliCommands::STATUS_TEST_HEARTBEAT_STALE;

        return <<<HELP
Command: {$this->getName()}

Description:
  Publish the public Status heartbeat now, without waiting for its cadence. The
  Status owner releases the hold {$stale} put on its
  publication, makes the heartbeat due and runs its ordinary publication step at
  once, the same under a freeze; the next natural heartbeat follows a full interval
  later. Readers are told the files changed.
  Refuses when the step published nothing, and on a production-like environment.

Exit codes:
  0  a heartbeat was published
  1  the daemon did not answer, refused, or answered without the moment
  2  an argument was given
  3  the daemon host/port environment values are missing or invalid

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Asks the Status owner for a heartbeat now and prints the moment it published.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional arguments; there are none
     * @return int Exit code
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        if ($args !== []) {
            echo "Usage: {$this->getName()}\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $result = $this->sendCommand($this->getName(), []);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, $this->getName());
        }
        if (!$result->reply->isOk()) {
            return $this->printRefusal($result->reply);
        }

        $aliveAt = $result->reply->payload[StatusTestCommandConstants::FIELD_ALIVE_AT] ?? null;
        if (!is_int($aliveAt)) {
            echo "Command failed: the reply carries no heartbeat moment\n";

            return ExitCode::ERROR;
        }

        echo "Published a Status heartbeat now: alive at epoch ms {$aliveAt}\n";

        return ExitCode::SUCCESS;
    }
}
