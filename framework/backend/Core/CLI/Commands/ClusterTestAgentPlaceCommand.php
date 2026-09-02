<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;

/**
 * ClusterTestAgentPlaceCommand - Ask the leader to place an agent on the cluster (HIL-696)
 *
 * A test-only driver (extends {@see TestOnlyCommand} via
 * {@see AbstractCommandChannelTestCommand}, so it refuses on a production-like env): it raises
 * on this node the same request a frame addressed at an agent nobody has placed yet raises, and
 * lets the ordinary on-demand path run — the leader picks the node, or asks nothing of a node
 * that does not lead but forwards the request to whoever does.
 *
 * What it exists for is an agent the harness needs on the mesh at a moment of its choosing
 * rather than at boot: the deliberate second claimer of a runtime collection, which every other
 * scenario must never meet. Nothing else can start it, because an indexed policy-placed agent
 * is outside the framework's own placement sweep.
 *
 * Database-free by contract: it talks to nothing but the local command socket.
 */
class ClusterTestAgentPlaceCommand extends AbstractCommandChannelTestCommand implements DatabaseFreeCommand
{
    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (test:cluster:agent:place)
     */
    public function getName(): string
    {
        return CliCommands::CLUSTER_TEST_AGENT_PLACE;
    }

    /**
     * Returns short command description for help listing.
     *
     * @return string One-line description
     */
    public function getDescription(): string
    {
        return 'Ask the leader to place an agent on the cluster (test-only)';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: test:cluster:agent:place

Description:
  Raise the placement request an addressed frame raises for an agent nobody has placed
  yet, and let the leader pick the node. Idempotent: an agent already placed, being
  placed, or refused is left exactly as it is. Omit the index for a singleton agent.
  Refuses on a production-like environment.

Usage:
  php cli.php test:cluster:agent:place <agentType> [<agentIndex>]

Examples:
  php cli.php test:cluster:agent:place claimer 0
HELP;
    }

    /**
     * Sends the placement request and reports what was asked for.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args; the agent type, then an optional agent index
     * @return int Exit code (0 on success)
     * @throws CommandException When the command name is not registered as test-only
     */
    protected function run(array $options, array $args): int
    {
        // external-boundary: the harness's command line, checked two lines below
        $agentType = $args[0] ?? '';
        // external-boundary: the same command line; absent means a singleton agent
        $agentIndex = $args[1] ?? null;
        if ($agentType === '') {
            echo "Error: the agentType argument is required\n";
            return ExitCode::ERROR;
        }

        try {
            $result = $this->sendCommand(CliCommands::CLUSTER_TEST_AGENT_PLACE, [
                CommandConstants::FIELD_AGENT_TYPE => $agentType,
                CommandConstants::FIELD_AGENT_INDEX => $agentIndex,
            ]);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";
            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, CliCommands::CLUSTER_TEST_AGENT_PLACE);
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        $agentId = $agentIndex === null ? $agentType : "{$agentType}:{$agentIndex}";
        echo "Asked for the placement of {$agentId}\n";

        return ExitCode::SUCCESS;
    }
}
