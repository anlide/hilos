<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Environment\Exception\EnvException;

/**
 * Has this node's Daemon agent publish its picture now, with fresh master frames (test-only, HIL-1857).
 *
 * The CLI half only: the master ({@see DaemonManager}) forces its frames for the node agent,
 * waits for its process roster to go out, then asks the node agent ({@see DaemonNodeAgent}) to
 * report at once, past its change throttle. Printed is the node and the moment it reported, in
 * epoch milliseconds.
 */
class DaemonTestNodeReportCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /** @return string Command name */
    public function getName(): string
    {
        return CliCommands::DAEMON_TEST_NODE_REPORT;
    }

    /** @return string One-line description */
    public function getDescription(): string
    {
        return 'Publish this node\'s Daemon picture now, with fresh master frames (test-only)';
    }

    /** @return string Multi-line help text */
    public function getHelp(): string
    {
        return <<<HELP
Command: {$this->getName()}

Description:
  Publish this node's Daemon picture now, without waiting for the node agent's
  change throttle. The master sends every frame it owes the node agent afresh,
  waits until its process roster has gone out, then asks the node agent to report
  at once; the next change follows the throttle counted from this report.
  Refuses under protected mode, when the node agent is not running, and on a
  production-like environment.

Exit codes:
  0  the node agent reported
  1  the daemon did not answer, refused, or answered without the moment
  2  an argument was given
  3  the daemon host/port environment values are missing or invalid

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Asks the master for a node report now and prints the node and the moment it reported.
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

        $nodeId = $result->reply->payload[CommandConstants::FIELD_NODE_ID] ?? null;
        $reportedAt = $result->reply->payload[CommandConstants::FIELD_REPORTED_AT] ?? null;
        if (!is_string($nodeId) || !is_int($reportedAt)) {
            echo "Command failed: the reply carries no report moment\n";

            return ExitCode::ERROR;
        }

        echo "Reported node {$nodeId}: at epoch ms {$reportedAt}\n";

        return ExitCode::SUCCESS;
    }
}
