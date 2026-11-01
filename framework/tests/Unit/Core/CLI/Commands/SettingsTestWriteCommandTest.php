<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\SettingsTestWriteCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Database\Settings\SettingsCommandConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CLI half of test:settings:write (HIL-1856).
 *
 * What is pinned is what the operator's arguments turn into on the wire and what the library's
 * answer turns into on the terminal. Which keys are let in and how many pairs a batch carries are
 * the library's to judge, and are pinned in SettingsLibraryAgentTest.
 *
 * Runs under a non-production APP_ENV so the {@see TestOnlyCommand} guard admits the body.
 */
final class SettingsTestWriteCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    /** @var string|false APP_ENV the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousAppEnv = getenv('APP_ENV');
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
    }

    public function testNameAndTestOnlyDeclarationAgree(): void
    {
        $command = new SettingsTestWriteCommand();

        self::assertSame(CliCommands::SETTINGS_TEST_WRITE, $command->getName());
        self::assertInstanceOf(TestOnlyCommand::class, $command);
        self::assertTrue(TestOnlyCommandRegistry::isTestOnly(CliCommands::SETTINGS_TEST_WRITE));
    }

    /**
     * @return array<string, array{0: list<string>}> Arguments that carry no batch to send
     */
    public static function unreadableArguments(): array
    {
        return [
            'no pair' => [[]],
            'no equals sign' => [['example_string']],
            'no key before the equals sign' => [['=delivery-1']],
            'one good pair beside one without a key' => [['example_string=delivery-1', '=2']],
        ];
    }

    /**
     * @param list<string> $args Arguments as the operator typed them
     */
    #[DataProvider('unreadableArguments')]
    public function testArgumentsWithoutAPairAreRefusedBeforeAnythingIsSent(array $args): void
    {
        $command = new SettingsTestWriteCommandSpy();

        $this->expectOutputString("Usage: test:settings:write <key>=<value> [<key>=<value> ...]\n");
        self::assertSame(ExitCode::INVALID_ARGUMENT, $command->execute([], $args));
        self::assertNull($command->sentCommand);
    }

    public function testEachArgumentIsSplitAtItsFirstEqualsSignAndSentInOrder(): void
    {
        $command = new SettingsTestWriteCommandSpy();
        $command->reply = CommandReplyDTO::ok('cid', [SettingsCommandConstants::FIELD_WRITES => [[
            SettingsCommandConstants::FIELD_KEY => 'example_string',
            SettingsCommandConstants::FIELD_VALUE => 'a=b',
            SettingsCommandConstants::FIELD_ACCEPTED_AT => 1,
        ]]]);

        $this->expectOutputRegex('/^Wrote /');
        self::assertSame(
            ExitCode::SUCCESS,
            $command->execute([], ['example_string=a=b', 'example_integer=2', 'example_boolean=']),
        );
        self::assertSame(CliCommands::SETTINGS_TEST_WRITE, $command->sentCommand);
        self::assertSame([SettingsCommandConstants::FIELD_WRITES => [
            [SettingsCommandConstants::FIELD_KEY => 'example_string', SettingsCommandConstants::FIELD_VALUE => 'a=b'],
            [SettingsCommandConstants::FIELD_KEY => 'example_integer', SettingsCommandConstants::FIELD_VALUE => '2'],
            [SettingsCommandConstants::FIELD_KEY => 'example_boolean', SettingsCommandConstants::FIELD_VALUE => ''],
        ]], $command->sentPayload);
    }

    public function testEveryAcceptedWriteIsPrintedWithItsMomentInTheOrderAnswered(): void
    {
        $command = new SettingsTestWriteCommandSpy();
        $command->reply = CommandReplyDTO::ok('cid', [SettingsCommandConstants::FIELD_WRITES => [
            [
                SettingsCommandConstants::FIELD_KEY => 'example_string',
                SettingsCommandConstants::FIELD_VALUE => 'delivery-1',
                SettingsCommandConstants::FIELD_ACCEPTED_AT => 1761900000000,
            ],
            [
                SettingsCommandConstants::FIELD_KEY => 'example_integer',
                SettingsCommandConstants::FIELD_VALUE => '2',
                SettingsCommandConstants::FIELD_ACCEPTED_AT => 1761900000004,
            ],
        ]]);

        $this->expectOutputString(
            "Wrote example_string = delivery-1, accepted at epoch ms 1761900000000\n"
            . "Wrote example_integer = 2, accepted at epoch ms 1761900000004\n",
        );
        self::assertSame(ExitCode::SUCCESS, $command->execute([], ['example_string=delivery-1', 'example_integer=2']));
    }

    public function testALibraryRefusalIsRelayedAsAFailure(): void
    {
        $command = new SettingsTestWriteCommandSpy();
        $command->reply = CommandReplyDTO::error(
            'cid',
            "Write 2 was refused: Setting 'example_integer' takes a whole number; 'many' is not one",
        );

        self::assertSame(ExitCode::ERROR, $command->execute([], ['example_string=kept', 'example_integer=many']));
        self::assertSame(
            "Refused: Write 2 was refused: Setting 'example_integer' takes a whole number; 'many' is not one",
            $command->standardError,
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}> Reply payloads that do not say what was written
     */
    public static function unreadableReplies(): array
    {
        return [
            'no writes' => [[]],
            'empty writes' => [[SettingsCommandConstants::FIELD_WRITES => []]],
            'a write without its moment' => [[SettingsCommandConstants::FIELD_WRITES => [[
                SettingsCommandConstants::FIELD_KEY => 'example_string',
                SettingsCommandConstants::FIELD_VALUE => 'delivery-1',
            ]]]],
        ];
    }

    /**
     * @param array<string, mixed> $payload Reply payload as the library answered it
     */
    #[DataProvider('unreadableReplies')]
    public function testAReplyThatDoesNotSayWhatWasWrittenIsAFailure(array $payload): void
    {
        $command = new SettingsTestWriteCommandSpy();
        $command->reply = CommandReplyDTO::ok('cid', $payload);

        $this->expectOutputRegex('/^Command failed: the reply carries /');
        self::assertSame(ExitCode::ERROR, $command->execute([], ['example_string=delivery-1']));
    }

    public function testAChannelFailureIsPrintedToStandardError(): void
    {
        $command = new SettingsTestWriteCommandSpy();
        $command->channelResult = CommandChannelResult::timedOut('127.0.0.1:8094');

        self::assertSame(ExitCode::ERROR, $command->execute([], ['example_string=delivery-1']));
        self::assertStringContainsString('The daemon did not answer test:settings:write within', $command->standardError);
    }
}

