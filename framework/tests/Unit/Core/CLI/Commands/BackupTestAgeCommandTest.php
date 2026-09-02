<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupScope;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\BackupTestAgeCommand;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CLI half of test:backup:age after the rewrite moved into the agent (HIL-728).
 *
 * What is worth pinning is the seam between the two halves: which payload the operator's options
 * turn into, and that the backup agent declares the wire name that payload is sent under. Get
 * either wrong and the command fails only against a running stand, which is the slowest place to
 * learn it. The sidecar rewrite itself is the agent's and is covered by BackupSidecarRetimerTest.
 *
 * Runs under a non-production APP_ENV so the {@see TestOnlyCommand} guard admits the body.
 */
final class BackupTestAgeCommandTest extends TestCase
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

    public function testTheAgentDeclaresTheWireNameTheCliSendsUnder(): void
    {
        self::assertContains(CliCommands::BACKUP_TEST_AGE, BackupAgent::AGENT_COMMANDS);
        self::assertTrue(TestOnlyCommandRegistry::isTestOnly(CliCommands::BACKUP_TEST_AGE));
    }

    public function testDaysBecomeTheAgeDaysPayloadBesideTheBackupId(): void
    {
        $command = new BackupTestAgeCommandSpy();

        $this->expectOutputString("Aged backup 2026-07-19_10-30-00 createdAt=2026-06-09T10:30:00+00:00\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute(['days' => '40'], ['2026-07-19_10-30-00']));
        self::assertSame(CliCommands::BACKUP_TEST_AGE, $command->sentCommand);
        self::assertSame([
            BackupConstants::FIELD_AGE_DAYS => 40,
            BackupConstants::FIELD_BACKUP_ID => '2026-07-19_10-30-00',
        ], $command->sentPayload);
    }

    public function testAnExplicitInstantAndScopeTravelAsTheAgentReadsThem(): void
    {
        $command = new BackupTestAgeCommandSpy();

        $this->expectOutputRegex('/Aged backup/');
        self::assertSame(ExitCode::SUCCESS, $command->execute(
            ['at' => '2026-01-01T00:00:00+00:00', 'scope' => 'full'],
            ['2026-07-19_10-30-00'],
        ));
        self::assertSame([
            BackupConstants::FIELD_AGE_AT => '2026-01-01T00:00:00+00:00',
            BackupConstants::FIELD_BACKUP_ID => '2026-07-19_10-30-00',
            BackupConstants::FIELD_SCOPE => BackupScope::FULL->value,
        ], $command->sentPayload);
    }

    public function testALooserInstantIsNormalisedBeforeItGoesOnTheWire(): void
    {
        // What an operator types is not what the wire carries: the CLI parses it here, so the
        // agent reads one shape and a string it could not read never reaches it at all.
        $command = new BackupTestAgeCommandSpy();

        $this->expectOutputRegex('/Aged backup/');
        self::assertSame(
            ExitCode::SUCCESS,
            $command->execute(['at' => '2026-01-01 00:00:00 UTC'], ['2026-07-19_10-30-00']),
        );
        self::assertSame('2026-01-01T00:00:00+00:00', $command->sentPayload[BackupConstants::FIELD_AGE_AT]);
    }

    public function testAMalformedInstantIsRefusedHereRatherThanByTheAgent(): void
    {
        // Refused in the process the operator is standing in front of: a round-trip would answer
        // in payload keys ("ageAt"), which names nothing the operator typed.
        $command = new BackupTestAgeCommandSpy();

        $this->expectOutputRegex('/Specify exactly one/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            $command->execute(['at' => 'the day before yesterday-ish'], ['2026-07-19_10-30-00']),
        );
        self::assertNull($command->sentCommand);
    }

    public function testAnUnknownScopeIsRefusedHereAndNamesWhatWasTyped(): void
    {
        $command = new BackupTestAgeCommandSpy();

        $this->expectOutputString("Unknown scope: nonsense\n");
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            $command->execute(['days' => '40', 'scope' => 'nonsense'], ['2026-07-19_10-30-00']),
        );
        self::assertNull($command->sentCommand);
    }

    public function testNeitherOptionIsRefusedBeforeAnythingIsSent(): void
    {
        $command = new BackupTestAgeCommandSpy();

        $this->expectOutputRegex('/Specify exactly one/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, $command->execute([], ['2026-07-19_10-30-00']));
        self::assertNull($command->sentCommand);
    }

    public function testBothOptionsAreRefusedBeforeAnythingIsSent(): void
    {
        // Both named is two different instants asked for at once; honouring either one silently
        // would age the sidecar to something the operator did not choose.
        $command = new BackupTestAgeCommandSpy();

        $this->expectOutputRegex('/Specify exactly one/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            $command->execute(['at' => '2026-01-01T00:00:00+00:00', 'days' => '40'], ['2026-07-19_10-30-00']),
        );
        self::assertNull($command->sentCommand);
    }

    public function testAMissingIdIsRefusedBeforeAnythingIsSent(): void
    {
        $command = new BackupTestAgeCommandSpy();

        $this->expectOutputRegex('/Usage/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, $command->execute(['days' => '40'], []));
        self::assertNull($command->sentCommand);
    }

    public function testAReplyWithoutTheStampedInstantIsAFailure(): void
    {
        $command = new BackupTestAgeCommandSpy();
        $command->reply = CommandReplyDTO::ok('cid', []);

        $this->expectOutputRegex('/no aged createdAt/');
        self::assertSame(ExitCode::ERROR, $command->execute(['days' => '40'], ['2026-07-19_10-30-00']));
    }
}

/**
 * The command with its one outward call stood in: what it would have put on the wire is captured
 * instead, and a canned reply comes back.
 */
final class BackupTestAgeCommandSpy extends BackupTestAgeCommand
{
    /** @var ?string Wire name the command sent, or null when it refused before sending */
    public ?string $sentCommand = null;

    /** @var ?array<string, mixed> Payload the command sent, or null when it refused before sending */
    public ?array $sentPayload = null;

    /** @var ?CommandReplyDTO Reply handed back to the command; a successful aging by default */
    public ?CommandReplyDTO $reply = null;

    /**
     * Captures the round-trip instead of opening a socket.
     *
     * @param string $command Command-channel wire name
     * @param array<string, mixed> $payload Request payload
     * @return CommandChannelResult Canned reply
     */
    protected function sendCommand(string $command, array $payload): CommandChannelResult
    {
        $this->sentCommand = $command;
        $this->sentPayload = $payload;

        return CommandChannelResult::replied($this->reply ?? CommandReplyDTO::ok('cid', [
            BackupConstants::FIELD_BACKUP_ID => $payload[BackupConstants::FIELD_BACKUP_ID],
            BackupConstants::FIELD_AGE_AT => '2026-06-09T10:30:00+00:00',
        ]), '127.0.0.1:8094');
    }
}
