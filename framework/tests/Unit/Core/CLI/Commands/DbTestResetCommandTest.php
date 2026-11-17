<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Core\CLI\Commands\DbTestResetCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\Context\FsContext;
use Hilos\Hilos;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Tests that test:db:reset refuses before it touches anything (HIL-566).
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

    /** @var class-string<Hilos> Facade to restore */
    private string $previousFacade;

    private ?FsContext $previousFs;

    /** Binds Analytics so an early filesystem factory call would fail this case. */
    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousFacade = Hilos::appClass();
        $this->previousFs = Hilos::$fs;
        Hilos::$env = new EnvAccessor();
        ResetGuardHilos::initEnv();
        Hilos::$fs = null;
    }

    /** Restores facade statics; the suite extension restores the process environment. */
    protected function tearDown(): void
    {
        Hilos::$env = new EnvAccessor();
        $this->previousFacade::initEnv();
        Hilos::$env = $this->previousEnv;
        Hilos::$fs = $this->previousFs;
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

/** Both storage factories must stay beyond the non-production gate. */
final class ResetGuardHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::ANALYTICS];

    /** @return HilosDbContext Never initialized by a refused reset */
    protected static function createDb(): HilosDbContext
    {
        throw new LogicException('The refused reset reached the database factory');
    }

    /** @return ?FsContext Never initialized by a refused reset */
    protected static function createFs(): ?FsContext
    {
        throw new LogicException('The refused reset reached the filesystem factory');
    }
}
