<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Commands\ProtectedModeTestEnterCommand;
use Hilos\Core\CLI\Commands\ProtectedModeTestInspectCommand;
use Hilos\Core\CLI\Commands\ProtectedModeTestLeaveCommand;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the three protected-mode CLI commands (HIL-344): their names, the
 * argument-validation branches that return before the command channel is opened, and the
 * two contracts the whole family declares - test-only and database-free.
 *
 * Driving the mode itself needs a running daemon and is exercised by the e2e spec; what is
 * checked here is everything that must hold without one. Runs under a non-production APP_ENV
 * so the TestOnlyCommand guard admits the command bodies.
 */
final class ProtectedModeTestCommandsTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        putenv('APP_ENV');
    }

    public function testEachCommandAnswersItsRegisteredName(): void
    {
        self::assertSame(CliCommands::PROTECTED_MODE_TEST_INSPECT, new ProtectedModeTestInspectCommand()->getName());
        self::assertSame(CliCommands::PROTECTED_MODE_TEST_ENTER, new ProtectedModeTestEnterCommand()->getName());
        self::assertSame(CliCommands::PROTECTED_MODE_TEST_LEAVE, new ProtectedModeTestLeaveCommand()->getName());
    }

    public function testEveryCommandIsRegisteredAndNeedsNoDatabase(): void
    {
        // The inspector's whole point is answering on a node that cannot reach MySQL, and the
        // drive pair writes nothing from this process - every row is the agent's.
        $manager = new CliManager([]);

        foreach ([
            CliCommands::PROTECTED_MODE_TEST_INSPECT,
            CliCommands::PROTECTED_MODE_TEST_ENTER,
            CliCommands::PROTECTED_MODE_TEST_LEAVE,
        ] as $name) {
            self::assertTrue($manager->hasCommand($name), "{$name} must be registered.");
            self::assertFalse($manager->requiresDatabase($name), "{$name} must not open a database.");
        }
    }

    public function testEveryCommandDeclaresItselfDatabaseFree(): void
    {
        self::assertInstanceOf(DatabaseFreeCommand::class, new ProtectedModeTestInspectCommand());
        self::assertInstanceOf(DatabaseFreeCommand::class, new ProtectedModeTestEnterCommand());
        self::assertInstanceOf(DatabaseFreeCommand::class, new ProtectedModeTestLeaveCommand());
    }

    public function testHelpNamesTheArgumentsEachCommandTakes(): void
    {
        self::assertStringContainsString('<operation>', new ProtectedModeTestEnterCommand()->getHelp());
        self::assertStringContainsString('--accept-key', new ProtectedModeTestEnterCommand()->getHelp());
        // Leave takes nothing: it is authorized by initiator identity, not by an argument.
        self::assertStringNotContainsString('--', new ProtectedModeTestLeaveCommand()->getHelp());
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

    public function testTheWholeFamilyRefusesOnAProductionLikeEnvironment(): void
    {
        putenv('APP_ENV=production');
        Hilos::$env = new EnvAccessor();

        foreach ([
            new ProtectedModeTestInspectCommand(),
            new ProtectedModeTestEnterCommand(),
            new ProtectedModeTestLeaveCommand(),
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
