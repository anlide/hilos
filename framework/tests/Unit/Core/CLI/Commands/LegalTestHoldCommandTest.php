<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\LegalTestHoldCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the argument boundary and declarations of test:legal:hold.
 *
 * Invalid input returns before the command channel is opened. The production guard is
 * covered by TestOnlyCommandGuardTest.
 */
final class LegalTestHoldCommandTest extends TestCase
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

    public function testRejectsMissingArguments(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, new LegalTestHoldCommand()->execute([], []));
    }

    public function testRejectsNonIntegerUserId(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, new LegalTestHoldCommand()->execute([], ['abc', 'terms', 'rev1']));
    }

    public function testRejectsZeroUserId(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, new LegalTestHoldCommand()->execute([], ['0', 'terms', 'rev1']));
    }

    public function testRejectsUndeclaredDocument(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, new LegalTestHoldCommand()->execute([], ['42', 'cookies', 'rev1']));
    }

    public function testRejectsEmptyRevisionId(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, new LegalTestHoldCommand()->execute([], ['42', 'terms', '']));
    }

    public function testNameAndTestOnlyDeclarationAgree(): void
    {
        $command = new LegalTestHoldCommand();

        self::assertSame(CliCommands::LEGAL_TEST_HOLD, $command->getName());
        self::assertInstanceOf(TestOnlyCommand::class, $command);
    }
}
