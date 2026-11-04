<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Backup\Agent\BackupAgent;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Commands\CommandChannelResult;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Commands\ProtectedModeCloseCommand;
use Hilos\Core\CLI\Commands\ProtectedModeInspectCommand;
use Hilos\Core\CLI\Commands\ProtectedModeOpenCommand;
use Hilos\Core\CLI\Commands\ProtectedModePassCommand;
use Hilos\Core\CLI\Commands\ProtectedModeTestCloseCommand;
use Hilos\Core\CLI\Commands\ProtectedModeTestEnterCommand;
use Hilos\Core\CLI\Commands\ProtectedModeTestLeaveCommand;
use Hilos\Core\CLI\Commands\ProtectedModeTestOpenCommand;
use Hilos\Core\CLI\Commands\ProtectedModeTestPassCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\ProtectedMode\ProtectedModeCommandConstants;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the protected-mode CLI commands: their names, the argument-validation
 * branches that return before the command channel is opened, and the contracts each family
 * declares - database-free for all of them, test-only for the drive commands (HIL-344) and
 * emphatically NOT test-only for the operator ones (HIL-481), which exist to be run on the
 * production node a restore just froze. The mint answers to one name of each kind (HIL-616) and
 * so does the close (HIL-704), so both contracts are pinned on the same two handlers.
 *
 * Driving the mode itself needs a running daemon and is exercised by the e2e spec; what is
 * checked here is everything that must hold without one. Runs under a non-production APP_ENV
 * so the TestOnlyCommand guard admits the command bodies.
 */
