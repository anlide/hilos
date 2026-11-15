<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Auth\AccountDeletion\AccountDeletionCommandConstants;
use Hilos\Auth\AccountDeletion\AccountDeletionCommandOutcome;
use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\AccountTestForcePurgeCommand;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Users\AccountErasureProgress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the argument boundary, declarations and reply rendering of test:account:force-purge.
 *
 * Invalid input returns before the command channel is opened. The rendering cases pin the honest
 * outcome of HIL-1953: "Erased" only for a finished erasure, the stage reached otherwise, and an
 * unknown outcome when no reply arrived. The production guard is covered by TestOnlyCommandGuardTest.
 */
final class AccountTestForcePurgeCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    /** @var string|false APP_ENV restored after each case */
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

    public function testRejectsMissingUserId(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, new AccountTestForcePurgeCommand()->execute([], []));
    }

    public function testRejectsNonIntegerUserId(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, new AccountTestForcePurgeCommand()->execute([], ['abc']));
    }

    public function testRejectsZeroUserId(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, new AccountTestForcePurgeCommand()->execute([], ['0']));
    }

    public function testNameAndTestOnlyDeclarationAgree(): void
    {
        $command = new AccountTestForcePurgeCommand();

        self::assertSame(CliCommands::ACCOUNT_TEST_FORCE_PURGE, $command->getName());
        self::assertInstanceOf(TestOnlyCommand::class, $command);
    }

    public function testACompletedErasurePrintsTheTallyAndSucceeds(): void
    {
        $command = new AccountTestForcePurgeCommandSpy();
        $command->result = self::replied(CommandReplyDTO::ok('cid', [
            AccountDeletionCommandConstants::FIELD_USER_ID => 42,
            AccountDeletionCommandConstants::FIELD_OUTCOME => AccountDeletionCommandOutcome::Completed->value,
            AccountDeletionCommandConstants::FIELD_ROWS_ERASED => ['personal' => 3, 'tickets' => 2],
        ]));

        $this->expectOutputString("Erased the account of user 42 (personal=3, tickets=2)\n");
        self::assertSame(ExitCode::SUCCESS, $command->execute([], ['42']));
        self::assertSame(CliCommands::ACCOUNT_TEST_FORCE_PURGE, $command->sentCommand);
        self::assertSame([AccountDeletionCommandConstants::FIELD_USER_ID => 42], $command->sentPayload);
    }

    public function testABegunErasurePrintsItsStageAndTimesOut(): void
    {
        $part = static fn(string $participant, int $userId, bool $done): array => [
            AccountErasureProgress::participant => $participant,
            AccountErasureProgress::owner => 'library',
            AccountErasureProgress::userId => $userId,
            AccountErasureProgress::done => $done,
        ];
        $command = new AccountTestForcePurgeCommandSpy();
        $command->result = self::replied(CommandReplyDTO::ok('cid', [
            AccountDeletionCommandConstants::FIELD_USER_ID => 42,
            AccountDeletionCommandConstants::FIELD_OUTCOME => AccountDeletionCommandOutcome::Erasing->value,
            AccountDeletionCommandConstants::FIELD_ERASURE => [
                AccountErasureProgress::startedAt => 1_700_000_000_000,
                AccountErasureProgress::parts => [
                    $part('personal', 42, true),
                    $part('tickets', 42, true),
                    $part('personal', 43, true),
                    $part('tickets', 43, false),
                    $part('chat', 42, false),
                ],
                AccountErasureProgress::waits => [
                    [AccountErasureProgress::participant => 'chat', AccountErasureProgress::userId => 43],
                ],
                AccountErasureProgress::refusals => [
                    [AccountErasureProgress::userId => 43, AccountErasureProgress::table => 'tickets'],
                    [AccountErasureProgress::userId => 42, AccountErasureProgress::table => null],
                ],
            ],
        ]));

        $this->expectOutputString(
            'The erasure of user 42 has started and is not finished: 3 of 5 parts done'
            . '; waiting for an owner of chat (user 43)'
            . '; the database refuses to finish user 43 (table tickets), user 42'
            . ". Run the command again to follow it\n",
        );
        self::assertSame(ExitCode::TIMEOUT, $command->execute([], ['42']));
    }

    public function testAnErasureNotYetBegunTimesOut(): void
    {
        $command = new AccountTestForcePurgeCommandSpy();
        $command->result = self::replied(CommandReplyDTO::ok('cid', [
            AccountDeletionCommandConstants::FIELD_USER_ID => 42,
            AccountDeletionCommandConstants::FIELD_OUTCOME => AccountDeletionCommandOutcome::Starting->value,
        ]));

        $this->expectOutputString(
            "The erasure of user 42 has not started yet: its account's agent has not begun it. Run the command again to follow it\n",
        );
        self::assertSame(ExitCode::TIMEOUT, $command->execute([], ['42']));
    }

    public function testARefusalPrintsNothingOnStandardOutput(): void
    {
        $command = new AccountTestForcePurgeCommandSpy();
        $command->result = self::replied(
            CommandReplyDTO::error('cid', 'The deletion of user 42 was canceled before it could be carried out'),
        );

        $this->expectOutputString('');
        self::assertSame(ExitCode::ERROR, $command->execute([], ['42']));
        self::assertStringContainsString('The deletion of user 42 was canceled', $command->standardError);
    }

    /** @return array<string, array{CommandChannelResult}> Round-trips that came back without a reply */
    public static function lostReplies(): array
    {
        return [
            'timed out' => [CommandChannelResult::timedOut('127.0.0.1:8094')],
            'unreachable' => [CommandChannelResult::unreachable('127.0.0.1:8094')],
        ];
    }

    #[DataProvider('lostReplies')]
    public function testALostReplyLeavesTheOutcomeUnknown(CommandChannelResult $lost): void
    {
        $command = new AccountTestForcePurgeCommandSpy();
        $command->result = $lost;

        $this->expectOutputString('');
        self::assertSame(ExitCode::ERROR, $command->execute([], ['42']));
        self::assertStringContainsString(
            'Whether the erasure of user 42 began is unknown; run the command again to see where it stands',
            $command->standardError,
        );
        self::assertStringNotContainsString('canceled', $command->standardError);
    }

    public function testAReplyWithoutAnOutcomeIsAFailure(): void
    {
        $command = new AccountTestForcePurgeCommandSpy();
        $command->result = self::replied(CommandReplyDTO::ok('cid', [
            AccountDeletionCommandConstants::FIELD_USER_ID => 42,
            AccountDeletionCommandConstants::FIELD_ROWS_ERASED => ['personal' => 3],
        ]));

        $this->expectOutputString("Command failed: the reply carries no erasure outcome\n");
        self::assertSame(ExitCode::ERROR, $command->execute([], ['42']));
    }

    private static function replied(CommandReplyDTO $reply): CommandChannelResult
    {
        return CommandChannelResult::replied($reply, '127.0.0.1:8094');
    }
}

/**
 * The command with its one outward call stood in: what it would have put on the wire is captured
 * instead, a canned round-trip comes back, and stderr is kept for the case to read.
 */
final class AccountTestForcePurgeCommandSpy extends AccountTestForcePurgeCommand
{
    /** @var ?string Wire name the command sent, or null when it refused before sending */
    public ?string $sentCommand = null;

    /** @var ?array<string, mixed> Payload the command sent, or null when it refused before sending */
    public ?array $sentPayload = null;

    /** @var ?CommandChannelResult Round-trip handed back to the command */
    public ?CommandChannelResult $result = null;

    /** @var string Text sent to stderr by the shared printers and the command */
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

        return $this->result ?? CommandChannelResult::unreachable('127.0.0.1:8094');
    }

    /** Captures stderr output instead of writing it during the suite. */
    protected function writeToStandardError(string $text): void
    {
        $this->standardError .= $text . "\n";
    }
}
