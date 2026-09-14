<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Daemon;

use Hilos\Core\Daemon\DaemonCrashReason;
use PHPUnit\Framework\TestCase;

final class DaemonCrashReasonTest extends TestCase
{
    public function testSignalTakesPrecedenceOverExitCode(): void
    {
        self::assertSame(
            'killed by signal 9, survived 0.02s. Last daemon output: (the daemon printed nothing)',
            DaemonCrashReason::render(137, 9, 0.02, '(the daemon printed nothing)'),
        );
    }

    public function testExitCodeAndTailAreRendered(): void
    {
        self::assertSame(
            'exit code 1, survived 0.44s. Last daemon output: Required environment values are missing: DB_HOST, DB_USER',
            DaemonCrashReason::render(
                1,
                null,
                0.44,
                'Required environment values are missing: DB_HOST, DB_USER',
            ),
        );
    }

    public function testUnknownExitStatusAndTwoDecimalUptimeAreRendered(): void
    {
        self::assertSame(
            'exit status unknown, survived 3.10s. Last daemon output: (the daemon printed nothing)',
            DaemonCrashReason::render(null, null, 3.1, '(the daemon printed nothing)'),
        );
    }
}
