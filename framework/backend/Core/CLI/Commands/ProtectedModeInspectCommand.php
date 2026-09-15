<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\Daemon\ProtectedModeSnapshotSource;
use Hilos\Environment\Exception\EnvException;
use JsonException;

/**
 * ProtectedModeInspectCommand - dump this node's protected-mode state as JSON.
 *
 * It sends `protected-mode:inspect` over the command channel and the master answers
 * synchronously with its own view ({@see ProtectedModeSnapshotSource}): the
 * runtime row's phase and initiator identity, the agents the freeze stopped on this node, and
 * whether the agent-start gate is shut. It only reads state; it never forces any - entering
 * and leaving go through an initiator agent, which is the subsystem's one entry.
 *
 * Answering in the master rather than in an agent is what makes it usable at all: during a
 * freeze every agent but the initiator is stopped, so an agent-answered inspector would time
 * out in exactly the phase worth inspecting.
 *
 * Production-safe because the snapshot publishes no credential and its fields were chosen for
 * the command channel's unauthenticated reader; HIL-743 closes that channel by binding the port,
 * not by judging the caller. Database-free by contract: it talks to nothing but the local
 * command socket, which lets it answer on a node whose database is unreachable.
 *
 * The snapshot is this node's view. On a cluster an initiating follower remains activating
 * while the leader alone records that every node quiesced, so the local phase must not be read
 * as a cluster-wide verdict.
 */
class ProtectedModeInspectCommand implements CommandInterface, DatabaseFreeCommand
{
    use CommandChannelClientTrait;

    /**
     * Returns command name for CLI routing.
     *
     * @return string Command name (protected-mode:inspect)
     */
    public function getName(): string
    {
        return CliCommands::PROTECTED_MODE_INSPECT;
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
        return 'Print this node\'s protected-mode state as JSON';
    }

    /**
     * Returns full help text with usage and examples.
     *
     * @return string Multi-line help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Command: {$this->getName()}

Description:
  Print the running daemon's own view of protected mode as JSON: the runtime row's
  phase and initiator identity, the agents this node stopped for the freeze, and
  whether the agent-start gate is shut. Answers in any phase, including mid-freeze,
  and changes nothing. A project without a runtime context answers rtMounted=false,
  so "not frozen" and "no protected mode here" stay distinguishable.

  This is the command a caller asks once when a protected-mode drive command went
  unanswered. The snapshot is this node's view; its phase does not say that every node
  of a cluster has quiesced.

Usage:
  php cli.php {$this->getName()}
HELP;
    }

    /**
     * Sends the inspect request and prints the daemon's snapshot as JSON.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args (unused)
     * @return int Exit code (0 on success)
     * @throws JsonException When the reply payload cannot be encoded to JSON
     */
    public function execute(array $options, array $args): int
    {
        try {
            $result = $this->sendCommand($this->getName(), []);
        } catch (EnvException $e) {
            echo "Error: {$e->getMessage()}\n";

            return ExitCode::CONFIG_ERROR;
        }

        if ($result->reply === null) {
            return $this->printChannelFailure($result, $this->getName());
        }

        $reply = $result->reply;

        if (!$reply->isOk()) {
            return $this->printRefusal($reply);
        }

        echo json_encode($reply->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

        return ExitCode::SUCCESS;
    }
}
