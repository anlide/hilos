<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\StatusTestWorkStartCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use Hilos\Status\StatusTestCommandConstants;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CLI half of test:status:work:start (HIL-1856).
 *
 * Pinned is what the operator's argument turns into on the wire and what the owner's answer turns
 * into on the terminal; which works may be started is the owner's, pinned in the integration test.
 */
final class StatusTestWorkStartCommandTest extends TestCase
{
    private const string PUBLIC_ID = '0123456789abcdef0123456789abcdef';

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
        $command = new StatusTestWorkStartCommand();

        self::assertSame(CliCommands::STATUS_TEST_WORK_START, $command->getName());
        self::assertInstanceOf(TestOnlyCommand::class, $command);
        self::assertTrue(TestOnlyCommandRegistry::isTestOnly(CliCommands::STATUS_TEST_WORK_START));
    }

    /**
     * @return array<string, array{0: list<string>}> Arguments that name no single work
     */
    public static function unreadableArguments(): array
    {
        return [
            'no public id' => [[]],
            'a blank public id' => [['  ']],
            'two public ids' => [[self::PUBLIC_ID, self::PUBLIC_ID]],
        ];
    }

    /**
     * @param list<string> $args Arguments as the operator typed them
     */
    #[DataProvider('unreadableArguments')]
    public function testArgumentsWithoutOnePublicIdAreRefusedBeforeAnythingIsSent(array $args): void
    {
        $command = new StatusTestWorkStartCommandSpy();

        $this->expectOutputString("Usage: test:status:work:start <publicId>\n");
        self::assertSame(ExitCode::INVALID_ARGUMENT, $command->execute([], $args));
        self::assertNull($command->sentCommand);
    }

    public function testSendsThePublicIdAndPrintsTheNewStartAndVersion(): void
    {
        $command = new StatusTestWorkStartCommandSpy();
        $command->reply = CommandReplyDTO::ok('cid', [
            StatusTestCommandConstants::FIELD_PUBLIC_ID => self::PUBLIC_ID,
            StatusTestCommandConstants::FIELD_STARTS_AT => 1761900000000,
            StatusTestCommandConstants::FIELD_VERSION => 2,
        ]);

        $this->expectOutputString(
            'Started maintenance announcement ' . self::PUBLIC_ID . ": starts at epoch ms 1761900000000, version 2\n",
        );
        self::assertSame(ExitCode::SUCCESS, $command->execute([], [self::PUBLIC_ID]));
        self::assertSame(CliCommands::STATUS_TEST_WORK_START, $command->sentCommand);
        self::assertSame([StatusTestCommandConstants::FIELD_PUBLIC_ID => self::PUBLIC_ID], $command->sentPayload);
    }

    public function testAnOwnerRefusalIsRelayedAsAFailure(): void
    {
        $command = new StatusTestWorkStartCommandSpy();
        $command->reply = CommandReplyDTO::error('cid', 'This maintenance announcement no longer exists. Refresh the list.');

        self::assertSame(ExitCode::ERROR, $command->execute([], [self::PUBLIC_ID]));
        self::assertSame('Refused: This maintenance announcement no longer exists. Refresh the list.', $command->standardError);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}> Reply payloads that do not say what was moved
     */
    public static function unreadableReplies(): array
    {
        return [
            'nothing' => [[]],
            'no version' => [[
                StatusTestCommandConstants::FIELD_PUBLIC_ID => self::PUBLIC_ID,
                StatusTestCommandConstants::FIELD_STARTS_AT => 1761900000000,
            ]],
            'a start that is not a number' => [[
                StatusTestCommandConstants::FIELD_PUBLIC_ID => self::PUBLIC_ID,
                StatusTestCommandConstants::FIELD_STARTS_AT => 'now',
                StatusTestCommandConstants::FIELD_VERSION => 2,
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $payload Reply payload as the owner answered it
     */
    #[DataProvider('unreadableReplies')]
    public function testAReplyThatDoesNotSayWhatWasMovedIsAFailure(array $payload): void
    {
        $command = new StatusTestWorkStartCommandSpy();
        $command->reply = CommandReplyDTO::ok('cid', $payload);

        $this->expectOutputString("Command failed: the reply carries no public id, start or version\n");
        self::assertSame(ExitCode::ERROR, $command->execute([], [self::PUBLIC_ID]));
    }

    public function testAChannelFailureIsPrintedToStandardError(): void
    {
        $command = new StatusTestWorkStartCommandSpy();
        $command->channelResult = CommandChannelResult::timedOut('127.0.0.1:8094');

        self::assertSame(ExitCode::ERROR, $command->execute([], [self::PUBLIC_ID]));
        self::assertStringContainsString('The daemon did not answer test:status:work:start within', $command->standardError);
    }
}

/** The command with its one outward call stood in: the request is captured and a canned reply comes back. */
final class StatusTestWorkStartCommandSpy extends StatusTestWorkStartCommand
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
    protected function sendCommand(string $command, array $payload, ?float $waitSeconds = null): CommandChannelResult
    {
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
