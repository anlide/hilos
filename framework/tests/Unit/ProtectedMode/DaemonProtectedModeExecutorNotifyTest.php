<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\DTO\ProtectedModeStateSignalData;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\ProtectedMode\ProtectedModeClientNotifier;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for what the daemon executor tells this node's browser connections.
 *
 * A page loaded before the freeze landed hears about it exactly twice - entering and lifting -
 * and both of those frames leave nobody out: while the node is frozen there is no application for
 * the initiator to be kept in either, and after a restore its data is as stale as everyone else's.
 * The two phases in between say nothing, because the surface is already up and must stay up. The
 * verification window is where the exclusions live, and it is the one phase that speaks twice: the
 * broadcast keeps the stub up for everyone still outside, and a session frame carries the operator
 * back into the application without an F5. Beside it stands one announcement that moves no phase at
 * all - the first minted pass, which turns the waiting sentence on the stub into the code field
 * without the verifier touching anything. With no notifier registered the executor is inert, the
 * same way it already is with no runtime row mounted.
 */
final class DaemonProtectedModeExecutorNotifyTest extends TestCase
{
    private DaemonProtectedModeExecutor $executor;

    private RecordingClientNotifier $notifier;

    /** Daemon log directory the executor leaves the freeze file in (HIL-482) */
    private string $logDirectory = '';

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        // Every phase this executor writes is also left on disk, so these cases need a directory
        // for it: without one each transition would log a failure of the freeze store, which has
        // nothing to do with the frames asserted here.
        $this->logDirectory = (string)tempnam(sys_get_temp_dir(), 'hilos-executor-notify');
        unlink($this->logDirectory);
        mkdir($this->logDirectory);
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->logDirectory . '/daemon.log');

        $this->executor = new DaemonProtectedModeExecutor();
        $this->notifier = new RecordingClientNotifier();

        Hilos::$rt = new ExecutorNotifyTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerProtectedModeClientNotifier($this->notifier);
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

    public function testEnteringAnnouncesTheModeWithCopyAndExcludesNobody(): void
    {
        // Not even the browser that asked: the agents behind every page have just been stopped, so
        // the operator would be held in an application that answers nothing. Every tab they have,
        // the one they pressed Restore in included, goes to the stub - where the restore panel is
        // the thing that keeps being fed (HIL-718).
        $this->executor->enterActivating($this->freeze(), 'accept-7', 'session-hash-7');

        $this->assertCount(1, $this->notifier->frames);
        [$state, $excludedKey, $excludedSession] = $this->notifier->frames[0];
        $this->assertTrue($state->active);
        $this->assertSame('restore', $state->operation);
        $this->assertNotNull($state->title);
        $this->assertNotNull($state->message);
        $this->assertNull($excludedKey);
        $this->assertNull($excludedSession);
        // And nothing is said to the initiator's session on its own either: the entry has one
        // verdict and it is the same for everybody.
        $this->assertSame([], $this->notifier->sessionFrames);
    }

    public function testOnAFollowerNobodyIsExcludedEither(): void
    {
        // The initiator's connection lives on the initiator's node, so a follower has no accept
        // key to spare and no session hash to name. It arrives at the same broadcast the leader
        // makes, which is what the two nodes are supposed to agree on.
        $this->executor->enterActivating($this->freeze(), null, null);

        $this->assertNull($this->notifier->frames[0][1]);
        $this->assertNull($this->notifier->frames[0][2]);
    }

    public function testTheMiddlePhasesSayNothing(): void
    {
        $this->executor->enterActivating($this->freeze(), 'accept-7', null);
        $this->notifier->frames = [];

        $this->executor->enterActive();
        $this->executor->enterDeactivating();

        $this->assertSame([], $this->notifier->frames);
    }

    public function testLiftingAnnouncesTheModeIsOffToEveryone(): void
    {
        $this->executor->enterActivating($this->freeze(), 'accept-7', null);
        $this->notifier->frames = [];

        $this->executor->enterDeactivating();
        $this->executor->enterInactive();

        $this->assertCount(1, $this->notifier->frames);
        [$state, $excludedKey, $excludedSession] = $this->notifier->frames[0];
        $this->assertFalse($state->active);
        $this->assertNull($state->operation);
        $this->assertNull($state->title);
        $this->assertNull($state->message);
        // Neither half is spared on the way out: after a restore the initiator's data is as stale
        // as anybody else's, and this frame means reload.
        $this->assertNull($excludedKey);
        $this->assertNull($excludedSession);
    }

    public function testTheVerificationWindowKeepsTheStubUpAndOffersACodeField(): void
    {
        $this->executor->enterActivating($this->freeze(), 'accept-7', 'session-hash-7');
        $this->executor->enterActive();
        $this->notifier->frames = [];

        $this->executor->enterVerifying();

        $this->assertSame(
            StateProtectedModeRuntime::PHASE_VERIFYING,
            Hilos::$rt?->hilosProtectedModeRuntime?->phase,
        );
        $this->assertCount(1, $this->notifier->frames);
        [$state, $excludedKey, $excludedSession] = $this->notifier->frames[0];
        // Still active: everyone without a pass has to keep seeing the stub. What changed is
        // only that the stub may now ask for a code.
        $this->assertTrue($state->active);
        $this->assertTrue($state->acceptsPass);
        // Open, and empty: nothing has been minted yet, so the surface says to wait instead of
        // offering a field that could take nothing.
        $this->assertFalse($state->passIssued);
        $this->assertSame('restore', $state->operation);
        $this->assertNotNull($state->title);
        // The operator is left out of this one because the next frame says the opposite to them.
        $this->assertSame('accept-7', $excludedKey);
        $this->assertSame('session-hash-7', $excludedSession);
    }

    public function testTheWindowCarriesEveryTabOfTheOperatorBackInWithoutAReload(): void
    {
        // Nothing tore those connections down on the way into the freeze, so every tab of the
        // operator is standing on the stub and would stand there for the whole window waiting for
        // an F5 nobody told them to press. This frame is what takes them all off it at once.
        $this->executor->enterActivating($this->freeze(), 'accept-7', 'session-hash-7');
        $this->executor->enterActive();
        $this->notifier->sessionFrames = [];

        $this->executor->enterVerifying();

        $this->assertCount(1, $this->notifier->sessionFrames);
        [$state, $sessionTokenHash] = $this->notifier->sessionFrames[0];
        $this->assertSame('session-hash-7', $sessionTokenHash);
        $this->assertFalse($state->active);
        // The row's own bit, not this session's: a client reading active: false without it takes
        // the frame for a lift and reloads itself straight back out of the window.
        $this->assertTrue($state->acceptsPass);
        // The window opens before anything is minted, and this session is not the one that mints.
        $this->assertFalse($state->passIssued);
        // No copy: a browser being let into the application renders no stub to put words on.
        $this->assertNull($state->title);
        $this->assertNull($state->message);
    }

    public function testAnInitiatorWithNoBrowserBehindItIsAddressedByNeitherFrame(): void
    {
        // The freeze recognized one socket and no browser behind it - a CLI restore, or a browser
        // whose session the agent could not read, which it warns about. The broadcast goes on
        // sparing only what it was actually told about, and the session frame is not sent at all:
        // there is no session to address it to, and inventing one would be addressing a stranger.
        $this->executor->enterActivating($this->freeze(), 'accept-7', null);
        $this->executor->enterActive();
        $this->notifier->frames = [];

        $this->executor->enterVerifying();

        $this->assertCount(1, $this->notifier->frames);
        $this->assertSame('accept-7', $this->notifier->frames[0][1]);
        $this->assertNull($this->notifier->frames[0][2]);
        $this->assertSame([], $this->notifier->sessionFrames);
    }

    public function testTheFirstMintTurnsTheSentenceIntoTheField(): void
    {
        $this->executor->enterActivating($this->freeze(), 'accept-7', 'session-hash-7');
        $this->executor->enterActive();
        $this->executor->enterVerifying();
        Hilos::$rt?->hilosProtectedModeRuntime?->actions->issuePass('hash-a');
        $this->notifier->frames = [];

        $this->executor->announcePassIssued();

        $this->assertCount(1, $this->notifier->frames);
        [$state, $excludedKey, $excludedSession] = $this->notifier->frames[0];
        $this->assertTrue($state->active);
        $this->assertTrue($state->acceptsPass);
        $this->assertTrue($state->passIssued);
        $this->assertSame('restore', $state->operation);
        $this->assertNotNull($state->title);
        // The same exclusion the window push makes, and here it still earns its keep: this frame
        // says active, and reaching the operator with it would put them back on the stub in the
        // one phase they are inside the application.
        $this->assertSame('accept-7', $excludedKey);
        $this->assertSame('session-hash-7', $excludedSession);
    }

    public function testTheMintAnnouncementMovesNoPhaseAndWritesNoPass(): void
    {
        $this->executor->enterActivating($this->freeze(), 'accept-7', null);
        $this->executor->enterActive();
        $this->executor->enterVerifying();
        Hilos::$rt?->hilosProtectedModeRuntime?->actions->issuePass('hash-a');

        $this->executor->announcePassIssued();

        $this->assertSame(
            StateProtectedModeRuntime::PHASE_VERIFYING,
            Hilos::$rt?->hilosProtectedModeRuntime?->phase,
        );
        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
    }

    public function testClosingBackFromTheWindowTakesTheCodeFieldAway(): void
    {
        $this->executor->enterActivating($this->freeze(), 'accept-7', null);
        $this->executor->enterActive();
        $this->executor->enterVerifying();
        Hilos::$rt?->hilosProtectedModeRuntime?->actions->issuePass('hash-a');
        Hilos::$rt?->hilosProtectedModeRuntime?->actions->admitSession('session-hash-1');
        $this->notifier->frames = [];

        $this->executor->reenterActive();

        $this->assertSame(
            StateProtectedModeRuntime::PHASE_ACTIVE,
            Hilos::$rt?->hilosProtectedModeRuntime?->phase,
        );
        // Every pass is void, so the verifier that was inside is back on the stub with everyone.
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->admittedSessionTokenHashes);
        $this->assertTrue(Hilos::$rt?->hilosProtectedModeRuntime?->locksOut('accept-1', 'session-hash-1'));

        $this->assertCount(1, $this->notifier->frames);
        [$state, $excludedKey, $excludedSession] = $this->notifier->frames[0];
        $this->assertTrue($state->active);
        $this->assertFalse($state->acceptsPass);
        // Both bits fall together on the way out: the field is gone because the window is, not
        // because it ran out of codes.
        $this->assertFalse($state->passIssued);
        // Nobody is left out, the mirror of the entry: the node is shut again and the operator
        // goes back behind the stub with everyone else.
        $this->assertNull($excludedKey);
        $this->assertNull($excludedSession);
    }

    public function testClosingBackIsRefusedWhenTheRowNamesNoInitiator(): void
    {
        // A row with no initiator would stop every agent including the one that could lift the
        // mode again, so the node says so and stays where it is.
        Hilos::$rt?->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_VERIFYING,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
        ]));

        $this->executor->reenterActive();

        $this->assertSame(
            StateProtectedModeRuntime::PHASE_VERIFYING,
            Hilos::$rt?->hilosProtectedModeRuntime?->phase,
        );
        $this->assertSame([], $this->notifier->frames);
    }

    public function testWithoutARegisteredNotifierTheExecutorStillFreezes(): void
    {
        Hilos::$cluster = new ClusterContext();

        $this->executor->enterActivating($this->freeze(), 'accept-7', null);

        $this->assertSame([], $this->notifier->frames);
        $this->assertSame(
            StateProtectedModeRuntime::PHASE_ACTIVATING,
            Hilos::$rt?->hilosProtectedModeRuntime?->phase,
        );
    }

    /**
     * @return ProtectedModeQuiesceData Freeze descriptor of a single-node restore
     */
    private function freeze(): ProtectedModeQuiesceData
    {
        return new ProtectedModeQuiesceData('restore', 'backup', 2, null);
    }
}

final class ExecutorNotifyTestRtContext extends RtContext
{
    /**
     * Registers no project runtime state: the framework mount supplies the freeze row.
     */
    public function configure(): void
    {
    }
}

/**
 * Recording fake of the client-notifier port: captures each frame and whom it left out.
 */
final class RecordingClientNotifier implements ProtectedModeClientNotifier
{
    /**
     * @var list<array{0: ProtectedModeStateSignalData, 1: ?string, 2: ?string}> Frames announced, each
     *      with the accept key and the session hash it left out
     */
    public array $frames = [];

    /** @var list<array{0: ProtectedModeStateSignalData, 1: string}> Session frames, with the session addressed */
    public array $sessionFrames = [];

    public function notifyProtectedModeState(
        ProtectedModeStateSignalData $state,
        ?string $excludeAcceptKey,
        ?string $excludeSessionTokenHash,
    ): void {
        $this->frames[] = [$state, $excludeAcceptKey, $excludeSessionTokenHash];
    }

    public function notifyProtectedModeSessionState(
        ProtectedModeStateSignalData $state,
        string $sessionTokenHash,
    ): void {
        $this->sessionFrames[] = [$state, $sessionTokenHash];
    }
}
