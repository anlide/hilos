<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\API\AsyncCommandClient;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\ExitCode;
use Hilos\Constants\TimeConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\RandomHelper;
use Throwable;

/**
 * The {@see AsyncCommandClient} round-trip a CLI command uses to drive a running agent.
 *
 * A trait rather than a base class because the two callers cannot share an ancestor: the
 * test-only family ({@see AbstractCommandChannelTestCommand}) must keep extending
 * {@see TestOnlyCommand} so it refuses on a production-like environment, while an operator
 * command like {@see BackupVerifyCommand} is a plain {@see CommandInterface} that must NOT
 * refuse there - reaching the daemon is the only thing they have in common.
 *
 * One wait budget serves every caller. Three commands used to carry their own copy of this
 * round-trip with a 2-second budget of their own; the budget a command waits for its daemon is
 * not something a command has an opinion about, and the copies proved it by disagreeing without
 * anyone having decided they should.
 */
trait CommandChannelClientTrait
{
    /** @var float Wall-clock wait budget for a reply in milliseconds */
    private const float MAX_WAIT_MS = 5000.0;

    /** @var string What a refusal is called when the daemon answered with one and worded no reason */
    private const string UNWORDED_REFUSAL = 'unknown error';

    /**
     * Sends one command over the channel and waits for its reply.
     *
     * @param string $command Command-channel wire name routed to the owning agent
     * @param array<string, mixed> $payload Request payload delivered to the agent
     * @return CommandChannelResult Reply, or why none arrived
     * @throws EnvException When daemon host/port env values are missing or invalid
     */
    protected function sendCommand(string $command, array $payload): CommandChannelResult
    {
        $host = Hilos::$env[EnvConstants::HILOS_DAEMON_HOST];
        $port = Hilos::$env->int(EnvConstants::COMMAND_PORT);
        $address = "{$host}:{$port}";

        $client = new AsyncCommandClient($host, $port);
        $request = new CommandRequestDTO(
            correlationId: RandomHelper::hex(8),
            command: $command,
            payload: $payload,
        );

        try {
            $client->startRequest($request);

            $startedAtMs = microtime(true) * TimeConstants::MS_PER_SECOND;
            while (!$client->hasResult()) {
                if ((microtime(true) * TimeConstants::MS_PER_SECOND - $startedAtMs) > self::MAX_WAIT_MS) {
                    return CommandChannelResult::timedOut($address);
                }

                $client->tick();
                usleep(CommandConstants::POLL_INTERVAL_US);
            }

            return CommandChannelResult::replied($client->consumeResult(), $address);
        } catch (Throwable) {
            // Every way the socket can fail - refused, reset, unparseable - says the same thing to
            // the operator: the channel is not there. What it is NOT is a daemon that heard the
            // command and stayed silent, and that is the distinction the two texts exist for.
            return CommandChannelResult::unreachable($address);
        }
    }

    /**
     * Words why a round-trip came back without a reply.
     *
     * The one place either sentence is written. Callers print the SUCCESS of their own command,
     * because that is theirs to word; a failure of the transport is the transport's, and the
     * twenty-five hand-copied versions of it were identical only by luck.
     *
     * Separate from the printing for a reason that is not tidiness: the sentence goes to stderr,
     * and no test suite here can read stderr - PHPUnit's output expectations see stdout only. A
     * text returned is a text a test can pin, which is what keeps a ticket about refusals from
     * quietly ending the one check that reads them.
     *
     * @param CommandChannelResult $result Failed round-trip; a result carrying a reply is not for this
     * @param string $command Command-channel wire name that went unanswered
     * @return string Sentence naming why no reply arrived, without its line break
     */
    protected function channelFailureText(CommandChannelResult $result, string $command): string
    {
        $seconds = (int)(self::MAX_WAIT_MS / TimeConstants::MS_PER_SECOND);

        return match ($result->failure) {
            CommandChannelFailure::TIMEOUT => "The daemon did not answer {$command} within {$seconds}s",
            default => "Cannot reach the daemon command channel at {$result->address}",
        };
    }

    /**
     * Prints why a round-trip came back without a reply, and answers with the exit code for it.
     *
     * @param CommandChannelResult $result Failed round-trip; a result carrying a reply is not for this
     * @param string $command Command-channel wire name that went unanswered
     * @return int Exit code to return from the command
     */
    protected function printChannelFailure(CommandChannelResult $result, string $command): int
    {
        return $this->printToStandardError($this->channelFailureText($result, $command));
    }

    /**
     * Words a refusal the daemon answered with.
     *
     * The refusal is the daemon's to word and the command's only to relay, exactly as the
     * transport failure above is. Before this, thirty-five commands each read the message out of
     * the payload and named the event themselves, and they disagreed about it: twenty-seven said
     * "Command failed", eight said "Refused", for one and the same reply.
     *
     * A reply that carries no message is still a refusal, so it is worded rather than skipped -
     * the operator has to learn that the command did not happen even when nobody said why.
     *
     * @param CommandReplyDTO $reply Error reply from the daemon; an ok reply is not for this
     * @return string Sentence relaying the refusal, without its line break
     */
    protected function refusalText(CommandReplyDTO $reply): string
    {
        // The answering agent may word no message, and the reply is still a refusal
        $message = $reply->payload[CommandConstants::FIELD_MESSAGE] ?? self::UNWORDED_REFUSAL;

        return 'Refused: ' . (is_string($message) ? $message : self::UNWORDED_REFUSAL);
    }

    /**
     * Prints a refusal the daemon answered with, and answers with the exit code for it.
     *
     * @param CommandReplyDTO $reply Error reply from the daemon; an ok reply is not for this
     * @return int Exit code to return from the command
     */
    protected function printRefusal(CommandReplyDTO $reply): int
    {
        return $this->printToStandardError($this->refusalText($reply));
    }

    /**
     * Writes one sentence to stderr and answers with the exit code a failed command returns.
     *
     * Both failures of a command channel round-trip go to stderr, and neither is a result: the
     * whole point of the two streams is that a caller can read what a command produced without
     * reading what went wrong with it. The success stays where it is, on stdout, because that
     * one IS the result.
     *
     * Protected for the reason {@see sendCommand()} is: a command double stands in for the one
     * side of itself that reaches outside the process, and stderr is the other. Nothing here
     * chooses a stream - there is one behaviour and one implementation of it.
     *
     * @param string $text Sentence to write, without its line break
     * @return int Exit code to return from the command
     */
    protected function printToStandardError(string $text): int
    {
        fwrite(STDERR, $text . "\n");

        return ExitCode::ERROR;
    }
}
