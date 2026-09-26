<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\AccountTestForcePurgeCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the argument boundary and declarations of test:account:force-purge.
 *
 * Invalid input returns before the command channel is opened. The production guard is
 * covered by TestOnlyCommandGuardTest.
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
}
