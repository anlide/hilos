<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Cluster\ClusterCommandConstants;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Environment\Exception\EnvException;

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
    use CommandChannelClientTrait;

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
     * Declares the rule: the daemon does the work and this process only initiates it and prints.
     *
     * @return CommandExecution Where this command's work happens
     */
    public function execution(): CommandExecution
    {
        return CommandExecution::daemon();
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
            $result = $this->sendCommand(CommandConstants::COMMAND_CLUSTER_NODES, []);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CommandConstants::COMMAND_CLUSTER_NODES);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
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
            $online = ($node[ClusterCommandConstants::FIELD_NODE_ONLINE] ?? false) === true ? 'online' : 'offline';
            $capabilities = $node[ClusterCommandConstants::FIELD_NODE_CAPABILITIES] ?? [];
            $capabilitiesText = is_array($capabilities) && $capabilities !== []
                ? implode(',', array_map('strval', $capabilities))
                : '-';

            echo "  {$nodeId}  role={$role}  {$online}  capabilities={$capabilitiesText}\n";
        }
    }
}
