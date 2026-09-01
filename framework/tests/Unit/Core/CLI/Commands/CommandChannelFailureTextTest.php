<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandChannelClientTrait;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use PHPUnit\Framework\TestCase;

/**
 * Pins the sentences a command-channel round-trip ends in when it does not end in a result.
 *
 * Before HIL-728 the transport had one, "No reply from daemon (is it running?)", hand-copied
 * into twenty-five files. It asked the operator a question the CLI already knew the answer to,
 * and gave the same answer to two problems that are fixed differently: nothing listening on the
 * channel, and a daemon that heard the command and never replied.
 *
 * HIL-730 did the same for the refusal the daemon answers WITH, which thirty-five commands each
 * worded for themselves - twenty-seven as "Command failed", eight as "Refused", for one and the
 * same reply - and moved all of it to stderr, where a failure belongs.
 *
 * The sentences are read back from the wording methods rather than from the process's output on
 * purpose: stderr is not something a test in this suite can capture, and a check that quietly
 * stopped reading anything would be the exact silence this ticket is about.
 */
final class CommandChannelFailureTextTest extends TestCase
{
    private const string ADDRESS = '127.0.0.1:8094';

    private const string COMMAND = 'test:backup:prune';

    private const string CORRELATION_ID = 'unit-correlation';

    public function testAnUnreachableChannelNamesTheAddressItTried(): void
    {
        $printer = new ChannelFailurePrinter();

        self::assertSame(
            'Cannot reach the daemon command channel at 127.0.0.1:8094',
            $printer->failureText(CommandChannelResult::unreachable(self::ADDRESS), self::COMMAND),
        );
    }

    public function testASilentDaemonNamesTheCommandAndTheBudgetItWaited(): void
    {
        $printer = new ChannelFailurePrinter();

        self::assertSame(
            'The daemon did not answer test:backup:prune within 5s',
            $printer->failureText(CommandChannelResult::timedOut(self::ADDRESS), self::COMMAND),
        );
    }

    public function testARefusalRelaysTheReasonTheDaemonGave(): void
    {
        $printer = new ChannelFailurePrinter();

        self::assertSame(
            'Refused: the freeze file is held by another node',
            $printer->refusal(CommandReplyDTO::error(self::CORRELATION_ID, 'the freeze file is held by another node')),
        );
    }

    /**
     * A refusal with nothing in it is still a refusal: the command did not happen, and saying
     * nothing would leave that fact to the exit code alone.
     */
    public function testARefusalWithNoMessageIsStillWorded(): void
    {
        $printer = new ChannelFailurePrinter();

        self::assertSame(
            'Refused: unknown error',
            $printer->refusal(new CommandReplyDTO(self::CORRELATION_ID, CommandConstants::STATUS_ERROR)),
        );
    }

    /**
     * Both printers answer with the exit code a failed command returns, and write their sentence
     * to stderr rather than into the command's result.
     */
    public function testBothPrintersWriteToStandardErrorAndFail(): void
    {
        $printer = new ChannelFailurePrinter();

        self::assertSame(
            ExitCode::ERROR,
            $printer->print(CommandChannelResult::timedOut(self::ADDRESS), self::COMMAND),
        );
        self::assertSame(
            ExitCode::ERROR,
            $printer->printRefused(CommandReplyDTO::error(self::CORRELATION_ID, 'no')),
        );
        self::assertSame(
            [
                'The daemon did not answer test:backup:prune within 5s',
                'Refused: no',
            ],
            $printer->written,
        );
    }
}

/**
 * Reaches the trait's protected wording and printing, which is where all four sentences live.
 */
final class ChannelFailurePrinter
{
    use CommandChannelClientTrait;

    /** @var list<string> Sentences the printers handed to the stderr writer, in order */
    public array $written = [];

    /**
     * @param CommandChannelResult $result Failed round-trip to word
     * @param string $command Wire name that went unanswered
     * @return string Sentence the transport failure is worded as
     */
    public function failureText(CommandChannelResult $result, string $command): string
    {
        return $this->channelFailureText($result, $command);
    }

    /**
     * @param CommandReplyDTO $reply Error reply to word
     * @return string Sentence the refusal is worded as
     */
    public function refusal(CommandReplyDTO $reply): string
    {
        return $this->refusalText($reply);
    }

    /**
     * @param CommandChannelResult $result Failed round-trip to print
     * @param string $command Wire name that went unanswered
     * @return int Exit code the printer answers with
     */
    public function print(CommandChannelResult $result, string $command): int
    {
        return $this->printChannelFailure($result, $command);
    }

    /**
     * @param CommandReplyDTO $reply Error reply to print
     * @return int Exit code the printer answers with
     */
    public function printRefused(CommandReplyDTO $reply): int
    {
        return $this->printRefusal($reply);
    }

    /**
     * Records what would have gone to stderr, which no output buffer here can read back.
     *
     * @param string $text Sentence the printer wrote
     * @return int Exit code to return from the command
     */
    protected function printToStandardError(string $text): int
    {
        $this->written[] = $text;

        return ExitCode::ERROR;
    }
}
