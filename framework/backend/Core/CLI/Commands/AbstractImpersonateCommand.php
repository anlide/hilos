<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\ExitCode;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;

/**
 * Shared base for the impersonation start/stop CLI commands.
 *
 * Sends an impersonation command over the daemon command channel; the daemon parks the
 * connection, routes it to the agent that answers the name, and writes the reply back.
 * Subclasses parse their positional args into a payload and format the success line; the
 * wire name is the CLI name, as it is for every other command of this directory.
 *
 * Real operator commands, not test-only, and database-free ({@see DatabaseFreeCommand}):
 * everything they cause is written where the session lives, so the CLI process needs no
 * connection of its own.
 */
abstract class AbstractImpersonateCommand implements CommandInterface, DatabaseFreeCommand
{
    use CommandChannelClientTrait;

    /**
     * Declares the rule for both subclasses: the daemon starts and stops the impersonation,
     * this process only asks it to.
     *
     * @return CommandExecution Where this family's work happens
     */
    final public function execution(): CommandExecution
    {
        return CommandExecution::daemon();
    }

    /**
     * Parses positional args into the command payload, or returns null when the
     * args are invalid (the subclass prints its own usage hint before returning).
     *
     * @param list<string> $args Positional CLI args
     * @return ?array<string, mixed> Command payload, or null when the args are invalid
     */
    abstract protected function buildPayload(array $args): ?array;

    /**
     * Formats the human line printed for a successful (ok) reply.
     *
     * @param CommandReplyDTO $reply Ok reply from the agent
     * @return string Single-line success summary
     */
    abstract protected function describeSuccess(CommandReplyDTO $reply): string;

    /**
     * Sends the impersonation command through the channel and prints the outcome.
     *
     * @param array<string, mixed> $options Parsed options (unused)
     * @param list<string> $args Positional args parsed by the subclass
     * @return int Exit code (0 on success)
     */
    public function execute(array $options, array $args): int
    {
        $payload = $this->buildPayload($args);
        if ($payload === null) {
            return ExitCode::INVALID_ARGUMENT;
        }

        try {
            $result = $this->sendCommand($this->getName(), $payload);
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

        echo $this->describeSuccess($reply) . "\n";

        return ExitCode::SUCCESS;
    }
}
