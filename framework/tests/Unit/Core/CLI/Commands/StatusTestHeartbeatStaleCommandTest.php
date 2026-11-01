<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\StatusTestHeartbeatStaleCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use Hilos\Status\StatusPublicFiles;
use Hilos\Status\StatusTestCommandConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CLI half of test:status:heartbeat:stale (HIL-1856).
 *
 * Pinned is what goes on the wire and what the owner's answer turns into on the terminal; the
 * aged moment and the hold are the owner's, pinned in StatusPublicFilesTest and the integration test.
 */
final class StatusTestHeartbeatStaleCommandTest extends TestCase
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
        $command = new StatusTestHeartbeatStaleCommand();

        self::assertSame(CliCommands::STATUS_TEST_HEARTBEAT_STALE, $command->getName());
        self::assertInstanceOf(TestOnlyCommand::class, $command);
        self::assertTrue(TestOnlyCommandRegistry::isTestOnly(CliCommands::STATUS_TEST_HEARTBEAT_STALE));
    }

    public function testAnArgumentIsRefusedBeforeAnythingIsSent(): void
    {
        $command = new StatusTestHeartbeatStaleCommandSpy();

        $this->expectOutputString("Usage: test:status:heartbeat:stale\n");
        self::assertSame(ExitCode::INVALID_ARGUMENT, $command->execute([], ['now']));
        self::assertNull($command->sentCommand);
    }

    public function testSendsAnEmptyRequestAndPrintsThePublishedMoment(): void
    {
        $command = new StatusTestHeartbeatStaleCommandSpy();
        $command->reply = CommandReplyDTO::ok('cid', [StatusTestCommandConstants::FIELD_ALIVE_AT => 1761899879000]);

        $this->expectOutputString("Aged the Status heartbeat: alive at epoch ms 1761899879000\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], []));
        self::assertSame(CliCommands::STATUS_TEST_HEARTBEAT_STALE, $command->sentCommand);
        self::assertSame([], $command->sentPayload);
    }

    public function testAnOwnerRefusalIsRelayedAsAFailure(): void
    {
        $command = new StatusTestHeartbeatStaleCommandSpy();
        $command->reply = CommandReplyDTO::error('cid', StatusPublicFiles::NO_PUBLIC_PAIR);

        self::assertSame(ExitCode::ERROR, $command->execute([], []));
        self::assertSame('Refused: No public Status pair is published yet.', $command->standardError);
    }

    public function testAReplyWithoutTheMomentIsAFailure(): void
    {
        $command = new StatusTestHeartbeatStaleCommandSpy();
        $command->reply = CommandReplyDTO::ok('cid', [StatusTestCommandConstants::FIELD_ALIVE_AT => '1761899879000']);

        $this->expectOutputString("Command failed: the reply carries no heartbeat moment\n");
        self::assertSame(ExitCode::ERROR, $command->execute([], []));
    }

    public function testAChannelFailureIsPrintedToStandardError(): void
    {
        $command = new StatusTestHeartbeatStaleCommandSpy();
        $command->channelResult = CommandChannelResult::timedOut('127.0.0.1:8094');

        self::assertSame(ExitCode::ERROR, $command->execute([], []));
        self::assertStringContainsString('The daemon did not answer test:status:heartbeat:stale within', $command->standardError);
    }
}

/** The command with its one outward call stood in: the request is captured and a canned reply comes back. */
final class StatusTestHeartbeatStaleCommandSpy extends StatusTestHeartbeatStaleCommand
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
