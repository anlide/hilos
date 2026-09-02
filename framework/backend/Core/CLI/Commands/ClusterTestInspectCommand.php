<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use JsonException;

/**
 * ClusterTestInspectCommand - dump the daemon's cluster/consensus/placement state as JSON.
 *
 * A test-only inspector (extends {@see TestOnlyCommand}, so it refuses on a
 * production-like env): the multi-node harness (HIL-185) runs it against each node
 * and asserts on the machine-readable reply. It sends `test:cluster:inspect` over the
 * command channel; the daemon answers synchronously with that node's own view —
 * membership, its consensus verdicts (leader / term / role / quorum) and lifecycle
 * phase, and the leader-tracked placements. It only reads state; it never forces any.
 *
 * Database-free by contract: it talks to nothing but the local command socket, which is
 * what lets the harness inspect a network-partitioned node from inside its own container —
 * that node cannot reach MySQL either, and its answer is the point of the scenario.
 */
class ClusterTestInspectCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:cluster:inspect)
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TEST_INSPECT;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Inspect the daemon cluster/consensus/placement state as JSON (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:cluster:inspect

Description:
  Print the running daemon's own view of the cluster as JSON: membership, this
  node's consensus verdicts (leader / term / role / quorum) and lifecycle phase,
  and the leader-tracked agent placements. Read-only; it forces no state. Refuses
  on a production-like environment. Used by the multi-node test harness to assert
  on each node deterministically.

Usage:
  php cli.php test:cluster:inspect
HELP;
    }

    /**
     * Sends the inspect request and prints the daemon's reply payload as JSON.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     * @throws JsonException When the reply payload cannot be encoded to JSON
     */
    protected function run(array $options, array $args): int
    {
        try {
            $result = $this->sendCommand(CliCommands::CLUSTER_TEST_INSPECT, []);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CliCommands::CLUSTER_TEST_INSPECT);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        echo json_encode($reply->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        return ExitCode::SUCCESS;
    }
}
