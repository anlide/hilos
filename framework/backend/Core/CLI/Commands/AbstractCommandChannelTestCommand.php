<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Constants\CommandConstants;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Exception\CommandException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;

/**
 * Base for test-only CLI commands that drive a running agent over the command channel.
 *
 * Extends {@see TestOnlyCommand} (so the whole family refuses on a production-like env) and
 * carries the {@see CommandChannelClientTrait} round-trip {@see PingCommand} pioneered: open
 * the socket, send a {@see CommandRequestDTO}, poll until a reply or timeout, and hand the
 * reply back. Subclasses build the payload, name the command, and render the reply.
 *
 * The round-trip itself lives in the trait because operator commands need it too, and they
 * must not inherit the test-only refusal.
 */
abstract class AbstractCommandChannelTestCommand extends TestOnlyCommand
{
    use CommandChannelClientTrait {
        sendCommand as private sendChannelCommand;
    }

    /**
     * Declares the rule for the whole family: every command here drives a running agent.
     *
     * Final, and stated once rather than in each subclass, because membership in this hierarchy
     * IS the declaration - {@see sendCommand()} below is the only way out of these classes, and
     * it puts the name on the command channel. A subclass that wanted another site would have to
     * stop sending, and then it does not belong here.
     *
     * @return CommandExecution Where this family's work happens
     */
    final public function execution(): CommandExecution
    {
        return CommandExecution::daemon();
    }

    /**
     * Sends one command over the channel, refusing a name that does not carry the test-only prefix.
     *
     * The latch behind the whole family: a class in this hierarchy is test-only by contract, so
     * the name it puts on the wire has to be test-only too, or the socket gate will wave that
     * name through on a production node. Nothing else checks the pairing - reading "this CLI
     * class is test-only" off the class hierarchy needs Reflection, which this project forbids -
     * and a forgotten flag is invisible until someone reaches the port of a live installation.
     * Refusing here instead puts the mistake in front of whoever runs the existing suites: every
     * command of this family is driven there, so the first run after the omission fails on it.
     *
     * It asks the NAME rather than {@see TestOnlyCommandRegistry}, and the difference is not
     * cosmetic: the registry answers per installation, because the flag is declared by an agent
     * a project may not register, while {@see CliManager} hands every project the whole family.
     * A demo without a backup agent would then be told its `test:backup:prune` "is not test-only",
     * which is a wrong account of a different problem. The prefix is the same fact stated
     * project-independently - topology validation refuses to start a daemon whose flag and prefix
     * disagree either way, so a prefixed name is a flagged name wherever the agent does live.
     *
     * @param string $command Command-channel wire name routed to the owning agent
     * @param array<string, mixed> $payload Request payload delivered to the agent
     * @return CommandChannelResult Reply, or why none arrived
     * @throws CommandException When the command name does not carry the test-only prefix
     * @throws EnvException When daemon host/port env values are missing or invalid
     */
    protected function sendCommand(string $command, array $payload): CommandChannelResult
    {
        if (!str_starts_with($command, CommandConstants::TEST_ONLY_PREFIX)) {
            throw new CommandException(
                "Command {$command} is sent by a test-only CLI command but is not named "
                . CommandConstants::TEST_ONLY_PREFIX . '*',
            );
        }

        return $this->sendChannelCommand($command, $payload);
    }
}
