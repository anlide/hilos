<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Unit;

use Demo\Chat\CLI\Commands\SessionExpireCommand;
use Demo\Chat\Hilos;
use Hilos\Constants\ExitCode;
use Hilos\Database\Context\DbContext;
use Hilos\Environment\EnvAccessor;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the test:session:expire CLI argument-validation branches.
 *
 * Covers only the branches that return before any database write (missing token,
 * unavailable db); the actual expiry behavior is exercised by SessionsActionsTest and
 * the HIL-167 e2e. Runs under a non-production APP_ENV so the TestOnlyCommand guard
 * admits the command body.
 */
final class SessionExpireCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private ?DbContext $previousDb = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousDb = Hilos::$db;
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        Hilos::$db = $this->previousDb;
        putenv('APP_ENV');
    }

    public function testRejectsEmptyToken(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new SessionExpireCommand()->execute([], []),
        );
    }

    public function testConfigErrorWhenDbMissing(): void
    {
        Hilos::$db = null;

        $this->expectOutputRegex('/not available/');
        self::assertSame(
            ExitCode::CONFIG_ERROR,
            new SessionExpireCommand()->execute([], ['deadbeefdeadbeefdeadbeefdeadbeef']),
        );
    }
}
