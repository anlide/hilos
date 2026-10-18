<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Closure;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\Agent\AgentId;
use Hilos\Environment\Exception\EnvException;

/** Asks the running daemon to replace one agent instance. */
final class AgentRestartCommand implements CommandInterface, DatabaseFreeCommand
{
    use CommandChannelClientTrait;

    /** @var list<string> Option names this command reads, without the leading dashes */
    public const array OPTIONS = [
        self::OPTION_NODE,
    ];

    private const string OPTION_NODE = 'node';

    /** @param ?Closure(string, array<string, mixed>): CommandChannelResult $channel Command transport, if supplied */
    public function __construct(private readonly ?Closure $channel = null)
    {
    }

    /** @return string Command name */
    public function getName(): string
    {
        return CliCommands::AGENT_RESTART;
    }

    /** @return string Help-list description */
    public function getDescription(): string
    {
        return 'Restart one running agent';
    }

    /** @return string Full operator help */
    public function getHelp(): string
    {
        return <<<HELP
Command: {$this->getName()} <agentId> [--node=<nodeId>]

Description:
  Stop one running agent and start the same instance again. The daemon answers
  when it starts or tells you why it could not restart it.

Arguments:
  <agentId>  Agent type, or type:index for an indexed agent

Options:
  --node=<nodeId>  Node hosting a per-node agent

Exit codes:
  0  the agent started again
  1  the daemon refused or the command channel failed
  2  the agent id is missing
  3  the daemon host/port environment values are missing or invalid

Usage:
  php cli.php {$this->getName()} hilos_daemon_node
HELP;
    }

    /** @return CommandExecution Daemon-owned operation */
    public function execution(): CommandExecution
    {
        return CommandExecution::daemon();
    }

    /**
     * @param array<string, mixed> $options Parsed command options
     * @param list<string> $args Positional agent id
     * @return int Exit code
     */
    public function execute(array $options, array $args): int
    {
        if (!isset($args[0]) || $args[0] === '') {
            echo "Usage: php cli.php {$this->getName()} <agentId> [--node=<nodeId>]\n";

            return ExitCode::INVALID_ARGUMENT;
        }

        $agent = AgentId::fromId($args[0]);
        $nodeId = $options[self::OPTION_NODE] ?? null;
        try {
            $payload = [
                CommandConstants::FIELD_AGENT_TYPE => $agent->type,
                CommandConstants::FIELD_AGENT_INDEX => $agent->index,
                CommandConstants::FIELD_NODE_ID => $nodeId === null ? null : (string)$nodeId,
            ];
            $result = $this->channel === null
                ? $this->sendCommand($this->getName(), $payload)
                : ($this->channel)($this->getName(), $payload);
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

        $id = $result->reply->payload[CommandConstants::FIELD_AGENT_ID];
        $node = $result->reply->payload[CommandConstants::FIELD_NODE_ID];
        echo "Agent {$id} restarted on node {$node}\n";

        return ExitCode::SUCCESS;
    }
}
