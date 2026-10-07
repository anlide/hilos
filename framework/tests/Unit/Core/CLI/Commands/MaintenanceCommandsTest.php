<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandChannelWindows;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\MaintenanceDisableCommand;
use Hilos\Core\CLI\Commands\MaintenanceEnableCommand;
use Hilos\Core\CLI\Commands\MaintenancePassCommand;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Pins CLI behavior and re-ask logic for the manual maintenance commands.
 */
final class MaintenanceCommandsTest extends TestCase
{
    private const string ADDRESS = '127.0.0.1:8094';

    private const string CORRELATION_ID = 'unit-correlation';

    public function testEnableReplyInTimePrintsSuccessWithoutReAsk(): void
    {
        $command = new MaintenanceEnableCommandDouble([
            $this->reply([
                ProtectedModeCommandConstants::FIELD_PHASE => ProtectedModeRuntime::PHASE_VERIFYING,
            ]),
        ]);

        $this->expectOutputString(
            "Manual maintenance is on: visitors see the maintenance screen (phase: verifying)\n"
            . "Let someone in with maintenance:pass; open the system again with maintenance:disable.\n"
        );
        self::assertSame(ExitCode::SUCCESS, $command->execute([], []));
        self::assertSame([[CliCommands::MAINTENANCE_ENABLE, null]], $command->calls);
    }

    public function testEnableRefusalPrintsError(): void
    {
        $command = new MaintenanceEnableCommandDouble([
            CommandChannelResult::replied(
                CommandReplyDTO::error(self::CORRELATION_ID, 'another protected-mode request is still in flight'),
                self::ADDRESS,
            ),
        ]);

        self::assertSame(ExitCode::ERROR, $command->execute([], []));
        self::assertSame([[CliCommands::MAINTENANCE_ENABLE, null]], $command->calls);
        self::assertSame(['Refused: another protected-mode request is still in flight'], $command->standardError);
    }

    public function testEnableLostReplyReAskTakenPrintsSuccess(): void
    {
        $command = new MaintenanceEnableCommandDouble([
            CommandChannelResult::timedOut(self::ADDRESS),
            $this->inspectReply(
                ProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE,
                ProtectedModeRuntime::PHASE_VERIFYING,
            ),
        ]);

        $this->expectOutputString(
            "Manual maintenance is on: visitors see the maintenance screen (phase: verifying)\n"
            . "Let someone in with maintenance:pass; open the system again with maintenance:disable.\n"
        );
        self::assertSame(ExitCode::SUCCESS, $command->execute([], []));
        self::assertSame([
            [CliCommands::MAINTENANCE_ENABLE, null],
            [CliCommands::PROTECTED_MODE_INSPECT, CommandChannelWindows::RE_ASK_WAIT_SECONDS],
        ], $command->calls);
        self::assertSame([
            'The daemon did not answer maintenance:enable within 15s; asked the node for its state instead',
        ], $command->standardError);
    }

    public function testEnableLostReplyReAskNotTakenWrongPhasePrintsNotClosed(): void
    {
        $command = new MaintenanceEnableCommandDouble([
            CommandChannelResult::timedOut(self::ADDRESS),
            $this->inspectReply(
                ProtectedModeRuntime::OPERATION_MANUAL_MAINTENANCE,
                ProtectedModeRuntime::PHASE_ACTIVE,
            ),
        ]);

        self::assertSame(ExitCode::ERROR, $command->execute([], []));
        self::assertSame([
            [CliCommands::MAINTENANCE_ENABLE, null],
            [CliCommands::PROTECTED_MODE_INSPECT, CommandChannelWindows::RE_ASK_WAIT_SECONDS],
        ], $command->calls);
        self::assertSame([
            "The daemon did not answer maintenance:enable within 15s; the node reads phase 'active', so the system is NOT closed to visitors",
        ], $command->standardError);
    }

    public function testEnableLostReplyReAskForeignOperationPrintsNotClosed(): void
    {
        $command = new MaintenanceEnableCommandDouble([
            CommandChannelResult::timedOut(self::ADDRESS),
            $this->inspectReply('restore', ProtectedModeRuntime::PHASE_VERIFYING),
        ]);

        self::assertSame(ExitCode::ERROR, $command->execute([], []));
        self::assertSame([
            [CliCommands::MAINTENANCE_ENABLE, null],
            [CliCommands::PROTECTED_MODE_INSPECT, CommandChannelWindows::RE_ASK_WAIT_SECONDS],
        ], $command->calls);
        self::assertSame([
            "The daemon did not answer maintenance:enable within 15s; the node reads phase 'verifying', so the system is NOT closed to visitors",
        ], $command->standardError);
    }

    public function testEnableLostReplyReAskUnknownPrintsUnknown(): void
    {
        $command = new MaintenanceEnableCommandDouble([
            CommandChannelResult::timedOut(self::ADDRESS),
            CommandChannelResult::timedOut(self::ADDRESS),
        ]);

        self::assertSame(ExitCode::ERROR, $command->execute([], []));
        self::assertSame([
            [CliCommands::MAINTENANCE_ENABLE, null],
            [CliCommands::PROTECTED_MODE_INSPECT, CommandChannelWindows::RE_ASK_WAIT_SECONDS],
        ], $command->calls);
        self::assertSame([
            'The daemon did not answer maintenance:enable within 15s, and did not answer '
            . 'protected-mode:inspect either; whether the system closed to visitors is unknown',
        ], $command->standardError);
    }

