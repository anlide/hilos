<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\CLI\Commands\VerificationTestEndPauseCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/** Argument boundary and test-only declaration of the resend pause command. */
final class VerificationTestEndPauseCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    /** @var string|false APP_ENV restored after each case */
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousAppEnv = getenv('APP_ENV');
        $this->previousEnv = Hilos::$env;
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
    }

    public function testRequiresAnAddressAndSessionToken(): void
    {
        $command = new VerificationTestEndPauseCommand();

        $this->expectOutputRegex('/Usage/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, $command->execute([], ['person@example.test']));
    }

    public function testRejectsBlankAddressBeforeOpeningTheChannel(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new VerificationTestEndPauseCommand()->execute([], [' ', 'cookie-token']),
        );
    }

    public function testNameAndTestOnlyDeclarationAgree(): void
    {
        $command = new VerificationTestEndPauseCommand();

        self::assertSame(CliCommands::VERIFICATION_TEST_END_PAUSE, $command->getName());
        self::assertInstanceOf(TestOnlyCommand::class, $command);
    }
}
