<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Cluster\ClusterCommandConstants;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Environment\Exception\EnvException;

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
    use CommandChannelClientTrait;

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
            $result = $this->sendCommand(CommandConstants::COMMAND_CLUSTER_RELOAD, []);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CommandConstants::COMMAND_CLUSTER_RELOAD);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
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
}
