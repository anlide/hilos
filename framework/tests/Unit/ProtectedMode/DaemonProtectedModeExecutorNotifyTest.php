<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Cluster\ClusterContext;
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
 * and each of the two frames is shaped by a rule that is easy to get backwards: the entry frame
 * leaves the initiator out (it drives the operation and must keep seeing the real app), while the
 * lift frame leaves nobody out (after a restore the initiator's data is as stale as everyone
 * else's). The two phases in between say nothing, because the surface is already up and must stay
 * up. With no notifier registered the executor is inert, the same way it already is with no
 * runtime row mounted.
 */
final class DaemonProtectedModeExecutorNotifyTest extends TestCase
{
    private DaemonProtectedModeExecutor $executor;

    private RecordingClientNotifier $notifier;

    protected function setUp(): void
    {
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

        parent::tearDown();
    }

    public function testEnteringAnnouncesTheModeWithCopyAndExcludesTheInitiator(): void
    {
        $this->executor->enterActivating($this->freeze(), 'accept-7');

        $this->assertCount(1, $this->notifier->frames);
        [$state, $excluded] = $this->notifier->frames[0];
        $this->assertTrue($state->active);
        $this->assertSame('restore', $state->operation);
        $this->assertNotNull($state->title);
        $this->assertNotNull($state->message);
        $this->assertSame('accept-7', $excluded);
    }

    public function testOnAFollowerNobodyIsExcluded(): void
    {
        // The initiator's connection lives on the initiator's node, so a follower has no accept
        // key to spare - and must not invent one, or it would quietly leave a browser unfrozen.
        $this->executor->enterActivating($this->freeze(), null);

        $this->assertNull($this->notifier->frames[0][1]);
    }

    public function testTheMiddlePhasesSayNothing(): void
    {
        $this->executor->enterActivating($this->freeze(), 'accept-7');
        $this->notifier->frames = [];

        $this->executor->enterActive();
        $this->executor->enterDeactivating();

        $this->assertSame([], $this->notifier->frames);
    }

    public function testLiftingAnnouncesTheModeIsOffToEveryone(): void
    {
        $this->executor->enterActivating($this->freeze(), 'accept-7');
        $this->notifier->frames = [];

        $this->executor->enterDeactivating();
        $this->executor->enterInactive();

        $this->assertCount(1, $this->notifier->frames);
        [$state, $excluded] = $this->notifier->frames[0];
        $this->assertFalse($state->active);
        $this->assertNull($state->operation);
        $this->assertNull($state->title);
        $this->assertNull($state->message);
        $this->assertNull($excluded);
    }

    public function testWithoutARegisteredNotifierTheExecutorStillFreezes(): void
    {
        Hilos::$cluster = new ClusterContext();

        $this->executor->enterActivating($this->freeze(), 'accept-7');

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
    /** @var list<array{0: ProtectedModeStateSignalData, 1: ?string}> Frames announced, with the excluded accept key */
    public array $frames = [];

    public function notifyProtectedModeState(ProtectedModeStateSignalData $state, ?string $excludeAcceptKey): void
    {
        $this->frames[] = [$state, $excludeAcceptKey];
    }
}
