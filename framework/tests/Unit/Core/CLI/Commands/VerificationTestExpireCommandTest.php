<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\VerificationTestExpireCommand;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Verification\VerificationType;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the verification:test:expire CLI argument-validation branches.
 *
 * Covers only the branches that return before any database access (bad args, missing
 * collection); the actual expiry behavior is exercised by the HIL-167 e2e. Runs under
 * a non-production APP_ENV so the TestOnlyCommand guard admits the command body.
 */
final class VerificationTestExpireCommandTest extends TestCase
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

    public function testRejectsUnknownType(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new VerificationTestExpireCommand()->execute([], ['not_a_type', 'user@example.com']),
        );
    }

    public function testRejectsEmptyIdentifier(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new VerificationTestExpireCommand()->execute([], [VerificationType::PASSWORD_RESET]),
        );
    }

    public function testConfigErrorWhenVerificationsCollectionMissing(): void
    {
        Hilos::$db = null;

        $this->expectOutputRegex('/not available/');
        self::assertSame(
            ExitCode::CONFIG_ERROR,
            new VerificationTestExpireCommand()->execute([], [VerificationType::PASSWORD_RESET, 'user@example.com']),
        );
    }
}
