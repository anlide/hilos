<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * A test-only command refused on production reaches the operator as a refusal, not as a crash.
 *
 * The command throws {@see TestOnlyCommandOnProductionException}. Until CliManager caught it by
 * name, the catch-all printed "✗ Unexpected Error" with a file and a line, which reads as a
 * defect of the framework rather than as the verdict it is. The sentence is the one the command
 * socket answers the same refusal with, so a caller driving a command either way reads one
 * wording.
 */
final class CliManagerTestOnlyRefusalTest extends TestCase
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

    public function testATestOnlyCommandOnProductionIsPrintedAsARefusal(): void
    {
        putenv('APP_ENV=production');
        Hilos::$env = new EnvAccessor();
        $manager = new class (['cli.php', CliCommands::TABLE_TEST_LAG]) extends CliManager {
            /** @var list<string> Sentences the manager wrote to stderr */
            public array $standardError = [];

            protected function writeToStandardError(string $text): void
            {
                $this->standardError[] = $text;
            }
        };

        $this->expectOutputString('');
        $exitCode = $manager->run();

        $this->assertSame(ExitCode::ERROR, $exitCode);
        $this->assertSame(
            ['Refused: ' . TestOnlyCommandOnProductionException::message(CliCommands::TABLE_TEST_LAG)],
            $manager->standardError,
        );
    }
}
