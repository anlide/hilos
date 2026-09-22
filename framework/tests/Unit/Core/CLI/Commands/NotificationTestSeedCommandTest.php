<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\CliCommands;
use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\NotificationTestSeedCommand;
use Hilos\Core\CLI\Commands\TestOnlyCommand;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for test:notification:seed argument validation, deterministic title
 * generation, and the inherited production gate. Database behavior is exercised by
 * the chat integration test.
 */
final class NotificationTestSeedCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    /** @var string|false APP_ENV restored after each test */
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

    public function testCommandIsTestOnlyByContract(): void
    {
        self::assertInstanceOf(TestOnlyCommand::class, new NotificationTestSeedCommand());
        self::assertSame(CliCommands::NOTIFICATION_TEST_SEED, new NotificationTestSeedCommand()->getName());
    }

    public function testFixtureTitleIsOneBasedAndPaddedToCountWidth(): void
    {
        self::assertSame('Seed notification 001', NotificationTestSeedCommand::fixtureTitle(1, 25, 'Seed notification'));
        self::assertSame('Seed notification 025', NotificationTestSeedCommand::fixtureTitle(25, 25, 'Seed notification'));
        self::assertSame('Alert 0001', NotificationTestSeedCommand::fixtureTitle(1, 1500, 'Alert'));
        self::assertSame('Alert 1500', NotificationTestSeedCommand::fixtureTitle(1500, 1500, 'Alert'));
    }

    public function testRejectsNonIntegerCount(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, new NotificationTestSeedCommand()->execute([], ['abc']));
    }

    public function testRejectsZeroCount(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(ExitCode::INVALID_ARGUMENT, new NotificationTestSeedCommand()->execute([], ['0']));
    }

    public function testRejectsReadCountAboveTotal(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new NotificationTestSeedCommand()->execute(['read' => '6'], ['5']),
        );
    }

    public function testRejectsEmptyDeliveredChannelOption(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new NotificationTestSeedCommand()->execute(['delivered' => ''], ['5']),
        );
    }

    public function testRejectsBooleanDeliveredChannelOption(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new NotificationTestSeedCommand()->execute(['delivered' => true], ['5']),
        );
    }

    public function testRefusesOnProductionBeforeReadingTheDatabase(): void
    {
        putenv('APP_ENV=prod');
        Hilos::$env = new EnvAccessor();

        $this->expectException(TestOnlyCommandOnProductionException::class);
        $this->expectExceptionMessage(CliCommands::NOTIFICATION_TEST_SEED);
        new NotificationTestSeedCommand()->execute([], ['5']);
    }
}
