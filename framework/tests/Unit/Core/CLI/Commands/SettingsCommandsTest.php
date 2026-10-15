<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\SettingsResetCommand;
use Hilos\Core\CLI\Commands\SettingsSetCommand;
use Hilos\Database\Settings\SettingsCommandConstants;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Pins CLI behavior and payload contracts for settings:set and settings:reset (HIL-1514).
 */
final class SettingsCommandsTest extends TestCase
{
    private const string ADDRESS = '127.0.0.1:8094';

    private const string CORRELATION_ID = 'unit-correlation';

    public function testSetArgumentsMissingReturnsInvalidArgument(): void
    {
        $command = new SettingsSetCommandDouble([]);

        $this->expectOutputString("Usage: settings:set <key> <value>\n");
        self::assertSame(ExitCode::INVALID_ARGUMENT, $command->execute([], []));
        self::assertSame([], $command->calls);
    }

    public function testSetSingleArgumentReturnsInvalidArgument(): void
    {
        $command = new SettingsSetCommandDouble([]);

        $this->expectOutputString("Usage: settings:set <key> <value>\n");
        self::assertSame(ExitCode::INVALID_ARGUMENT, $command->execute([], ['example_integer']));
        self::assertSame([], $command->calls);
    }

    public function testSetEmptyKeyReturnsInvalidArgument(): void
    {
        $command = new SettingsSetCommandDouble([]);

        $this->expectOutputString("Usage: settings:set <key> <value>\n");
        self::assertSame(ExitCode::INVALID_ARGUMENT, $command->execute([], ['', 'val']));
        self::assertSame([], $command->calls);
    }

    public function testSetSendsKeyAndValuePayloadAndPrintsSuccessOnOkReply(): void
    {
        $command = new SettingsSetCommandDouble([
            CommandChannelResult::replied(
                CommandReplyDTO::ok(self::CORRELATION_ID, []),
                self::ADDRESS,
            ),
        ]);

        $this->expectOutputString("Reply (ok): example_integer = 30\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], ['example_integer', '30']));
        self::assertSame([[
            CliCommands::SETTINGS_SET,
            [
                SettingsCommandConstants::FIELD_KEY => 'example_integer',
                SettingsCommandConstants::FIELD_VALUE => '30',
            ],
            null,
        ]], $command->calls);
        self::assertSame([], $command->standardError);
    }

    public function testSetAllowsEmptyStringValue(): void
    {
        $command = new SettingsSetCommandDouble([
            CommandChannelResult::replied(
                CommandReplyDTO::ok(self::CORRELATION_ID, []),
                self::ADDRESS,
            ),
        ]);

        $this->expectOutputString("Reply (ok): example_string = \n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], ['example_string', '']));
        self::assertSame([[
            CliCommands::SETTINGS_SET,
            [
                SettingsCommandConstants::FIELD_KEY => 'example_string',
                SettingsCommandConstants::FIELD_VALUE => '',
            ],
            null,
        ]], $command->calls);
        self::assertSame([], $command->standardError);
    }

    public function testSetRefusalPrintsErrorAndReturnsErrorExitCode(): void
    {
        $command = new SettingsSetCommandDouble([
            CommandChannelResult::replied(
                CommandReplyDTO::error(self::CORRELATION_ID, "Setting key 'unknown' is not in catalog"),
                self::ADDRESS,
            ),
        ]);

        self::assertSame(ExitCode::ERROR, $command->execute([], ['unknown', 'val']));
        self::assertSame([[
            CliCommands::SETTINGS_SET,
            [
                SettingsCommandConstants::FIELD_KEY => 'unknown',
                SettingsCommandConstants::FIELD_VALUE => 'val',
            ],
            null,
        ]], $command->calls);
        self::assertSame(["Refused: Setting key 'unknown' is not in catalog"], $command->standardError);
    }

    public function testResetArgumentsMissingReturnsInvalidArgument(): void
    {
        $command = new SettingsResetCommandDouble([]);

        $this->expectOutputString("Usage: settings:reset <key>\n");
        self::assertSame(ExitCode::INVALID_ARGUMENT, $command->execute([], []));
        self::assertSame([], $command->calls);
    }

    public function testResetEmptyKeyReturnsInvalidArgument(): void
    {
        $command = new SettingsResetCommandDouble([]);

        $this->expectOutputString("Usage: settings:reset <key>\n");
        self::assertSame(ExitCode::INVALID_ARGUMENT, $command->execute([], ['']));
        self::assertSame([], $command->calls);
    }

    public function testResetSendsKeyPayloadAndPrintsSuccessOnOkReply(): void
    {
        $command = new SettingsResetCommandDouble([
            CommandChannelResult::replied(
                CommandReplyDTO::ok(self::CORRELATION_ID, []),
                self::ADDRESS,
            ),
        ]);

        $this->expectOutputString("Reply (ok): example_integer is back to its catalog default\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], ['example_integer']));
        self::assertSame([[
            CliCommands::SETTINGS_RESET,
            [
                SettingsCommandConstants::FIELD_KEY => 'example_integer',
            ],
            null,
        ]], $command->calls);
        self::assertSame([], $command->standardError);
    }

    public function testResetRefusalPrintsErrorAndReturnsErrorExitCode(): void
    {
        $command = new SettingsResetCommandDouble([
            CommandChannelResult::replied(
                CommandReplyDTO::error(self::CORRELATION_ID, "Setting key 'unknown' is not in catalog"),
                self::ADDRESS,
            ),
        ]);

        self::assertSame(ExitCode::ERROR, $command->execute([], ['unknown']));
        self::assertSame([[
            CliCommands::SETTINGS_RESET,
            [
                SettingsCommandConstants::FIELD_KEY => 'unknown',
            ],
            null,
        ]], $command->calls);
        self::assertSame(["Refused: Setting key 'unknown' is not in catalog"], $command->standardError);
    }

    public function testChannelFailurePrintsToStandardError(): void
    {
        $command = new SettingsSetCommandDouble([
            CommandChannelResult::timedOut(self::ADDRESS),
        ]);

        self::assertSame(ExitCode::ERROR, $command->execute([], ['example_integer', '10']));
        self::assertCount(1, $command->standardError);
        self::assertStringContainsString('The daemon did not answer settings:set within', $command->standardError[0]);
    }
}

/**
 * Common double behavior for settings CLI command tests.
 */
trait SettingsCommandDoubleTrait
{
    /** @var list<array{0: string, 1: array<string, mixed>, 2: ?float}> Command calls */
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
        $this->calls[] = [$command, $payload, $waitSeconds];

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
 * Test double for SettingsSetCommand.
 */
final class SettingsSetCommandDouble extends SettingsSetCommand
{
    use SettingsCommandDoubleTrait;
}

/**
 * Test double for SettingsResetCommand.
 */
final class SettingsResetCommandDouble extends SettingsResetCommand
{
    use SettingsCommandDoubleTrait;
}