/**
 * The command with its one outward call stood in: what it would have put on the wire is captured
 * instead, and a canned reply comes back.
 */
final class SettingsTestWriteCommandSpy extends SettingsTestWriteCommand
{
    /** @var ?string Wire name the command sent, or null when it refused before sending */
    public ?string $sentCommand = null;

    /** @var ?array<string, mixed> Payload the command sent, or null when it refused before sending */
    public ?array $sentPayload = null;

    /** @var ?CommandReplyDTO Reply handed back to the command */
    public ?CommandReplyDTO $reply = null;

    /** @var ?CommandChannelResult Round-trip handed back instead of the reply, when a case needs no reply at all */
    public ?CommandChannelResult $channelResult = null;

    /** @var string What the command wrote to stderr, kept here instead of the test run's own stream */
    public string $standardError = '';

    /**
     * Captures the round-trip instead of opening a socket.
     *
     * @param string $command Command-channel wire name
     * @param array<string, mixed> $payload Request payload
     * @param ?float $waitSeconds Wait budget (ignored)
     * @return CommandChannelResult Canned round-trip
     */
    protected function sendCommand(
        string $command,
        array $payload,
        ?float $waitSeconds = null,
    ): CommandChannelResult {
        $this->sentCommand = $command;
        $this->sentPayload = $payload;

        return $this->channelResult
            ?? CommandChannelResult::replied($this->reply ?? CommandReplyDTO::ok('cid', []), '127.0.0.1:8094');
    }

    /**
     * Keeps a refusal instead of writing it to the test run's stderr.
     *
     * @param string $text Sentence the command wrote
     */
    protected function writeToStandardError(string $text): void
    {
        $this->standardError .= $text;
    }
}
