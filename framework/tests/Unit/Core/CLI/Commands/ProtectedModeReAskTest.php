<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\ProtectedModeOpenCommand;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Pins the one re-ask a protected-mode operator command makes after a lost reply.
 */
final class ProtectedModeReAskTest extends TestCase
{
    private const string ADDRESS = '127.0.0.1:8094';

    private const string CORRELATION_ID = 'unit-correlation';

    public function testAReplyInTimeDoesNotTriggerAReAsk(): void
    {
        $command = new ProtectedModeOpenCommandDouble([
            $this->reply(ProtectedModeRuntime::PHASE_INACTIVE),
        ]);

        $this->expectOutputString("Protected mode lifted, the system is open (phase: inactive)\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], []));
        self::assertSame([
            [CliCommands::PROTECTED_MODE_OPEN, null],
        ], $command->calls);
    }

    public function testATimeoutTriggersExactlyOneShortInspect(): void
    {
        $command = new ProtectedModeOpenCommandDouble([
            CommandChannelResult::timedOut(self::ADDRESS),
            $this->reply(ProtectedModeRuntime::PHASE_INACTIVE),
        ]);

        $this->expectOutputString("Protected mode lifted, the system is open (phase: inactive)\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], []));
        self::assertSame([
            [CliCommands::PROTECTED_MODE_OPEN, null],
            [CliCommands::PROTECTED_MODE_INSPECT, CommandChannelWindows::RE_ASK_WAIT_SECONDS],
        ], $command->calls);
    }

    public function testAnUnreachableDriveAlsoTriggersOneInspect(): void
    {
        $command = new ProtectedModeOpenCommandDouble([
            CommandChannelResult::unreachable(self::ADDRESS),
            $this->reply(ProtectedModeRuntime::PHASE_ACTIVE),
        ]);

        self::assertSame(ExitCode::ERROR, $command->execute([], []));
        self::assertSame([
            [CliCommands::PROTECTED_MODE_OPEN, null],
            [CliCommands::PROTECTED_MODE_INSPECT, CommandChannelWindows::RE_ASK_WAIT_SECONDS],
        ], $command->calls);
    }

    public function testAReAskThatAlsoTimesOutReportsUnknown(): void
    {
        $command = new ProtectedModeOpenCommandDouble([
            CommandChannelResult::timedOut(self::ADDRESS),
            CommandChannelResult::timedOut(self::ADDRESS),
        ]);

        self::assertSame(ExitCode::ERROR, $command->execute([], []));
        self::assertSame([
            "The daemon did not answer protected-mode:open within 15s, and did not answer"
                . ' protected-mode:inspect either; whether the system opened is unknown',
        ], $command->standardError);
    }

    /**
     * @param string $phase Protected-mode phase in the reply
     * @return CommandChannelResult Successful command-channel result
     */
    private function reply(string $phase): CommandChannelResult
    {
        return CommandChannelResult::replied(
            CommandReplyDTO::ok(self::CORRELATION_ID, [
                ProtectedModeCommandConstants::FIELD_RT_MOUNTED => true,
                ProtectedModeCommandConstants::FIELD_OPERATION => null,
                ProtectedModeCommandConstants::FIELD_PHASE => $phase,
            ]),
            self::ADDRESS,
        );
    }
}

/**
 * Replaces the socket round trips and stderr writer of the operator command under test.
 */
final class ProtectedModeOpenCommandDouble extends ProtectedModeOpenCommand
{
    /** @var list<array{0: string, 1: ?float}> Command names and wait budgets, in order */
    public array $calls = [];

    /** @var list<string> Sentences written to stderr */
    public array $standardError = [];

    /**
     * @param list<CommandChannelResult> $results Round-trip results to return, in order
     */
    public function __construct(private array $results)
    {
    }

    /**
     * @param string $command Command-channel wire name
     * @param array<string, mixed> $payload Request payload
     * @param ?float $waitSeconds Optional wait budget
     * @return CommandChannelResult Next configured round-trip result
     */
    protected function sendCommand(
        string $command,
        array $payload,
        ?float $waitSeconds = null,
    ): CommandChannelResult {
        $this->calls[] = [$command, $waitSeconds];

        return array_shift($this->results) ?? throw new LogicException('Unexpected command-channel call');
    }

    /**
     * @param string $text Sentence that would be written to stderr
     */
    protected function writeToStandardError(string $text): void
    {
        $this->standardError[] = $text;
    }
}
