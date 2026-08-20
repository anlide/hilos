<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Core\CLI\Commands\DbTestResetCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Tests that db:test:reset refuses before it touches anything (HIL-566).
 *
 * The command drops a database and recreates it, and until this ticket it was the one
 * destructive CLI command with no environment guard at all: APP_ENV reached it only at the
 * seed step, by which time the DROP had already run. The refusal has to arrive BEFORE the
 * connection, which is what makes it testable without a database at all - a production-like
 * environment must raise, not fail to connect.
 */
final class DbTestResetCommandTest extends TestCase
{
    /** @var ?EnvAccessor Previous env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        putenv('APP_ENV=test');
    }

    public function testTheCommandIsTestOnlyByContract(): void
    {
        $this->assertInstanceOf(TestOnlyCommand::class, new DbTestResetCommand());
        $this->assertSame(CliCommands::DB_TEST_RESET, new DbTestResetCommand()->getName());
    }

    public function testItRefusesOnProductionBeforeTouchingTheDatabase(): void
    {
        putenv('APP_ENV=prod');
        Hilos::$env = new EnvAccessor();

        $this->expectException(TestOnlyCommandOnProductionException::class);
        $this->expectExceptionMessage(CliCommands::DB_TEST_RESET);
        new DbTestResetCommand()->execute([], []);
    }

    public function testItRefusesOnAnUnrecognizedEnvironment(): void
    {
        putenv('APP_ENV=weekend-box');
        Hilos::$env = new EnvAccessor();

        $this->expectException(TestOnlyCommandOnProductionException::class);
        new DbTestResetCommand()->execute([], []);
    }
}
