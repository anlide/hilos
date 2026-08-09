<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Constants\ExitCode;
use Hilos\Core\CLI\Commands\NotificationTestEmitCommand;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Notification\NotificationSeverity;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the test:notification:emit CLI: channel-list parsing and the
 * argument-validation branches that return before the command channel is opened. The
 * emit itself needs a running daemon and is exercised by the audit run; the
 * production-env guard is covered by TestOnlyCommandGuardTest. Runs under a
 * non-production APP_ENV so the TestOnlyCommand guard admits the command body.
 */
final class NotificationTestEmitCommandTest extends TestCase
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

    public function testParsesChannelListTrimmingBlanks(): void
    {
        self::assertSame(['email'], NotificationTestEmitCommand::parseChannelList('email'));
        self::assertSame(['email', 'sms'], NotificationTestEmitCommand::parseChannelList('email,sms'));
        // Spaces around a name survive a shell quoting the whole option value.
        self::assertSame(['email', 'sms'], NotificationTestEmitCommand::parseChannelList(' email , sms '));
        // A trailing separator names no channel and must not become an empty one.
        self::assertSame(['email'], NotificationTestEmitCommand::parseChannelList('email,'));
        self::assertSame([], NotificationTestEmitCommand::parseChannelList(' , '));
    }

    public function testRejectsMissingUserId(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new NotificationTestEmitCommand()->execute([], []),
        );
    }

    public function testRejectsNonIntegerUserId(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new NotificationTestEmitCommand()->execute([], ['abc', 'audit.check', 'Probe']),
        );
    }

    public function testRejectsZeroUserId(): void
    {
        $this->expectOutputRegex('/Usage/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new NotificationTestEmitCommand()->execute([], ['0', 'audit.check', 'Probe']),
        );
    }

    public function testRejectsMissingType(): void
    {
        $this->expectOutputRegex('/<type>/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new NotificationTestEmitCommand()->execute([], ['1']),
        );
    }

    public function testRejectsMissingTitle(): void
    {
        $this->expectOutputRegex('/<title>/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new NotificationTestEmitCommand()->execute([], ['1', 'audit.check']),
        );
    }

    public function testRejectsEmptyBody(): void
    {
        $this->expectOutputRegex('/--body/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new NotificationTestEmitCommand()->execute(['body' => ''], ['1', 'audit.check', 'Probe']),
        );
    }

    public function testRejectsUnknownSeverity(): void
    {
        // Rejected rather than silently downgraded: the emit seam falls back to info, so a
        // fixture asking for "critical" would otherwise pass while storing something else.
        $this->expectOutputRegex('/--severity/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new NotificationTestEmitCommand()->execute(['severity' => 'critical'], ['1', 'audit.check', 'Probe']),
        );
        self::assertNotContains('critical', NotificationSeverity::ALL);
    }

    public function testRejectsChannelsOptionNamingNoChannel(): void
    {
        $this->expectOutputRegex('/--channels/');
        self::assertSame(
            ExitCode::INVALID_ARGUMENT,
            new NotificationTestEmitCommand()->execute(['channels' => ' , '], ['1', 'audit.check', 'Probe']),
        );
    }
}
