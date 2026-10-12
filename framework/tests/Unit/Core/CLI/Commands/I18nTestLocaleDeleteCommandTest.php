<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\DatabaseFreeCommand;
use Hilos\Core\CLI\Commands\I18nTestLocaleDeleteCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/** The test locale delete command refuses malformed arguments before opening the channel. */
final class I18nTestLocaleDeleteCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
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

    public function testDeclaresItsNameAndExecutionBoundary(): void
    {
        $command = new I18nTestLocaleDeleteCommand();
        self::assertSame(CliCommands::I18N_TEST_LOCALE_DELETE, $command->getName());
        self::assertInstanceOf(TestOnlyCommand::class, $command);
        self::assertInstanceOf(DatabaseFreeCommand::class, $command);
    }

    public function testRejectsMissingAndMalformedCodes(): void
    {
        foreach ([[], ['EN'], ['eng'], [''], ['fr', 'de']] as $arguments) {
            ob_start();
            $exit = new I18nTestLocaleDeleteCommand()->execute([], $arguments);
            $output = ob_get_clean();
            self::assertSame(ExitCode::INVALID_ARGUMENT, $exit);
            self::assertStringContainsString('Usage:', $output);
        }
    }

    public function testRefusesProductionBeforeOpeningTheChannel(): void
    {
        putenv('APP_ENV=production');
        Hilos::$env = new EnvAccessor();
        $this->expectException(TestOnlyCommandOnProductionException::class);
        new I18nTestLocaleDeleteCommand()->execute([], ['uk']);
    }
}
