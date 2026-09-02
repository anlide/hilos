<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\BackupRunKind;
use Hilos\Backup\BackupConstants;
use Hilos\Backup\BackupScope;
use Hilos\Backup\BackupStatus;
use Hilos\Backup\RestoreEnvDecision;
use Hilos\Backup\RestorePhase;
use Hilos\Constants\ExitCode;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\DTO\DbReHydrateOutcome;
use Hilos\Hilos;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\BackupRuntime as StateBackupRuntime;
use Hilos\Runtime\State\Item\RestoreRuntime as StateRestoreRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RestoreRuntime;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Where a restore ends now that it no longer ends with its SQL (HIL-436, HIL-694).
 *
 * The child exiting used to be the whole story: the supervisor wrote the outcome and moved the
 * node on to the verification window. But every process on the node still held caches of the
 * database that had just been replaced underneath it, so the verifiers let in through that window
 * could be reading a database that no longer existed and confirming it. The run therefore stops
 * halfway - announced, re-hydrating, unfinished - and the second half runs only once the barrier
 * has answered.
 *
 * The two properties worth breaking a build over are here: the window is asked for **only** on a
 * closed barrier, and re-reading is asked for on **both** branches of the child, because a failed
 * import may have left the database half-rewritten and re-reading an untouched one is harmless.
 *
 * The spawn-and-poll half stays at e2e, where a live child belongs. What a test reaches directly
 * is the moment the poller finds that child gone, because everything under test here happens
 * after it and has no other door.
 *
 * The wait itself has no clock of its own any more (HIL-694). It used to, on top of the daemon's,
 * and the two together are what let a verdict belonging to a finished restore close the wait of
 * the one after it. So what is pinned about the wait now is that only an answer or a shutdown
 * ends it - a tick never does.
 */
