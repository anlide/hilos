<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\Daemon;

use Hilos\Core\Daemon\DaemonHangMonitor;
use Hilos\Core\Daemon\MasterHeartbeat;
use PHPUnit\Framework\TestCase;

final class DaemonHangMonitorTest extends TestCase
{
    public function testNoJudgmentBeforeTheFirstMark(): void
    {
        $monitor = new DaemonHangMonitor();
        self::assertNull($monitor->staleAt());
        self::assertFalse($monitor->isStale(10000.0));
        self::assertNull($monitor->killAt());
        self::assertSame(10.0, MasterHeartbeat::INTERVAL_SECONDS);
        self::assertSame(60.0, MasterHeartbeat::STALE_SECONDS);
        self::assertSame(5.0, MasterHeartbeat::STOP_GRACE_SECONDS);
    }

    public function testExactStaleBoundaryAndMarkRefresh(): void
    {
        $monitor = new DaemonHangMonitor();
        $monitor->markReceived(100.0);
        self::assertSame(160.0, $monitor->staleAt());
        self::assertFalse($monitor->isStale(159.999));
        self::assertTrue($monitor->isStale(160.0));
        $monitor->markReceived(160.0);
        self::assertSame(220.0, $monitor->staleAt());
        self::assertFalse($monitor->isStale(160.0));
        self::assertSame(10.0, $monitor->silenceSeconds(170.0));
    }

    public function testNormalControlSuspendsDetection(): void
    {
        $monitor = new DaemonHangMonitor();
        $monitor->markReceived(0.0);
        $monitor->suspend();
        $monitor->markReceived(100.0);
        $monitor->beginStop(100.0);
        self::assertNull($monitor->staleAt());
        self::assertNull($monitor->killAt());
        self::assertFalse($monitor->isStale(1000.0));
    }

    public function testGraceIsOneShotAndKillRetiresItsDeadline(): void
    {
        $monitor = new DaemonHangMonitor();
        $monitor->markReceived(0.0);
        $monitor->beginStop(60.0);
        $monitor->beginStop(62.0);
        self::assertSame(65.0, $monitor->killAt());
        self::assertTrue($monitor->isStopping());
        self::assertNull($monitor->staleAt());
        $monitor->markKilled();
        self::assertNull($monitor->killAt());
        $monitor->beginStop(200.0);
        self::assertNull($monitor->killAt());
    }

    public function testLateMarkAndTerminalStopDoNotCancelHangGrace(): void
    {
        $monitor = new DaemonHangMonitor();
        $monitor->markReceived(0.0);
        $monitor->beginStop(60.0);
        $monitor->markReceived(64.0);
        $monitor->suspend();
        self::assertSame(65.0, $monitor->killAt());
        self::assertSame(64.0, $monitor->silenceSeconds(64.0));
    }

    public function testResetStartsAnUnarmedGeneration(): void
    {
        $monitor = new DaemonHangMonitor();
        $monitor->markReceived(0.0);
        $monitor->beginStop(60.0);
        $monitor->markKilled();
        $monitor->suspend();
        $monitor->reset();
        self::assertNull($monitor->staleAt());
        self::assertNull($monitor->killAt());
        self::assertFalse($monitor->isStopping());
        $monitor->markReceived(100.0);
        self::assertSame(160.0, $monitor->staleAt());
    }

    public function testBringToNowOnlyChangesAnAlreadyActiveDeadline(): void
    {
        $monitor = new DaemonHangMonitor();
        $monitor->bringStaleToNow(1.0);
        $monitor->bringStopToNow(1.0);
        $monitor->markReceived(100.0);
        self::assertSame(160.0, $monitor->staleAt());
        self::assertNull($monitor->killAt());
        $monitor->bringStaleToNow(120.0);
        self::assertSame(120.0, $monitor->staleAt());
        self::assertTrue($monitor->isStale(120.0));
        $monitor->bringStaleToNow(121.0);
        self::assertSame(120.0, $monitor->staleAt());
        $monitor->beginStop(120.0);
        self::assertSame(125.0, $monitor->killAt());
        $monitor->bringStopToNow(122.0);
        $monitor->bringStopToNow(123.0);
        self::assertSame(122.0, $monitor->killAt());
    }

    public function testWrongPhaseRequestsCannotSurviveKillOrReset(): void
    {
        $monitor = new DaemonHangMonitor();
        $monitor->markReceived(0.0);
        $monitor->bringStopToNow(1.0);
        $monitor->beginStop(60.0);
        $monitor->bringStaleToNow(61.0);
        self::assertNull($monitor->staleAt());
        self::assertSame(65.0, $monitor->killAt());
        $monitor->markKilled();
        $monitor->bringStopToNow(62.0);
        self::assertNull($monitor->killAt());
        $monitor->reset();
        $monitor->beginStop(100.0);
        self::assertNull($monitor->killAt());
    }
}
