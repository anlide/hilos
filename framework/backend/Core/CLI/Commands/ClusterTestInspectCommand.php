<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\API\AsyncCommandClient;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\RandomHelper;
use JsonException;

/**
 * ClusterTestInspectCommand - dump the daemon's cluster/consensus/placement state as JSON.
 *
 * A test-only inspector (extends {@see TestOnlyCommand}, so it refuses on a
 * production-like env): the multi-node harness (HIL-185) runs it against each node
 * and asserts on the machine-readable reply. It sends `cluster:test:inspect` over the
 * command channel; the daemon answers synchronously with that node's own view —
 * membership, its consensus verdicts (leader / term / role / quorum) and lifecycle
 * phase, and the leader-tracked placements. It only reads state; it never forces any.
 */
class ClusterTestInspectCommand extends TestOnlyCommand
{
    /** @var float Wall-clock wait budget for a reply in milliseconds */
    private const float MAX_WAIT_MS = 2000.0;

    /** @var int Poll sleep between ticks in microseconds */
    private const int POLL_INTERVAL_US = 10000;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (cluster:test:inspect)
     */
    public function getName(): string
    {
        return CommandConstants::COMMAND_CLUSTER_INSPECT;
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
Command: cluster:test:inspect

Description:
  Print the running daemon's own view of the cluster as JSON: membership, this
  node's consensus verdicts (leader / term / role / quorum) and lifecycle phase,
  and the leader-tracked agent placements. Read-only; it forces no state. Refuses
  on a production-like environment. Used by the multi-node test harness to assert
  on each node deterministically.

Usage:
  php cli.php cluster:test:inspect
HELP;
    }

    /**
     * Sends the inspect request and prints the daemon's reply payload as JSON.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws JsonException When the reply payload cannot be encoded to JSON
     */
    protected function run(array $options, array $args): int
    {
        try {
            $reply = $this->sendRequest();
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($reply === null) {
            echo "No reply from daemon (is it running?)\n";
            return ExitCode::ERROR;
        }

        if (!$reply->isOk()) {
            $detail = (string) ($reply->payload[CommandConstants::FIELD_MESSAGE] ?? 'unknown error');
            echo "Command failed: {$detail}\n";
            return ExitCode::ERROR;
        }

        echo json_encode($reply->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Sends cluster:test:inspect over the command channel and waits for the reply.
     *
     * @return ?CommandReplyDTO Reply, or null on timeout / transport failure
     * @throws EnvException When daemon host/port env values are missing or invalid
     */
    private function sendRequest(): ?CommandReplyDTO
    {
        $host = Hilos::$env[EnvConstants::HILOS_DAEMON_HOST];
        $port = Hilos::$env->int(EnvConstants::COMMAND_PORT);

        $client = new AsyncCommandClient($host, $port);
        $request = new CommandRequestDTO(
            correlationId: RandomHelper::hex(8),
            command: CommandConstants::COMMAND_CLUSTER_INSPECT,
            payload: [],
        );

        try {
            $client->startRequest($request);

            $startedAtMs = microtime(true) * 1000;
            while (!$client->hasResult()) {
                if ((microtime(true) * 1000 - $startedAtMs) > self::MAX_WAIT_MS) {
                    return null;
                }

                $client->tick();
                usleep(self::POLL_INTERVAL_US);
            }

            return $client->consumeResult();
        } catch (\Throwable) {
            return null;
        }
    }
}
