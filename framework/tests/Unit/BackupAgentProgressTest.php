<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\BackupRunKind;
use Hilos\Backup\BackupPhase;
use Hilos\Backup\BackupProgressMarker;
use Hilos\Backup\BackupScope;
use Hilos\Backup\RestorePhase;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\State\Item\RestoreRuntime as StateRestoreRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\BackupRuntime;
use Hilos\Runtime\View\Item\RestoreRuntime;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The phase channel between a backup child and the runtime row its progress is drawn from.
 *
 * The supervisor learns where a run is by reading lines off the child's stdout, which is the one
 * place the whole feature can break quietly: a chunk that arrives split, a line that belongs to
 * nobody, a phase from the other kind of run. Each of those is a case here, driven through the
 * private consumer with the bound-closure technique {@see BackupAgentRestoreBarrierTest} uses -
 * a live child would test the pipe instead of the protocol.
 */
final class BackupAgentProgressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new BackupAgentProgressTestRtContext();
        Hilos::$rt->mountFeatureItem(StateBackupRuntime::RT_ITEM, StateBackupRuntime::create());
        Hilos::$rt->mountFeatureItem(StateRestoreRuntime::RT_ITEM, StateRestoreRuntime::create());
        RtTruthSourceRegistry::registerDaemon(StateBackupRuntime::RT_ITEM);
        RtTruthSourceRegistry::registerDaemon(StateRestoreRuntime::RT_ITEM);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateRestoreRuntime::RT_ITEM);
        RtTruthSourceRegistry::unregisterDaemon(StateBackupRuntime::RT_ITEM);
        Hilos::$rt = null;
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testAnAnnouncedPhaseReachesTheRunningRow(): void
    {
        $this->backupRow()->actions->markRunning('2026-08-15_10-30-00', BackupScope::FULL, 120);

        $this->feed(BackupRunKind::CREATE, BackupProgressMarker::statement(BackupPhase::ARCHIVING->value));

        $this->assertSame(BackupPhase::ARCHIVING->value, $this->backupRow()->phase);
        $this->assertNotNull(
            $this->backupRow()->phaseStartedAt,
            'A phase with no instant behind it is a bar that cannot move inside the phase',
        );
    }

    public function testAPhaseSplitAcrossTwoChunksStillArrives(): void
    {
        $this->backupRow()->actions->markRunning('2026-08-15_10-30-00', BackupScope::FULL, 120);
        $whole = BackupProgressMarker::statement(BackupPhase::DUMPING->value);

        $agent = $this->feed(BackupRunKind::CREATE, substr($whole, 0, 6));
        $this->assertNull($this->backupRow()->phase, 'Half a line names no phase yet');

        $this->feedAgent($agent, BackupRunKind::CREATE, substr($whole, 6));
        $this->assertSame(BackupPhase::DUMPING->value, $this->backupRow()->phase);
    }

    public function testWhatIsNotAPhaseDoesNotMoveTheRow(): void
    {
        $this->backupRow()->actions->markRunning('2026-08-15_10-30-00', BackupScope::FULL, 120);

        $this->feed(
            BackupRunKind::CREATE,
            "Backup created: /var/backups/full-2026-08-15.tar.gz\n"
            . BackupProgressMarker::statement('teleporting')
            // A restore phase during a create run: the child of the other kind, or a stray line.
            . BackupProgressMarker::statement(RestorePhase::IMPORTING->value),
        );

        $this->assertNull($this->backupRow()->phase);
    }

    public function testARestoreChildMovesTheRestoreRow(): void
    {
        $this->restoreRow()->actions->markRunning('2026-08-15_10-30-00', BackupScope::FULL, 600);

        $this->feed(
            BackupRunKind::RESTORE,
            BackupProgressMarker::statement(RestorePhase::VERIFYING->value)
            . BackupProgressMarker::statement(RestorePhase::EXTRACTING->value),
        );

        $this->assertSame(RestorePhase::EXTRACTING->value, $this->restoreRow()->phase);
    }

    public function testTheEstimateRidesTheRowFromTheMomentTheRunStarts(): void
    {
        $this->backupRow()->actions->markRunning('2026-08-15_10-30-00', BackupScope::FULL, 120);
        $this->restoreRow()->actions->markRunning('2026-08-15_10-30-00', BackupScope::FULL, 600);

        $this->assertSame(120, $this->backupRow()->estimatedSeconds);
        $this->assertSame(600, $this->restoreRow()->estimatedSeconds);
    }

    public function testAFinishedRunTakesItsProgressAnchorsWithIt(): void
    {
        $this->backupRow()->actions->markRunning('2026-08-15_10-30-00', BackupScope::FULL, 120);
        $this->feed(BackupRunKind::CREATE, BackupProgressMarker::statement(BackupPhase::PUBLISHING->value));

        $this->backupRow()->actions->clearRunning();

        $this->assertNull($this->backupRow()->phase, 'A bar left behind by a finished run never empties');
        $this->assertNull($this->backupRow()->phaseStartedAt);
        $this->assertNull($this->backupRow()->estimatedSeconds);
    }

    /**
     * Hands a chunk of child stdout to a fresh supervisor running the given kind.
     *
     * @param BackupRunKind $kind What the in-flight child is doing
     * @param string $chunk Whatever the child wrote to stdout
     * @return BackupAgent The supervisor that consumed it, for a follow-up chunk
     * @throws RtActionsCollectionNameNullException When the row's collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the test is not the row's truth source
     */
    private function feed(BackupRunKind $kind, string $chunk): BackupAgent
    {
        $agent = new BackupAgent();
        $this->feedAgent($agent, $kind, $chunk);

        return $agent;
    }

    /**
     * Hands a chunk of child stdout to a supervisor that may already hold half a line.
     *
     * @param BackupAgent $agent Supervisor consuming the chunk
     * @param BackupRunKind $kind What the in-flight child is doing
     * @param string $chunk Whatever the child wrote to stdout
     * @throws RtActionsCollectionNameNullException When the row's collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the test is not the row's truth source
     */
    private function feedAgent(BackupAgent $agent, BackupRunKind $kind, string $chunk): void
    {
        $feed = Closure::bind(
            static function (BackupAgent $agent, BackupRunKind $kind, string $chunk): void {
                $agent->runKind = $kind;
                $agent->consumeChildProgress($chunk);
            },
            null,
            BackupAgent::class,
        );

        $feed($agent, $kind, $chunk);
    }

    /**
     * @return BackupRuntime The mounted backup runtime row
     */
    private function backupRow(): BackupRuntime
    {
        $view = Hilos::$rt?->hilosBackupRuntime;

        return $view instanceof BackupRuntime
            ? $view
            : throw new RuntimeException('The backup runtime singleton is not mounted.');
    }

    /**
     * @return RestoreRuntime The mounted restore runtime row
     */
    private function restoreRow(): RestoreRuntime
    {
        $view = Hilos::$rt?->hilosRestoreRuntime;

        return $view instanceof RestoreRuntime
            ? $view
            : throw new RuntimeException('The restore runtime singleton is not mounted.');
    }
}

/**
 * Runtime context that registers no project state: the framework mount supplies both rows.
 */
final class BackupAgentProgressTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}
