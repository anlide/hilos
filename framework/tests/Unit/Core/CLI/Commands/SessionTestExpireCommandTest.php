<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\SessionTestExpireCommand;
use Hilos\Database\Context\DbContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the test:session:expire CLI argument-validation branches.
 *
 * Covers only the branches that return before any database write (missing token,
 * unavailable db); the actual expiry behavior is exercised by the demo's SessionsActionsTest
 * and the HIL-167 e2e. Runs under a non-production APP_ENV so the TestOnlyCommand guard
 * admits the command body.
 */
final class SessionTestExpireCommandTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private ?DbContext $previousDb = null;

    /** @var string|false APP_ENV the suite runs under, put back so this file does not decide what the next one reads */
    private string|false $previousAppEnv = false;

    protected function setUp(): void
    {
        $this->previousAppEnv = getenv('APP_ENV');
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
        $this->previousAppEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->previousAppEnv);
    }

    public function testRejectsEmptyToken(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new SessionTestExpireCommand()->execute([], []),
        );
    }

    public function testConfigErrorWhenDbMissing(): void
    {
        Hilos::$db = null;

        $this->expectOutputRegex('/not available/');
        self::assertSame(
            ExitCode::CONFIG_ERROR,
            new SessionTestExpireCommand()->execute([], ['deadbeefdeadbeefdeadbeefdeadbeef']),
        );
    }
}
