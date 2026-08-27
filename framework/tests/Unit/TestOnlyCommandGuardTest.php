<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\CommandExecution;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\NonProductionGate;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the TestOnlyCommand production guard.
 *
 * The verdict itself moved to {@see NonProductionGate} (HIL-566, rehomed in HIL-671), which
 * the command socket asks the same question of; these cases stay because the CLI half is the
 * one that answers by THROWING, and a base class that stopped refusing would take the whole
 * family down with it.
 */
final class TestOnlyCommandGuardTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    /** @var string|false APP_ENV the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousAppEnv = getenv('APP_ENV');
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
    }

    public function testRefusesOnProductionEnv(): void
    {
        putenv('APP_ENV=prod');
        Hilos::$env = new EnvAccessor();

        $this->expectException(TestOnlyCommandOnProductionException::class);
        $this->fixtureCommand()->execute([], []);
    }

    public function testRefusesOnStagingEnv(): void
    {
        putenv('APP_ENV=staging');
        Hilos::$env = new EnvAccessor();

        $this->expectException(TestOnlyCommandOnProductionException::class);
        $this->fixtureCommand()->execute([], []);
    }

    /**
     * A value nobody recognizes is refused, not guessed at: the gate is fail-closed because
     * the two mistakes cost differently, and an unreadable environment is not evidence of a
     * test node.
     */
    public function testRefusesOnAnUnrecognizedEnv(): void
    {
        putenv('APP_ENV=weekend-box');
        Hilos::$env = new EnvAccessor();

        $this->expectException(TestOnlyCommandOnProductionException::class);
        $this->fixtureCommand()->execute([], []);
    }

    public function testRunsOutsideProduction(): void
    {
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();

        $this->assertSame(ExitCode::SUCCESS, $this->fixtureCommand()->execute([], []));
    }

    private function fixtureCommand(): TestOnlyCommand
    {
        return new class extends TestOnlyCommand {
            public function getName(): string
            {
                return 'test:guard-fixture';
            }

            public function execution(): CommandExecution
            {
                return CommandExecution::cliOfflineWrite('guard fixture: the non-production gate is what this exercises, not a site');
            }

            public function getDescription(): string
            {
                return 'Guard fixture';
            }

            public function getHelp(): string
            {
                return 'Guard fixture';
            }

            protected function run(array $options, array $args): int
            {
                return ExitCode::SUCCESS;
            }
        };
    }
}