final class ProtectedModeTestCommandsTest extends TestCase
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

    public function testEachCommandAnswersItsRegisteredName(): void
    {
        self::assertSame(CliCommands::PROTECTED_MODE_INSPECT, new ProtectedModeInspectCommand()->getName());
        self::assertSame(CliCommands::PROTECTED_MODE_TEST_ENTER, new ProtectedModeTestEnterCommand()->getName());
        self::assertSame(CliCommands::PROTECTED_MODE_TEST_LEAVE, new ProtectedModeTestLeaveCommand()->getName());
        self::assertSame(CliCommands::PROTECTED_MODE_TEST_OPEN, new ProtectedModeTestOpenCommand()->getName());
        self::assertSame(CliCommands::PROTECTED_MODE_TEST_PASS, new ProtectedModeTestPassCommand()->getName());
        self::assertSame(CliCommands::PROTECTED_MODE_TEST_CLOSE, new ProtectedModeTestCloseCommand()->getName());
        self::assertSame(CliCommands::PROTECTED_MODE_PASS, new ProtectedModePassCommand()->getName());
        self::assertSame(CliCommands::PROTECTED_MODE_OPEN, new ProtectedModeOpenCommand()->getName());
        self::assertSame(CliCommands::PROTECTED_MODE_CLOSE, new ProtectedModeCloseCommand()->getName());
    }

    public function testBackupAliasesReuseTheCommandsWithTheirOwnNamesPayloadsAndHelp(): void
    {
        $enter = new BackupFreezeEnterCommandProbe(CliCommands::BACKUP_TEST_FREEZE_ENTER, CliCommands::BACKUP_TEST_FREEZE_LEAVE);
        $leave = new BackupFreezeLeaveCommandProbe(CliCommands::BACKUP_TEST_FREEZE_LEAVE, CliCommands::BACKUP_TEST_FREEZE_OPEN);
        $open = new BackupFreezeOpenCommandProbe(CliCommands::BACKUP_TEST_FREEZE_OPEN, CliCommands::BACKUP_TEST_FREEZE_LEAVE);
        $manager = new CliManager([]);
        $this->expectOutputRegex('/Protected mode entered.*Operation ended.*Protected mode opened/s');

        foreach ([
            [$enter, 'test:backup:freeze-enter', CliCommands::BACKUP_TEST_FREEZE_LEAVE, ProtectedModeTestEnterCommand::class],
            [$leave, 'test:backup:freeze-leave', CliCommands::BACKUP_TEST_FREEZE_OPEN, ProtectedModeTestLeaveCommand::class],
            [$open, 'test:backup:freeze-open', CliCommands::BACKUP_TEST_FREEZE_LEAVE, ProtectedModeTestOpenCommand::class],
        ] as [$command, $wireName, $pairedName, $commandClass]) {
            self::assertSame($wireName, $command->getName());
            self::assertSame($commandClass, $manager->commandClasses()[$wireName]);
            self::assertFalse($manager->requiresDatabase($wireName));
            self::assertContains($wireName, BackupAgent::AGENT_COMMANDS);
            self::assertStringContainsString($wireName, $command->getHelp());
            self::assertStringContainsString($pairedName, $command->getHelp());
            self::assertStringNotContainsString('test:protected-mode:', $command->getHelp());
            self::assertSame(ExitCode::SUCCESS, $command->execute(
                $command === $enter ? ['accept-key' => 'browser-key', 'session-token' => 'browser-session'] : [],
                $command === $enter ? ['test-freeze'] : [],
            ));
            self::assertSame($wireName, $command->sentCommand);
            self::assertSame($command === $enter ? [
                ProtectedModeCommandConstants::FIELD_OPERATION => 'test-freeze',
                CommandConstants::FIELD_ACCEPT_KEY => 'browser-key',
                ProtectedModeCommandConstants::FIELD_SESSION_TOKEN => 'browser-session',
            ] : [], $command->sentPayload);
        }
    }

    public function testBackupAliasesKeepTheCliAndSocketTestOnlyRefusal(): void
    {
        putenv('APP_ENV=production');
        Hilos::$env = new EnvAccessor();

        foreach ([
            new ProtectedModeTestEnterCommand(CliCommands::BACKUP_TEST_FREEZE_ENTER, CliCommands::BACKUP_TEST_FREEZE_LEAVE),
            new ProtectedModeTestLeaveCommand(CliCommands::BACKUP_TEST_FREEZE_LEAVE, CliCommands::BACKUP_TEST_FREEZE_OPEN),
            new ProtectedModeTestOpenCommand(CliCommands::BACKUP_TEST_FREEZE_OPEN, CliCommands::BACKUP_TEST_FREEZE_LEAVE),
        ] as $command) {
            self::assertTrue(TestOnlyCommandRegistry::isTestOnly($command->getName()));
            try {
                $command->execute([], ['test-freeze']);
                self::fail("{$command->getName()} must refuse on production.");
            } catch (TestOnlyCommandOnProductionException $e) {
                self::assertStringContainsString($command->getName(), $e->getMessage());
            }
        }
    }

    public function testEveryCommandIsRegisteredAndNeedsNoDatabase(): void
    {
        // The inspector's whole point is answering on a node that cannot reach MySQL, and the
        // drive pair writes nothing from this process - every row is the agent's.
        $manager = new CliManager([]);

        foreach ([
            CliCommands::PROTECTED_MODE_INSPECT,
            CliCommands::PROTECTED_MODE_TEST_ENTER,
            CliCommands::PROTECTED_MODE_TEST_LEAVE,
            CliCommands::PROTECTED_MODE_TEST_OPEN,
            CliCommands::PROTECTED_MODE_TEST_PASS,
            CliCommands::PROTECTED_MODE_TEST_CLOSE,
            CliCommands::PROTECTED_MODE_PASS,
            CliCommands::PROTECTED_MODE_OPEN,
            CliCommands::PROTECTED_MODE_CLOSE,
        ] as $name) {
            self::assertTrue($manager->hasCommand($name), "{$name} must be registered.");
            self::assertFalse($manager->requiresDatabase($name), "{$name} must not open a database.");
        }
    }

    public function testEveryCommandDeclaresItselfDatabaseFree(): void
    {
        self::assertInstanceOf(DatabaseFreeCommand::class, new ProtectedModeInspectCommand());
        self::assertInstanceOf(DatabaseFreeCommand::class, new ProtectedModeTestEnterCommand());
        self::assertInstanceOf(DatabaseFreeCommand::class, new ProtectedModeTestLeaveCommand());
        self::assertInstanceOf(DatabaseFreeCommand::class, new ProtectedModeTestOpenCommand());
        self::assertInstanceOf(DatabaseFreeCommand::class, new ProtectedModeTestPassCommand());
        self::assertInstanceOf(DatabaseFreeCommand::class, new ProtectedModeTestCloseCommand());
        // The operator trio must be database-free for a harder reason than convenience: the
        // database a bootstrap connect would open is the very one the restore just rewrote.
        self::assertInstanceOf(DatabaseFreeCommand::class, new ProtectedModePassCommand());
        self::assertInstanceOf(DatabaseFreeCommand::class, new ProtectedModeOpenCommand());
        self::assertInstanceOf(DatabaseFreeCommand::class, new ProtectedModeCloseCommand());
    }

    public function testTheOperatorCommandsAreNotTestOnly(): void
    {
        // The point of the whole leaf: a restore no longer opens the system by itself, so these
        // three are the only way back in - on production above all. Subclassing TestOnlyCommand
        // would leave a frozen production node with no exit at all.
        self::assertNotInstanceOf(TestOnlyCommand::class, new ProtectedModePassCommand());
        self::assertNotInstanceOf(TestOnlyCommand::class, new ProtectedModeOpenCommand());
        self::assertNotInstanceOf(TestOnlyCommand::class, new ProtectedModeCloseCommand());
        self::assertNotInstanceOf(TestOnlyCommand::class, new ProtectedModeInspectCommand());
    }

    public function testHelpNamesTheArgumentsEachCommandTakes(): void
    {
        self::assertStringContainsString('<operation>', new ProtectedModeTestEnterCommand()->getHelp());
        self::assertStringContainsString('--accept-key', new ProtectedModeTestEnterCommand()->getHelp());
        self::assertStringContainsString('--session-token', new ProtectedModeTestEnterCommand()->getHelp());
        // Everything after enter takes nothing: those are authorized by initiator identity,
        // not by an argument.
        self::assertStringNotContainsString('--', new ProtectedModeTestLeaveCommand()->getHelp());
        self::assertStringNotContainsString('--', new ProtectedModeTestOpenCommand()->getHelp());
        self::assertStringNotContainsString('--', new ProtectedModeTestPassCommand()->getHelp());
        self::assertStringNotContainsString('--', new ProtectedModeTestCloseCommand()->getHelp());
    }

    public function testTheTestMintIsTestOnlyWhileTheOperatorsIsNot(): void
    {
        // The pair that makes the two names worth having: the same mint runs behind both, but
        // only one of them may be reachable on a production node.
        self::assertInstanceOf(TestOnlyCommand::class, new ProtectedModeTestPassCommand());
        self::assertNotInstanceOf(TestOnlyCommand::class, new ProtectedModePassCommand());
    }

    public function testEnterRejectsAMissingOperationName(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, new ProtectedModeTestEnterCommand()->execute([], []));
    }

    public function testEnterRejectsAnEmptyOperationName(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, new ProtectedModeTestEnterCommand()->execute([], ['']));
    }

    public function testEnterRejectsAnEmptyAcceptKeyOption(): void
    {
        // Absent means "no window survives the lockout"; present-but-empty is a fixture that
        // meant to name a connection and named none, which must not read as the default.
        $this->expectOutputRegex('/--accept-key/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new ProtectedModeTestEnterCommand()->execute(['accept-key' => ''], ['restore']),
        );
    }

    public function testEnterRejectsAnEmptySessionTokenOption(): void
    {
        $this->expectOutputRegex('/--session-token/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new ProtectedModeTestEnterCommand()->execute(['session-token' => ''], ['restore']),
        );
    }

    public function testTheWholeFamilyRefusesOnAProductionLikeEnvironment(): void
    {
        putenv('APP_ENV=production');
        Hilos::$env = new EnvAccessor();

        foreach ([
            new ProtectedModeTestEnterCommand(),
            new ProtectedModeTestLeaveCommand(),
            new ProtectedModeTestOpenCommand(),
            new ProtectedModeTestPassCommand(),
            new ProtectedModeTestCloseCommand(),
        ] as $command) {
            try {
                $command->execute([], ['restore']);
                self::fail("{$command->getName()} must refuse on a production-like environment.");
            } catch (TestOnlyCommandOnProductionException $e) {
                self::assertStringContainsString($command->getName(), $e->getMessage());
            }
        }
    }
}

trait BackupFreezeCommandProbeTrait
{
    public ?string $sentCommand = null;

    /** @var ?array<string, mixed> Last payload sent */
    public ?array $sentPayload = null;

    /**
     * @param string $command Wire name to capture
     * @param array<string, mixed> $payload Payload to capture
     * @param ?float $waitSeconds Unused wait budget
     * @return CommandChannelResult Successful canned reply without opening a socket
     */
    protected function sendCommand(string $command, array $payload, ?float $waitSeconds = null): CommandChannelResult
    {
        $this->sentCommand = $command;
        $this->sentPayload = $payload;

        return CommandChannelResult::replied(CommandReplyDTO::ok('alias-test', []), '127.0.0.1:8094');
    }
}

final class BackupFreezeEnterCommandProbe extends ProtectedModeTestEnterCommand
{
    use BackupFreezeCommandProbeTrait;
}

final class BackupFreezeLeaveCommandProbe extends ProtectedModeTestLeaveCommand
{
    use BackupFreezeCommandProbeTrait;
}

final class BackupFreezeOpenCommandProbe extends ProtectedModeTestOpenCommand
{
    use BackupFreezeCommandProbeTrait;
}
