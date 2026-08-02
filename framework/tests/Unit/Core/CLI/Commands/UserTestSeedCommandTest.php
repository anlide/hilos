<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\UserTestSeedCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the test:user:seed CLI: deterministic identifier generation and the
 * argument-validation / no-seam branches that return before any user or identity is
 * written. The seed-and-verify behavior is exercised by the chat integration and e2e
 * tests; the production-env guard is covered by TestOnlyCommandGuardTest. Runs under a
 * non-production APP_ENV so the TestOnlyCommand guard admits the command body.
 */
final class UserTestSeedCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        putenv('APP_ENV');
    }

    public function testFixtureIdentifierIsOneBasedAndPaddedToCountWidth(): void
    {
        self::assertSame('seed-001', UserTestSeedCommand::fixtureIdentifier(1, 25, 'seed'));
        self::assertSame('seed-025', UserTestSeedCommand::fixtureIdentifier(25, 25, 'seed'));
        // Width follows the count's digit count, floored at three.
        self::assertSame('seed-0001', UserTestSeedCommand::fixtureIdentifier(1, 1500, 'seed'));
        self::assertSame('seed-1500', UserTestSeedCommand::fixtureIdentifier(1500, 1500, 'seed'));
        // A small count still pads to three, and the prefix is honored.
        self::assertSame('load-007', UserTestSeedCommand::fixtureIdentifier(7, 9, 'load'));
    }

    public function testRejectsNonIntegerCount(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new UserTestSeedCommand()->execute([], ['abc']),
        );
    }

    public function testRejectsZeroCount(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new UserTestSeedCommand()->execute([], ['0']),
        );
    }

    public function testRejectsNegativeCount(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new UserTestSeedCommand()->execute([], ['-3']),
        );
    }

    public function testRejectsMissingCount(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new UserTestSeedCommand()->execute([], []),
        );
    }

    public function testRejectsEmptyPrefix(): void
    {
        $this->expectOutputRegex('/--prefix/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new UserTestSeedCommand()->execute(['prefix' => ''], ['5']),
        );
    }

    public function testConfigErrorWhenProjectHasNoFixtureUserSeam(): void
    {
        // The base Hilos facade returns null from createFixtureUser, so a project that
        // never wired the seam reports a config error before writing any identity.
        $this->expectOutputRegex('/does not support fixture users/');
        self::assertSame(
            ExitCode::CONFIG_ERROR,
            new UserTestSeedCommand()->execute([], ['5']),
        );
    }
}
