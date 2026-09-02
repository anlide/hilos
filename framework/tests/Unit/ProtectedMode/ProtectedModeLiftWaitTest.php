<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Auth\Session\DTO\SessionCarryOverDeferredSignalData;
use Hilos\Auth\Session\DTO\SessionCarryOverDoneSignalData;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\DTO\ProtectedModeStateSignalData;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\ProtectedMode\ProtectedModeClientNotifier;
use Hilos\ProtectedMode\ProtectedModeLiftAnnouncer;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Worker\DTO\WorkerSessionCarryOverDeferredDTO;
use Hilos\Socket\Worker\DTO\WorkerSessionCarryOverDoneDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the one push protected mode may hold back: the lift (HIL-771).
 *
 * "The freeze lifted" means "reload" to every browser that hears it, and a reload asks for the
 * session token. After a restore that token only survives because the restore photographed it
 * before the swap and the sessions library re-created the row afterwards - so a lift announced
 * ahead of that pass signs people out of a system that could have kept them logged in.
 *
 * What the wait is armed by is a debt reported by the node that took it on, never a guess: the
 * cases below pin that a node owed nothing lifts with no delay (which is every node outside the
 * moments after a restore), that a node owed something waits for the answer and not for the clock,
 * that the clock still wins in the end, and that a debt nobody ever answered for cannot follow the
 * node into its next freeze.
 */
final class ProtectedModeLiftWaitTest extends TestCase
{
    /** @var int Seconds past the hold after which the wait has certainly run out */
    private const int PAST_THE_WAIT = 60;

    private DaemonProtectedModeExecutor $executor;

    private ProtectedModeLiftAnnouncer $announcer;

    private LiftWaitClientNotifier $notifier;

