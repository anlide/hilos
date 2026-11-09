<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Runtime;

use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Item\ProtectedModeRuntime as ViewProtectedModeRuntime;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * What the master does with a frame of its own protected mode row that arrives after the row has
 * moved on (HIL-2085).
 *
 * The self-echo mark is one per row, so an older frame still queued spends it and the echo of
 * `deactivating` reaches the apply path over a row that already reads `inactive`. The chain cases
 * unroll the frames the row's own actions queue on a real router, because the defect lives in the
 * order they are written and applied in. Everything else is pinned as applied exactly as before.
 */
final class RtSyncApplicatorLateProtectedModeEchoTest extends TestCase
{
    /** The words every drop line carries, searched for in the cases that must stay silent. */
    private const string DROP_LINE = 'dropped a late echo';

    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$rt = new FrameworkRuntimeSingletonTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testALateEchoDoesNotTakeALiftedRowBack(): void
    {
        $this->arrangeStandingFreeze();
        $this->writeDiagnostics(3);
        $this->lift();
        $diagnosticsEcho = $this->nextQueuedFrame();
        $deactivatingEcho = $this->nextQueuedFrame();
        $inactiveEcho = $this->nextQueuedFrame();

        $logged = $this->logOf(function () use ($diagnosticsEcho, $deactivatingEcho, $inactiveEcho): void {
            RtSyncApplicator::applyUpdated($diagnosticsEcho);
            RtSyncApplicator::applyUpdated($deactivatingEcho);
            $this->writeDiagnostics(0);
            RtSyncApplicator::applyUpdated($inactiveEcho);
        });

        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, $this->row()->phase);
        $this->assertNull($this->row()->operation);
        $this->assertSame(1, substr_count($logged, self::DROP_LINE));
        $this->assertStringContainsString(
            "Protected mode: dropped a late echo moving the phase from 'active' to 'deactivating' while the row reads 'inactive'",
            $logged,
        );
    }

    public function testAnOrdinaryLiftEndsInactiveAndSaysNothing(): void
    {
        $this->arrangeStandingFreeze();
        $this->lift();
        $deactivatingEcho = $this->nextQueuedFrame();
        $inactiveEcho = $this->nextQueuedFrame();

        $logged = $this->logOf(static function () use ($deactivatingEcho, $inactiveEcho): void {
            RtSyncApplicator::applyUpdated($deactivatingEcho);
            RtSyncApplicator::applyUpdated($inactiveEcho);
        });

        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, $this->row()->phase);
        $this->assertNull($this->row()->operation);
        $this->assertStringNotContainsString(self::DROP_LINE, $logged);
    }

    public function testTheWorkerPathAppliesAFrameWithoutJudgingIt(): void
    {
        $this->arrangeStandingFreeze();
        $this->lift();
        $deactivatingEcho = $this->nextQueuedFrame();

        $logged = $this->logOf(static function () use ($deactivatingEcho): void {
            RtSyncApplicator::applyUpdated($deactivatingEcho, skipSelfBroadcastCheck: false);
        });

        $this->assertSame(StateProtectedModeRuntime::PHASE_DEACTIVATING, $this->row()->phase);
        $this->assertStringNotContainsString(self::DROP_LINE, $logged);
    }

    public function testAFrameCarryingNoPhaseIsApplied(): void
    {
        $frame = $this->frame(
            [StateProtectedModeRuntime::stoppedAgentCount => 7],
            [StateProtectedModeRuntime::stoppedAgentCount => 0],
        );

        $logged = $this->logOf(static function () use ($frame): void {
            RtSyncApplicator::applyUpdated($frame);
        });

        $this->assertSame(7, $this->row()->stoppedAgentCount);
        $this->assertStringNotContainsString(self::DROP_LINE, $logged);
    }

    public function testAFrameWithoutThePreviousPhaseIsApplied(): void
    {
        $frame = $this->frame([StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_DEACTIVATING], []);

        $logged = $this->logOf(static function () use ($frame): void {
            RtSyncApplicator::applyUpdated($frame);
        });

        $this->assertSame(StateProtectedModeRuntime::PHASE_DEACTIVATING, $this->row()->phase);
        $this->assertStringNotContainsString(self::DROP_LINE, $logged);
    }

    public function testAFrameMovingOnFromTheRowsPhaseIsApplied(): void
    {
        $this->arrangeStandingFreeze();
        $frame = $this->frame(
            [StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_DEACTIVATING],
            [StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_ACTIVE],
        );

        $logged = $this->logOf(static function () use ($frame): void {
            RtSyncApplicator::applyUpdated($frame);
        });

        $this->assertSame(StateProtectedModeRuntime::PHASE_DEACTIVATING, $this->row()->phase);
        $this->assertStringNotContainsString(self::DROP_LINE, $logged);
    }

    public function testAPhaseThatIsNotAStringIsLeftForTheRowToRefuse(): void
    {
        $frame = $this->frame(
            [StateProtectedModeRuntime::phase => ['not', 'a', 'phase']],
            [StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_ACTIVE],
        );

        $logged = $this->logOf(static function () use ($frame): void {
            RtSyncApplicator::applyUpdated($frame);
        });

        $this->assertSame(StateProtectedModeRuntime::PHASE_INACTIVE, $this->row()->phase);
        $this->assertStringContainsString('RT sync row refused', $logged);
        $this->assertStringNotContainsString(self::DROP_LINE, $logged);
    }

    public function testAFrameOfAnotherRowIsNotJudged(): void
    {
        $frame = new RtSyncUpdatedSignalData(
            'someOtherRow',
            StateProtectedModeRuntime::ID,
            [StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_DEACTIVATING],
            previous: [StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_ACTIVE],
        );

        $logged = $this->logOf(static function () use ($frame): void {
            RtSyncApplicator::applyUpdated($frame);
        });

        $this->assertStringNotContainsString(self::DROP_LINE, $logged);
    }

    /**
     * Puts the row under a standing freeze before any router is installed, so the arrangement
     * leaves neither a frame nor a self-echo mark behind for the case to trip over.
     */
    private function arrangeStandingFreeze(): void
    {
        $this->asTheMaster(function (): void {
            $this->row()->actions->enterActivating(new ProtectedModeQuiesceData('restore', 'backup', 0, null, null), null);
            $this->row()->actions->enterActive();
        });
        Hilos::$sr = new SignalRouter();
    }

    /**
     * Writes both phases of a lift in one call, the way the executor does.
     */
    private function lift(): void
    {
        $this->asTheMaster(function (): void {
            $this->row()->actions->enterDeactivating();
            $this->row()->actions->enterInactive();
        });
    }

    /**
     * Queues a diagnostics frame of the row; a phase never travels in one.
     *
     * @param int $stoppedAgentCount Count the frame carries, different from the last one written
     */
    private function writeDiagnostics(int $stoppedAgentCount): void
    {
        $this->asTheMaster(function () use ($stoppedAgentCount): void {
            $this->row()->actions->updateAgentDiagnostics('node-a', $stoppedAgentCount, [], true);
        });
    }

    /**
     * Runs a write with the daemon registered as the row's truth source, and drops it after.
     *
     * @param callable(): void $write Write the row accepts from its truth source only
     */
    private function asTheMaster(callable $write): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        try {
            $write();
        } finally {
            RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);
        }
    }

    /**
     * @return RtSyncUpdatedSignalData The frame next in line on the router
     */
    private function nextQueuedFrame(): RtSyncUpdatedSignalData
    {
        $frame = Hilos::$sr?->getNextQueuedSignal()?->data;
        $this->assertInstanceOf(RtSyncUpdatedSignalData::class, $frame);

        return $frame;
    }

    /**
     * @param array<string, mixed> $row Fields the frame changes
     * @param array<string, mixed> $previous Values those fields held before it
     * @return RtSyncUpdatedSignalData Hand-built frame of the protected mode row
     */
    private function frame(array $row, array $previous): RtSyncUpdatedSignalData
    {
        return new RtSyncUpdatedSignalData(
            StateProtectedModeRuntime::RT_ITEM,
            StateProtectedModeRuntime::ID,
            $row,
            previous: $previous,
        );
    }

    /**
     * @return ViewProtectedModeRuntime The mounted protected mode row
     */
    private function row(): ViewProtectedModeRuntime
    {
        $row = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertInstanceOf(ViewProtectedModeRuntime::class, $row);

        return $row;
    }

    /**
     * @param callable(): void $applying Steps whose log lines are wanted
     * @return string Everything the steps logged
     */
    private function logOf(callable $applying): string
    {
        ob_start();
        try {
            $applying();
        } finally {
            $logged = (string)ob_get_clean();
        }

        return $logged;
    }
}