    public function testDisableReplyInTimePrintsSuccessWithoutReAsk(): void
    {
        $command = new MaintenanceDisableCommandDouble([
            $this->reply([
                ProtectedModeCommandConstants::FIELD_PHASE => ProtectedModeRuntime::PHASE_INACTIVE,
            ]),
        ]);

        $this->expectOutputString("Manual maintenance is off, the system is open (phase: inactive)\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], []));
        self::assertSame([[CliCommands::MAINTENANCE_DISABLE, null]], $command->calls);
    }

    public function testDisableRefusalPrintsError(): void
    {
        $command = new MaintenanceDisableCommandDouble([
            CommandChannelResult::replied(
                CommandReplyDTO::error(self::CORRELATION_ID, 'mode is not active'),
                self::ADDRESS,
            ),
        ]);

        self::assertSame(ExitCode::ERROR, $command->execute([], []));
        self::assertSame([[CliCommands::MAINTENANCE_DISABLE, null]], $command->calls);
        self::assertSame(['Refused: mode is not active'], $command->standardError);
    }

    public function testDisableLostReplyReAskTakenPrintsSuccess(): void
    {
        $command = new MaintenanceDisableCommandDouble([
            CommandChannelResult::timedOut(self::ADDRESS),
            $this->inspectReply(null, ProtectedModeRuntime::PHASE_INACTIVE),
        ]);

        $this->expectOutputString("Manual maintenance is off, the system is open (phase: inactive)\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], []));
        self::assertSame([
            [CliCommands::MAINTENANCE_DISABLE, null],
            [CliCommands::PROTECTED_MODE_INSPECT, CommandChannelWindows::RE_ASK_WAIT_SECONDS],
        ], $command->calls);
        self::assertSame([
            'The daemon did not answer maintenance:disable within 15s; asked the node for its state instead',
        ], $command->standardError);
    }

    public function testDisableLostReplyReAskNotTakenPrintsNotOpen(): void
    {
        $command = new MaintenanceDisableCommandDouble([
            CommandChannelResult::timedOut(self::ADDRESS),
            $this->inspectReply(null, ProtectedModeRuntime::PHASE_VERIFYING),
        ]);

        self::assertSame(ExitCode::ERROR, $command->execute([], []));
        self::assertSame([
            [CliCommands::MAINTENANCE_DISABLE, null],
            [CliCommands::PROTECTED_MODE_INSPECT, CommandChannelWindows::RE_ASK_WAIT_SECONDS],
        ], $command->calls);
        self::assertSame([
            "The daemon did not answer maintenance:disable within 15s; the node reads phase 'verifying', so the system is NOT open",
        ], $command->standardError);
    }

    public function testDisableLostReplyReAskUnknownPrintsUnknown(): void
    {
        $command = new MaintenanceDisableCommandDouble([
            CommandChannelResult::timedOut(self::ADDRESS),
            CommandChannelResult::timedOut(self::ADDRESS),
        ]);

        self::assertSame(ExitCode::ERROR, $command->execute([], []));
        self::assertSame([
            [CliCommands::MAINTENANCE_DISABLE, null],
            [CliCommands::PROTECTED_MODE_INSPECT, CommandChannelWindows::RE_ASK_WAIT_SECONDS],
        ], $command->calls);
        self::assertSame([
            'The daemon did not answer maintenance:disable within 15s, and did not answer '
            . 'protected-mode:inspect either; whether the system opened is unknown',
        ], $command->standardError);
    }

    public function testPassReplyInTimePrintsSuccessWithoutReAsk(): void
    {
        $command = new MaintenancePassCommandDouble([
            $this->reply([
                ProtectedModeCommandConstants::FIELD_PASS => 'pass-secret-123',
            ]),
        ]);

        $this->expectOutputString(
            "Pass: pass-secret-123\n"
            . "Give it to the person to let in: they enter it on the maintenance screen.\n"
        );
        self::assertSame(ExitCode::SUCCESS, $command->execute([], []));
        self::assertSame([[CliCommands::MAINTENANCE_PASS, null]], $command->calls);
    }

    public function testPassReplyInTimeMissingPassKeyPrintsError(): void
    {
        $command = new MaintenancePassCommandDouble([
            $this->reply([]),
        ]);

        $this->expectOutputString("The agent recorded a pass but returned no key\n");
        self::assertSame(ExitCode::ERROR, $command->execute([], []));
        self::assertSame([[CliCommands::MAINTENANCE_PASS, null]], $command->calls);
    }

    public function testPassRefusalPrintsError(): void
    {
        $command = new MaintenancePassCommandDouble([
            CommandChannelResult::replied(
                CommandReplyDTO::error(self::CORRELATION_ID, 'mode is not in verification window'),
                self::ADDRESS,
            ),
        ]);

        self::assertSame(ExitCode::ERROR, $command->execute([], []));
        self::assertSame([[CliCommands::MAINTENANCE_PASS, null]], $command->calls);
        self::assertSame(['Refused: mode is not in verification window'], $command->standardError);
    }

    public function testPassLostReplyReAskUnknownPrintsUnknown(): void
    {
        $command = new MaintenancePassCommandDouble([
            CommandChannelResult::timedOut(self::ADDRESS),
            CommandChannelResult::timedOut(self::ADDRESS),
        ]);

        self::assertSame(ExitCode::ERROR, $command->execute([], []));
        self::assertSame([
            [CliCommands::MAINTENANCE_PASS, null],
            [CliCommands::PROTECTED_MODE_INSPECT, CommandChannelWindows::RE_ASK_WAIT_SECONDS],
        ], $command->calls);
        self::assertSame([
            'The daemon did not answer maintenance:pass within 15s, and did not answer '
            . 'protected-mode:inspect either; whether a pass was minted is unknown',
        ], $command->standardError);
    }

    public function testPassLostReplyReAskNoPassCountPrintsUnknown(): void
    {
        $command = new MaintenancePassCommandDouble([
            CommandChannelResult::timedOut(self::ADDRESS),
            $this->inspectReply(null, ProtectedModeRuntime::PHASE_VERIFYING),
        ]);

        self::assertSame(ExitCode::ERROR, $command->execute([], []));
        self::assertSame([
            [CliCommands::MAINTENANCE_PASS, null],
            [CliCommands::PROTECTED_MODE_INSPECT, CommandChannelWindows::RE_ASK_WAIT_SECONDS],
        ], $command->calls);
        self::assertSame([
            'The daemon did not answer maintenance:pass within 15s; the state reply carried no pass count, '
            . 'so whether a pass was minted is unknown',
        ], $command->standardError);
    }

    public function testPassLostReplyReAskWithPassCountAdvisesMintAnother(): void
    {
        $command = new MaintenancePassCommandDouble([
            CommandChannelResult::timedOut(self::ADDRESS),
            $this->inspectReply(null, ProtectedModeRuntime::PHASE_VERIFYING, 4),
        ]);

        self::assertSame(ExitCode::ERROR, $command->execute([], []));
        self::assertSame([
            [CliCommands::MAINTENANCE_PASS, null],
            [CliCommands::PROTECTED_MODE_INSPECT, CommandChannelWindows::RE_ASK_WAIT_SECONDS],
        ], $command->calls);
        self::assertSame([
            'The daemon did not answer maintenance:pass within 15s; the node now holds 4 passes, '
            . 'but a pass exists only in the reply that was lost - mint another with maintenance:pass',
        ], $command->standardError);
    }

    /**
     * @param array<string, mixed> $payload Reply payload
     * @return CommandChannelResult Successful command-channel result
     */
    private function reply(array $payload): CommandChannelResult
    {
        return CommandChannelResult::replied(
            CommandReplyDTO::ok(self::CORRELATION_ID, $payload),
            self::ADDRESS,
        );
    }

    /**
     * @param ?string $operation Operation name
     * @param string $phase Phase string
     * @param ?int $passCount Pass count
     * @return CommandChannelResult Successful inspect command-channel result
     */
    private function inspectReply(?string $operation, string $phase, ?int $passCount = null): CommandChannelResult
    {
        $payload = [
            ProtectedModeCommandConstants::FIELD_RT_MOUNTED => true,
            ProtectedModeCommandConstants::FIELD_OPERATION => $operation,
            ProtectedModeCommandConstants::FIELD_PHASE => $phase,
        ];
        if ($passCount !== null) {
            $payload[ProtectedModeCommandConstants::FIELD_PASS_COUNT] = $passCount;
        }

        return $this->reply($payload);
    }
}

/**
 * Common double implementation for testing maintenance commands without real sockets.
 */
trait MaintenanceCommandDoubleTrait
{
    /** @var list<array{0: string, 1: ?float}> Command names and wait budgets, in order */
    public array $calls = [];

    /** @var list<string> Sentences written to stderr */
    public array $standardError = [];

    /** @var list<CommandChannelResult> Round-trip results to return, in order */
    private array $results;

    /**
     * @param list<CommandChannelResult> $results Round-trip results to return, in order
     */
    public function __construct(array $results)
    {
        $this->results = $results;
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

/**
 * Replaces the socket round trips and stderr writer of MaintenanceEnableCommand.
 */
final class MaintenanceEnableCommandDouble extends MaintenanceEnableCommand
{
    use MaintenanceCommandDoubleTrait;
}

/**
 * Replaces the socket round trips and stderr writer of MaintenanceDisableCommand.
 */
final class MaintenanceDisableCommandDouble extends MaintenanceDisableCommand
{
    use MaintenanceCommandDoubleTrait;
}

/**
 * Replaces the socket round trips and stderr writer of MaintenancePassCommand.
 */
final class MaintenancePassCommandDouble extends MaintenancePassCommand
{
    use MaintenanceCommandDoubleTrait;
}
