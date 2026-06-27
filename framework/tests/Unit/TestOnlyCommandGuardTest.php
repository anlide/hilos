<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the TestOnlyCommand production guard.
 */
final class TestOnlyCommandGuardTest extends TestCase
{
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
        putenv('APP_ENV');
    }

    public function testRefusesOnProductionEnv(): void
    {
        putenv('APP_ENV=prod');
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