final class BackupAgentRestoreBarrierTest extends TestCase
{
    /** @var string Backup id restored in these cases */
    private const string BACKUP_ID = '2026-08-13_10-30-00';

    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new BackupAgentRestoreBarrierTestRtContext();
        Hilos::$rt->mountFeatureItem(StateRestoreRuntime::RT_ITEM, StateRestoreRuntime::create());
        // The agent clears both of its rows when it stops, so both are mounted here as the
        // backup feature mounts them.
        Hilos::$rt->mountFeatureItem(StateBackupRuntime::RT_ITEM, StateBackupRuntime::create());
        RtTruthSourceRegistry::registerDaemon(StateRestoreRuntime::RT_ITEM);
        RtTruthSourceRegistry::registerDaemon(StateBackupRuntime::RT_ITEM);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateBackupRuntime::RT_ITEM);
        RtTruthSourceRegistry::unregisterDaemon(StateRestoreRuntime::RT_ITEM);
        Hilos::$rt = null;
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testASucceededChildAnnouncesTheSwapInsteadOfFinishingTheRun(): void
    {
        $agent = $this->admittedRestore();

        $this->endRestoreChild($agent, ExitCode::SUCCESS);

        $this->assertContains(SignalTypeConstants::DB_REHYDRATE, $this->queuedSignalTypes());
        $this->assertSame(RestorePhase::REHYDRATING->value, $this->restoreRow()->phase);
        $this->assertTrue($this->restoreRow()->running, 'The run is not over until the barrier answers');
    }

    public function testAFailedChildAnnouncesTheSwapToo(): void
    {
        // The branch the old code never reached: a cut-short import may have left the database
        // half-rewritten, and the workers are holding caches of what it used to be.
        $agent = $this->admittedRestore();

        $this->endRestoreChild($agent, ExitCode::ERROR);

        $this->assertContains(SignalTypeConstants::DB_REHYDRATE, $this->queuedSignalTypes());
        $this->assertSame(RestorePhase::REHYDRATING->value, $this->restoreRow()->phase);
    }

    public function testTheVerificationWindowIsNotAskedForBeforeTheBarrierAnswers(): void
    {
        $agent = $this->admittedRestore();

        $this->endRestoreChild($agent, ExitCode::SUCCESS);

        $this->assertNotContains(
            SignalTypeConstants::PROTECTED_MODE_VERIFY,
            $this->queuedSignalTypes(),
            'A verifier let in here would be reading whatever the workers still had cached',
        );
    }

    public function testAFailedChildRecordsThatTheDatabaseMayBeHalfWritten(): void
    {
        $agent = $this->admittedRestore();

        $this->endRestoreChild($agent, ExitCode::ERROR);

        $this->assertTrue($this->restoreRow()->databaseTouched);
    }

    public function testAChildThatStoppedBeforeTheFirstDestructiveStepRecordsAnIntactDatabase(): void
    {
        // The one exit code that changes which sentence the monitor prints to whoever has to act.
        $agent = $this->admittedRestore();

        $this->endRestoreChild($agent, BackupConstants::RESTORE_EXIT_DATABASE_INTACT);

        $this->assertFalse($this->restoreRow()->databaseTouched);
    }

    public function testAClosedBarrierOpensTheWindowAndFinishesTheRun(): void
    {
        $agent = $this->admittedRestore();
        $this->endRestoreChild($agent, ExitCode::SUCCESS);
        $this->queuedSignalTypes();

        $agent->onDbReHydrateComplete(new DbReHydrateOutcome(true, []));

        $this->assertContains(SignalTypeConstants::PROTECTED_MODE_VERIFY, $this->queuedSignalTypes());
        $this->assertSame(BackupStatus::SUCCESS->value, $this->restoreRow()->outcome);
        $this->assertTrue($this->restoreRow()->rehydrateComplete);
        $this->assertFalse($this->restoreRow()->running);
    }

    public function testAnUnclosedBarrierKeepsTheNodeShutAndNamesWhoDidNotComeBack(): void
    {
        $agent = $this->admittedRestore();
        $this->endRestoreChild($agent, ExitCode::SUCCESS);
        $this->queuedSignalTypes();

        $agent->onDbReHydrateComplete(new DbReHydrateOutcome(false, ['worker #2: timeout']));

        $this->assertNotContains(
            SignalTypeConstants::PROTECTED_MODE_VERIFY,
            $this->queuedSignalTypes(),
            'Fail-closed: the node stays shut even to verifiers, and a human decides what to do',
        );
        $this->assertSame(BackupStatus::ERROR->value, $this->restoreRow()->outcome);
        $this->assertFalse($this->restoreRow()->rehydrateComplete);
        $this->assertSame(['worker #2: timeout'], $this->restoreRow()->rehydrateProblems);
    }

    public function testAnSqlSuccessDoesNotSurviveAnUnclosedBarrier(): void
    {
        // The terminal outcome belongs to the node, not to the child: a restore whose SQL replayed
        // cleanly but whose node did not finish re-reading has not come back.
        $agent = $this->admittedRestore();
        $this->endRestoreChild($agent, ExitCode::SUCCESS);

        $agent->onDbReHydrateComplete(new DbReHydrateOutcome(false, ['node-b: timeout']));

        $this->assertSame(BackupStatus::ERROR->value, $this->restoreRow()->outcome);
        $this->assertStringContainsString('node-b: timeout', (string)$this->restoreRow()->failureReason);
    }

    public function testAFailedRunThatCameBackWholeStillOpensTheWindow(): void
    {
        // However the run ended, a node left in the full freeze has nobody else to move it on
        // (HIL-481); what the barrier decides is whether it may be moved on at all.
        $agent = $this->admittedRestore();
        $this->endRestoreChild($agent, ExitCode::ERROR);
        $this->queuedSignalTypes();

        $agent->onDbReHydrateComplete(new DbReHydrateOutcome(true, []));

        $this->assertContains(SignalTypeConstants::PROTECTED_MODE_VERIFY, $this->queuedSignalTypes());
        $this->assertSame(BackupStatus::ERROR->value, $this->restoreRow()->outcome);
    }

    public function testAVerdictArrivingAfterTheWaitIsOverIsDropped(): void
    {
        // Re-finishing a run that is over would report an outcome twice and drain the pending
        // create slot into a second child.
        $agent = $this->admittedRestore();
        $this->endRestoreChild($agent, ExitCode::SUCCESS);
        $agent->onDbReHydrateComplete(new DbReHydrateOutcome(true, []));
        $this->queuedSignalTypes();

        $agent->onDbReHydrateComplete(new DbReHydrateOutcome(false, ['worker #2: timeout']));

        $this->assertNotContains(SignalTypeConstants::PROTECTED_MODE_VERIFY, $this->queuedSignalTypes());
        $this->assertSame(BackupStatus::SUCCESS->value, $this->restoreRow()->outcome);
    }

    public function testAVerdictArrivingAfterTheAgentStoppedIsDroppedToo(): void
    {
        // The other way the wait ends, and since HIL-694 the only other way: this agent no longer
        // gives up on its own clock, so a wait it is still holding is ended by a verdict or by its
        // own shutdown. A verdict landing after that shutdown must not re-finish the run.
        $agent = $this->admittedRestore();
        $this->endRestoreChild($agent, ExitCode::SUCCESS);
        $agent->onStop();
        $this->queuedSignalTypes();

        $agent->onDbReHydrateComplete(new DbReHydrateOutcome(true, []));

        $this->assertNotContains(SignalTypeConstants::PROTECTED_MODE_VERIFY, $this->queuedSignalTypes());
        $this->assertSame(BackupStatus::ERROR->value, $this->restoreRow()->outcome);
        $this->assertFalse($this->restoreRow()->rehydrateComplete);
    }

    public function testAWaitTheDaemonHasNotAnsweredYetSurvivesAnyNumberOfTicks(): void
    {
        // The agent used to hold a second deadline over the same barrier, and two timers over one
        // barrier is what let a verdict of a finished restore close the wait of the next one. The
        // daemon's round always answers - on a verdict, on its own deadline, or on being
        // superseded - so the wait here simply lasts until it does.
        $agent = $this->admittedRestore();
        $this->endRestoreChild($agent, ExitCode::SUCCESS);
        $this->queuedSignalTypes();

        $agent->onTick();
        $agent->onTick();

        $this->assertSame(
            RestorePhase::REHYDRATING->value,
            $this->restoreRow()->phase,
            'Ticking is not an answer, and nothing else here decides the barrier',
        );
        $this->assertNotContains(SignalTypeConstants::PROTECTED_MODE_VERIFY, $this->queuedSignalTypes());
    }

    public function testAnAgentStoppingMidRestoreLeavesTheNodeShut(): void
    {
        $agent = $this->admittedRestore();
        $this->queuedSignalTypes();

        $agent->onStop();

        $this->assertNotContains(SignalTypeConstants::PROTECTED_MODE_VERIFY, $this->queuedSignalTypes());
        $this->assertSame(BackupStatus::ERROR->value, $this->restoreRow()->outcome);
        $this->assertFalse($this->restoreRow()->rehydrateComplete);
    }

    public function testAnAgentStoppingBeforeItsChildRanLeavesTheDatabaseMarkedIntact(): void
    {
        // A restore still waiting for its freeze has spawned nothing, so there is nothing to look
        // at; telling the operator otherwise sends them auditing a database nobody touched.
        $agent = $this->admittedRestore();

        $agent->onStop();

        $this->assertFalse($this->restoreRow()->databaseTouched);
    }

    public function testAnAgentStoppingDuringTheBarrierKeepsWhatItsChildAlreadyDid(): void
    {
        // The child half is over and recorded by then; putting the run through the finalizer a
        // second time would re-announce the swap and overwrite the record with the stop.
        $agent = $this->admittedRestore();
        $this->endRestoreChild($agent, ExitCode::SUCCESS);
        $announcements = $this->queuedSignalTypes();

        $agent->onStop();

        $announcements = [...$announcements, ...$this->queuedSignalTypes()];
        $this->assertSame(
            [SignalTypeConstants::DB_REHYDRATE],
            array_values(array_filter(
                $announcements,
                static fn(string $type) => $type === SignalTypeConstants::DB_REHYDRATE,
            )),
            'One swap is announced once',
        );
        $this->assertStringNotContainsString('stopped during restore', (string)$this->restoreRow()->failureReason);
    }

    public function testADuplicateFreezeReadyDoesNotSpawnASecondChildOverTheBarrier(): void
    {
        // The child slot stands free between the child's exit and the verdict, and a second child
        // let in there would replay the archive over the database the first one just wrote.
        $agent = $this->admittedRestore();
        $this->endRestoreChild($agent, ExitCode::SUCCESS);

        $agent->onProtectedModeReady();

        $this->assertSame(
            RestorePhase::REHYDRATING->value,
            $this->restoreRow()->phase,
            'A spawn would have moved the row back to the phase a child opens with',
        );
    }

    public function testTheLengthWrittenBackCoversTheFreezeTheOperatorWaitedThrough(): void
    {
        $agent = $this->admittedRestore();
        $this->rewindRun($agent, admittedSecondsAgo: 100.0, spawnedSecondsAgo: 40.0);

        // What is recorded on the archive is what the NEXT restore is estimated from, and every
        // surface counts its elapsed from the instant of admission - the freeze included. Measured
        // from the spawn instead, the estimate would come out a freeze shorter than the span it is
        // compared against, and a normal run would report itself late every time.
        $this->assertSame(100, $this->restoreElapsed($agent));
    }

    /**
     * Moves an admitted restore's two instants into the past, independently of each other.
     *
     * They are separate on purpose: the gap between them is the freeze, and it is the whole
     * subject of the case above.
     *
     * @param BackupAgent $agent Agent holding an admitted restore
     * @param float $admittedSecondsAgo How long ago the restore was admitted
     * @param float $spawnedSecondsAgo How long ago its child was spawned
     */
    private function rewindRun(
        BackupAgent $agent,
        float $admittedSecondsAgo,
        float $spawnedSecondsAgo,
    ): void {
        $rewind = Closure::bind(
            static function (BackupAgent $agent, float $admitted, float $spawned): void {
                $agent->pendingRestoreSince = microtime(true) - $admitted;
                $agent->startedAt = microtime(true) - $spawned;
            },
            null,
            BackupAgent::class,
        );

        $rewind($agent, $admittedSecondsAgo, $spawnedSecondsAgo);
    }

    /**
     * The span the agent would write back as the length of this restore.
     *
     * @param BackupAgent $agent Agent holding an admitted restore
     * @return int Seconds the agent counts the run as having taken
     */
    private function restoreElapsed(BackupAgent $agent): int
    {
        $read = Closure::bind(
            static fn (BackupAgent $agent): int => $agent->restoreElapsedSeconds(),
            null,
            BackupAgent::class,
        );

        return $read($agent);
    }

    /**
     * Builds an agent standing where an admitted restore leaves it: the row is running and the
     * run is engaged, with the freeze and the child still ahead of it.
     *
     * Admission itself belongs to HIL-274 and is driven from the command channel, which reads
     * project-level configuration a framework test has no catalog for; what is under test here
     * begins after the child, so the state that path produces is set instead of re-enacted.
     *
     * @return BackupAgent Agent holding an admitted restore
     * @throws RtActionsCollectionNameNullException When the restore row's collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the test is not the row's truth source
     */
    private function admittedRestore(): BackupAgent
    {
        $this->restoreRow()->actions->markRunning(self::BACKUP_ID, BackupScope::FULL);

        $agent = new BackupAgent();
        $enter = Closure::bind(
            static function (BackupAgent $agent, string $backupId): void {
                $agent->pendingRestoreId = $backupId;
                $agent->pendingRestoreScope = BackupScope::FULL;
                $agent->pendingRestoreDecision = RestoreEnvDecision::ALLOW;
                $agent->pendingRestoreSince = microtime(true);
            },
            null,
            BackupAgent::class,
        );

        $enter($agent, self::BACKUP_ID);

        return $agent;
    }

    /**
     * Ends the restore child the way the poller does when it finds the process gone.
     *
     * The spawn is skipped, not faked: what it leaves behind is this run state, and driving a live
     * child from a unit test would say less about more. Reached through a bound closure rather
     * than reflection, the way the worker-dispatch cases already reach their private drain.
     *
     * @param BackupAgent $agent Agent whose restore child has just exited
     * @param ?int $exitCode Exit code the child reported, or null when it was killed
     */
    private function endRestoreChild(BackupAgent $agent, ?int $exitCode): void
    {
        $end = Closure::bind(
            static function (BackupAgent $agent, ?int $exitCode): void {
                $agent->currentBackupId = $agent->pendingRestoreId;
                $agent->runKind = BackupRunKind::RESTORE;
                $agent->startedAt = microtime(true);
                $agent->finishChild(
                    $exitCode === ExitCode::SUCCESS,
                    $exitCode === ExitCode::SUCCESS ? null : 'child exited with code ' . $exitCode,
                    $exitCode,
                );
            },
            null,
            BackupAgent::class,
        );

        $end($agent, $exitCode);
    }

    /**
     * Drains the queue and names what the agent asked for since the last drain.
     *
     * @return list<string> Signal types queued since the last drain, in order
     */
    private function queuedSignalTypes(): array
    {
        $types = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $types[] = $signal->signalType->getType();
        }

        return $types;
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
 * Runtime context that registers no project state: the framework mount supplies the restore row.
 */
final class BackupAgentRestoreBarrierTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}
