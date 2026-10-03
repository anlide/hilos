<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\CLI\Commands\VerificationTestSweepCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/** Argument boundary of the test-only sweep command. */
final class VerificationTestSweepCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousAppEnv = getenv('APP_ENV');
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
    }

    public function testRejectsMissingOrEmptyIdentifier(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, new VerificationTestSweepCommand()->execute([], []));
    }

    public function testNameAndTestOnlyDeclarationAgree(): void
    {
        $command = new VerificationTestSweepCommand();
        self::assertSame(CliCommands::VERIFICATION_TEST_SWEEP, $command->getName());
        self::assertInstanceOf(TestOnlyCommand::class, $command);
    }
}
