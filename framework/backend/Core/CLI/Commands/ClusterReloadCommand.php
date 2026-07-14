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
 * ClusterReloadCommand - reload cluster config and re-announce the local node.
 *
 * Sends `cluster:reload` over the command channel; the running daemon re-reads
 * its environment, rebuilds the local node's role/capabilities/address, refreshes
 * the master registry, and gossips the change to the peer mesh. This picks up an
 * operator's capability or role edits without a restart. It does not rebind the
 * peer listener or re-dial new seeds, and it treats the node id as stable:
 * changing the bind address, seeds, or node id still needs a restart.
 */
class ClusterReloadCommand implements CommandInterface
{
    /** @var float Wall-clock wait budget for a reply in milliseconds */
    private const float MAX_WAIT_MS = 2000.0;

    /** @var int Poll sleep between ticks in microseconds */
    private const int POLL_INTERVAL_US = 10000;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (cluster:reload)
     */
    public function getName(): string
    {
        return 'cluster:reload';
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Reload cluster config and re-announce the local node';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: cluster:reload

Description:
  Ask the running daemon to re-read its cluster configuration, rebuild the local
  node's role, capabilities, and advertised address, refresh the membership
  registry, and re-announce the local node to the peer mesh. Use it to apply
  capability or role edits without restarting the daemon. It does not rebind the
  peer listener, re-dial new seeds, or change the node id; those still need a
  restart. Fails when cluster mode is disabled.

Usage:
  php cli.php cluster:reload
HELP;
    }

    /**
     * Execute the cluster:reload command.
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

        $this->printResult($reply->payload);

        return ExitCode::SUCCESS;
    }

    /**
     * Prints the reload outcome returned by the daemon.
     *
     * @param array<string, mixed> $payload Reply payload from cluster:reload
     */
    private function printResult(array $payload): void
    {
        $changed = ($payload[ClusterCommandConstants::FIELD_CHANGED] ?? false) === true;
        echo $changed
            ? "Cluster config reloaded; local node changed and was re-announced.\n"
            : "Cluster config reloaded; local node unchanged.\n";

        $nodes = $payload[ClusterCommandConstants::FIELD_NODES] ?? [];
        if (!is_array($nodes)) {
            return;
        }

        echo "Cluster nodes (" . count($nodes) . "):\n";
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $nodeId = (string) ($node[ClusterCommandConstants::FIELD_NODE_ID] ?? '?');
            $role = (string) ($node[ClusterCommandConstants::FIELD_NODE_ROLE] ?? '?');
            $online = ($node[ClusterCommandConstants::FIELD_NODE_ONLINE] ?? false) === true ? 'online' : 'offline';
            $capabilities = $node[ClusterCommandConstants::FIELD_NODE_CAPABILITIES] ?? [];
            $capabilitiesText = is_array($capabilities) && $capabilities !== []
                ? implode(',', array_map('strval', $capabilities))
                : '-';

            echo "  {$nodeId}  role={$role}  {$online}  capabilities={$capabilitiesText}\n";
        }
    }

    /**
     * Sends cluster:reload over the command channel and waits for the reply.
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
            command: CommandConstants::COMMAND_CLUSTER_RELOAD,
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