    /** Daemon log directory the executor leaves the freeze file in */
    private string $logDirectory = '';

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        // Every phase the executor writes is also left on disk, so these cases need a directory
        // for it: without one each transition would log a failure of the freeze store, which has
        // nothing to do with the frames asserted here.
        $this->logDirectory = (string)tempnam(sys_get_temp_dir(), 'hilos-lift-wait');
        unlink($this->logDirectory);
        mkdir($this->logDirectory);
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->logDirectory . '/daemon.log');

        $this->executor = new DaemonProtectedModeExecutor();
        $this->announcer = new ProtectedModeLiftAnnouncer();
        $this->notifier = new LiftWaitClientNotifier();

        Hilos::$rt = new LiftWaitTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerProtectedModeClientNotifier($this->notifier);
        Hilos::$cluster->registerProtectedModeLiftAnnouncer($this->announcer);
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);
        Hilos::$rt = null;
        Hilos::$cluster = null;

        foreach ((array)glob($this->logDirectory . '/*') as $leftover) {
            unlink((string)$leftover);
        }
        rmdir($this->logDirectory);
        putenv(EnvConstants::DAEMON_LOG_FILE->name);
        Hilos::$env = $this->previousEnv;

        parent::tearDown();
    }

    public function testANodeOwedNothingLiftsWithNoWaitAtAll(): void
    {
        // The ordinary case, and the one that must stay free: a freeze nobody restored under, and
        // every follower of one that somebody did.
        $this->freezeAndLift();

        $this->assertCount(1, $this->notifier->frames);
        $this->assertFalse($this->notifier->frames[0]->active);
    }

    public function testTheLiftWaitsForTheLoginsARestoreLeftBehind(): void
    {
        $this->executor->enterActivating($this->freeze(), 'accept-7', null);
        $this->announcer->noteSessionsDeferred(3);
        $this->notifier->frames = [];

        $this->executor->enterDeactivating();
        $this->executor->enterInactive();

        $this->assertSame([], $this->notifier->frames);
        // The freeze itself is over regardless - what waits is only the sentence to the browsers.
        $this->assertSame(
            StateProtectedModeRuntime::PHASE_INACTIVE,
            Hilos::$rt?->hilosProtectedModeRuntime?->phase,
        );
    }

    public function testTheAnswerReleasesTheHeldLift(): void
    {
        $this->executor->enterActivating($this->freeze(), 'accept-7', null);
        $this->announcer->noteSessionsDeferred(3);
        $this->notifier->frames = [];
        $this->executor->enterDeactivating();
        $this->executor->enterInactive();

        $this->announcer->noteSessionsCarriedOver(2, 1);

        $this->assertCount(1, $this->notifier->frames);
        $this->assertFalse($this->notifier->frames[0]->active);
    }

    public function testAFailedPassStillReleasesTheLift(): void
    {
        // The library reports a pass that carried nothing too, because what the lift waits on is
        // whether anything more is coming - and after a failed pass the answer is no.
        $this->executor->enterActivating($this->freeze(), 'accept-7', null);
        $this->announcer->noteSessionsDeferred(3);
        $this->notifier->frames = [];
        $this->executor->enterDeactivating();
        $this->executor->enterInactive();

        $this->announcer->noteSessionsCarriedOver(0, 3);

        $this->assertCount(1, $this->notifier->frames);
    }

    public function testTheHeldLiftGoesOutOnItsOwnWhenNoAnswerComes(): void
    {
        $this->executor->enterActivating($this->freeze(), 'accept-7', null);
        $this->announcer->noteSessionsDeferred(3);
        $this->notifier->frames = [];
        $this->executor->enterDeactivating();
        $this->executor->enterInactive();

        // A tick inside the wait changes nothing; one past it lifts anyway. A node held shut over
        // an answer that is not coming is worse than a browser that has to sign in again.
        $this->announcer->tick(time());
        $this->assertSame([], $this->notifier->frames);

        $this->announcer->tick(time() + self::PAST_THE_WAIT);
        $this->assertCount(1, $this->notifier->frames);
        $this->assertFalse($this->notifier->frames[0]->active);
    }

    public function testTheLiftIsAnnouncedOnlyOnce(): void
    {
        $this->executor->enterActivating($this->freeze(), 'accept-7', null);
        $this->announcer->noteSessionsDeferred(3);
        $this->notifier->frames = [];
        $this->executor->enterDeactivating();
        $this->executor->enterInactive();
        $this->announcer->noteSessionsCarriedOver(3, 0);

        // Both exits stay armed until one of them fires, and neither may fire twice: a second
        // "reload" would be a second reload.
        $this->announcer->tick(time() + self::PAST_THE_WAIT);
        $this->announcer->noteSessionsCarriedOver(3, 0);

        $this->assertCount(1, $this->notifier->frames);
    }

    public function testADebtNobodyAnsweredDoesNotFollowTheNodeIntoTheNextFreeze(): void
    {
        // Otherwise a restore whose library never reported would make every later lift on this
        // node pause and complain about logins nobody is waiting for.
        $this->executor->enterActivating($this->freeze(), 'accept-7', null);
        $this->announcer->noteSessionsDeferred(3);

        $this->freezeAndLift();

        $this->assertCount(1, $this->notifier->frames);
    }

    public function testAnAnswerNobodyWasWaitingForIsHarmless(): void
    {
        // The usual shape of a restore, in fact: the library comes back with the other agents when
        // the verification window opens, and the operator opens the node long afterwards.
        $this->executor->enterActivating($this->freeze(), 'accept-7', null);
        $this->announcer->noteSessionsDeferred(3);
        $this->announcer->noteSessionsCarriedOver(3, 0);
        $this->notifier->frames = [];

        $this->executor->enterDeactivating();
        $this->executor->enterInactive();

        $this->assertCount(1, $this->notifier->frames);
    }

    public function testTheDebtAndItsAnswerSurviveTheWorkerChannel(): void
    {
        $deferred = WorkerDTO::factoryWorkerDTO((string)json_encode(
            (new WorkerSessionCarryOverDeferredDTO(new SessionCarryOverDeferredSignalData(3)))->toArray(),
        ));
        $done = WorkerDTO::factoryWorkerDTO((string)json_encode(
            (new WorkerSessionCarryOverDoneDTO(new SessionCarryOverDoneSignalData(2, 1)))->toArray(),
        ));

        $this->assertInstanceOf(WorkerSessionCarryOverDeferredDTO::class, $deferred);
        $this->assertSame(3, $deferred->data->sessions);
        $this->assertInstanceOf(WorkerSessionCarryOverDoneDTO::class, $done);
        $this->assertSame(2, $done->data->carried);
        $this->assertSame(1, $done->data->dropped);
    }

    /**
     * Takes this node through a whole freeze that owes nothing, ending at the lift.
     */
    private function freezeAndLift(): void
    {
        $this->executor->enterActivating($this->freeze(), 'accept-7', null);
        $this->notifier->frames = [];
        $this->executor->enterDeactivating();
        $this->executor->enterInactive();
    }

    /**
     * @return ProtectedModeQuiesceData Freeze descriptor of a single-node restore
     */
    private function freeze(): ProtectedModeQuiesceData
    {
        return new ProtectedModeQuiesceData('restore', 'backup', 2, null);
    }
}

final class LiftWaitTestRtContext extends RtContext
{
    /**
     * Registers no project runtime state: the framework mount supplies the freeze row.
     */
    public function configure(): void
    {
    }
}

/**
 * Recording fake of the client-notifier port: keeps every frame this node announced.
 */
final class LiftWaitClientNotifier implements ProtectedModeClientNotifier
{
    /** @var list<ProtectedModeStateSignalData> Frames announced, in the order they went out */
    public array $frames = [];

    public function notifyProtectedModeState(
        ProtectedModeStateSignalData $state,
        ?string $excludeAcceptKey,
        ?string $excludeSessionTokenHash,
    ): void {
        $this->frames[] = $state;
    }

    public function notifyProtectedModeSessionState(
        ProtectedModeStateSignalData $state,
        string $sessionTokenHash,
    ): void {
        $this->frames[] = $state;
    }
}
