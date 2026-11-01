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
 * Asks the live Status owner to age its public heartbeat past the reader's stale limit (test-only, HIL-1856).
 *
 * The CLI half only: the owner ({@see StatusAgent}) publishes the heartbeat of its last coherent
 * pair at now minus the stale limit and one second more, sends the hint, and holds its own
 * publication until {@see StatusTestHeartbeatNowCommand} or its restart. Printed is the moment it
 * published, in epoch milliseconds.
 */
class StatusTestHeartbeatStaleCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @return string Command name */
    public function getName(): string
    {
        return CliCommands::STATUS_TEST_HEARTBEAT_STALE;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Publish a Status heartbeat aged past the stale limit and hold the owner\'s publication (test-only)';
    }

    /** @return string Multi-line help text */
    public function getHelp(): string
    {
        $now = CliCommands::STATUS_TEST_HEARTBEAT_NOW;

        return <<<HELP
Command: {$this->getName()}

Description:
  Make the public Status page read its daemon as not responding, without waiting
  for it. The Status owner publishes the heartbeat of its last coherent pair at
  now minus the stale limit and one second more, tells connected readers the files
  changed, and publishes nothing more - heartbeat or content - until {$now}
  or its own restart.
  Refuses while no coherent pair is published, and on a production-like environment.

Exit codes:
  0  the aged heartbeat was published
  1  the daemon did not answer, refused, or answered without the moment
  2  an argument was given
  3  the daemon host/port environment values are missing or invalid

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Asks the Status owner to age its heartbeat and prints the moment it published.
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

        echo "Aged the Status heartbeat: alive at epoch ms {$aliveAt}\n";

        return ExitCode::SUCCESS;
    }
}
