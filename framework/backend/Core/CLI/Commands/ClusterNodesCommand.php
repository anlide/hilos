<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\API\AsyncCommandClient;
use Hilos\Cluster\ClusterCommandConstants;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\RandomHelper;

/**
 * ClusterNodesCommand - list the cluster nodes the daemon knows about.
 *
 * Sends `cluster:nodes` over the command channel; the daemon answers
 * synchronously with the live node snapshot. Until the peer-join registry lands
 * (HIL-178) the snapshot is just the local node, so this reports whether cluster
 * mode is on and, when on, the local node's id, role, and capabilities.
 */
class ClusterNodesCommand implements CommandInterface
{
    /** @var float Wall-clock wait budget for a reply in milliseconds */
    private const float MAX_WAIT_MS = 2000.0;

    /** @var int Poll sleep between ticks in microseconds */
    private const int POLL_INTERVAL_US = 10000;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (cluster:nodes)
     */
    public function getName(): string
    {
        return 'cluster:nodes';
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'List the cluster nodes the daemon knows about';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: cluster:nodes

Description:
  List the cluster nodes the running daemon knows about. When cluster mode is
  off the daemon runs as a single node and this reports it disabled. Until the
  peer-join registry lands the known set is just the local node.

Usage:
  php cli.php cluster:nodes
HELP;
    }

    /**
     * Execute the cluster:nodes command.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
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

        $this->printSnapshot($reply->payload);

        return ExitCode::SUCCESS;
    }

    /**
     * Prints the node snapshot returned by the daemon.
     *
     * @param array<string, mixed> $payload Reply payload from cluster:nodes
     */
    private function printSnapshot(array $payload): void
    {
        if (($payload[ClusterCommandConstants::FIELD_ENABLED] ?? false) !== true) {
            echo "Cluster mode is disabled (single-node).\n";
            return;
        }

        $nodes = $payload[ClusterCommandConstants::FIELD_NODES] ?? [];
        $count = is_array($nodes) ? count($nodes) : 0;
        echo "Cluster nodes ({$count}):\n";

        if (!is_array($nodes)) {
            return;
        }

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $nodeId = (string) ($node[ClusterCommandConstants::FIELD_NODE_ID] ?? '?');
            $role = (string) ($node[ClusterCommandConstants::FIELD_NODE_ROLE] ?? '?');
            $capabilities = $node[ClusterCommandConstants::FIELD_NODE_CAPABILITIES] ?? [];
            $capabilitiesText = is_array($capabilities) && $capabilities !== []
                ? implode(',', array_map('strval', $capabilities))
                : '-';

            echo "  {$nodeId}  role={$role}  capabilities={$capabilitiesText}\n";
        }
    }

    /**
     * Sends cluster:nodes over the command channel and waits for the reply.
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
            command: CommandConstants::COMMAND_CLUSTER_NODES,
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
