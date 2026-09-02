<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\Exception\ProtectedModeFreezeUnreadableException;
use Hilos\ProtectedMode\ProtectedModeFreezeStore;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The freeze a node leaves on disk so a restart does not quietly reopen it (HIL-482).
 *
 * The row lives in runtime state, which is memory only, so everything here defends one sentence: a
 * daemon that goes down under a freeze must come back under it. That splits into the two halves the
 * cases below are grouped by - what the store writes and reads, and what the daemon executor leaves
 * behind as it moves the phase.
 *
 * The refusal cases all approach the same rule from different sides: a file that is THERE and cannot
 * be understood refuses the startup, while a file that is ABSENT is the ordinary node that was never
 * frozen. A file damaged by permissions rather than by content is not among them on purpose - the
 * suite runs as root in the test container, where a mode of 0000 still reads - so the truncated file
 * stands for that branch, and it is the one a crash mid-write actually produces.
 */
final class ProtectedModeFreezeStoreTest extends TestCase
{
    /** @var string Operation the freeze in these cases protects */
    private const string OPERATION = 'backup:restore';

    /** @var string Agent type recorded as the initiator */
    private const string INITIATOR_TYPE = 'hilos_backup';

    /** @var int Epoch second the freeze in these cases began at */
    private const int STARTED_AT = 1_700_000_000;

    /** Daemon log directory the store keeps its file beside */
    private string $logDirectory = '';

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logDirectory = (string)tempnam(sys_get_temp_dir(), 'hilos-freeze-store');
        unlink($this->logDirectory);
        mkdir($this->logDirectory);

        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->logDirectory . '/daemon.log');
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

    public function testTheFreezeIsReadBackTheWayItWasLeft(): void
    {
        new ProtectedModeFreezeStore()->save($this->row(StateProtectedModeRuntime::PHASE_ACTIVE));

        $restored = new ProtectedModeFreezeStore()->load();

        $this->assertNotNull($restored);
        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, $restored->phase);
        $this->assertSame(self::OPERATION, $restored->operation);
        $this->assertSame(self::INITIATOR_TYPE, $restored->initiatorAgentType);
        $this->assertSame(2, $restored->initiatorAgentIndex);
        $this->assertSame('node-a', $restored->initiatorNodeId);
        $this->assertSame(self::STARTED_AT, $restored->startedAt);
        $this->assertSame(self::STARTED_AT + 1, $restored->activatedAt);
    }

    public function testANodeThatWasNeverFrozenFindsNoFreeze(): void
    {
        // The ordinary startup, and the one case that must not be read as trouble: no file at all.
        $this->assertNull(new ProtectedModeFreezeStore()->load());
    }

    public function testALiftedFreezeLeavesNothingOnDisk(): void
    {
        $store = new ProtectedModeFreezeStore();
        $store->save($this->row(StateProtectedModeRuntime::PHASE_ACTIVE));

        $store->forget();

        $this->assertFileDoesNotExist($this->freezeFile());
        $this->assertNull($store->load());
    }

    public function testForgettingAFreezeThatWasNeverWrittenIsNotAFailure(): void
    {
        // The lift runs on every node, including the ones that never got as far as writing a file.
        new ProtectedModeFreezeStore()->forget();

        $this->assertFileDoesNotExist($this->freezeFile());
    }

    public function testTheWriteLeavesNoTempFileBesideIt(): void
    {
        // The file is published rather than streamed into place, and the temp it is published from
        // must not survive: a leftover beside the freeze is one more thing an operator has to judge.
        new ProtectedModeFreezeStore()->save($this->row(StateProtectedModeRuntime::PHASE_ACTIVE));

        $this->assertSame(
            [$this->freezeFile()],
            array_values(array_filter(
                (array)glob($this->logDirectory . '/*'),
                static fn(string $path): bool => !str_ends_with($path, '.log'),
            )),
        );
    }

    public function testATruncatedFileRefusesTheStartup(): void
    {
        // What a crash during the write leaves, and the reason the write is published at all. It is
        // not "no freeze": the file is there because this node was frozen when it went down.
        file_put_contents($this->freezeFile(), '');

        $this->expectException(ProtectedModeFreezeUnreadableException::class);
        new ProtectedModeFreezeStore()->load();
    }

    public function testAFileThatIsNotJsonRefusesTheStartup(): void
    {
        file_put_contents($this->freezeFile(), 'frozen, honestly');

        $this->expectException(ProtectedModeFreezeUnreadableException::class);
        new ProtectedModeFreezeStore()->load();
    }

    public function testAFileFromAFormatThisBuildDoesNotKnowRefusesTheStartup(): void
    {
        // A build that cannot tell what the fields mean must say so rather than restore a freeze by
        // guessing at them - the version key exists for exactly this moment.
        file_put_contents($this->freezeFile(), (string)json_encode(['version' => 2, 'row' => []]));

        $this->expectException(ProtectedModeFreezeUnreadableException::class);
        new ProtectedModeFreezeStore()->load();
    }

    public function testAFileCarryingNoRowRefusesTheStartup(): void
    {
        file_put_contents($this->freezeFile(), (string)json_encode(['version' => 1]));

        $this->expectException(ProtectedModeFreezeUnreadableException::class);
        new ProtectedModeFreezeStore()->load();
    }

    public function testTheExecutorLeavesEveryPhaseItWritesOnDisk(): void
    {
        $executor = $this->executorOnAMountedNode();

        $executor->enterActivating($this->freeze(), 'accept-7', null);
        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVATING, $this->persistedPhase());

        $executor->enterActive();
        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, $this->persistedPhase());

        $executor->enterVerifying();
        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, $this->persistedPhase());

        $executor->reenterActive();
        $this->assertSame(StateProtectedModeRuntime::PHASE_ACTIVE, $this->persistedPhase());

        $executor->enterDeactivating();
        $this->assertSame(StateProtectedModeRuntime::PHASE_DEACTIVATING, $this->persistedPhase());
    }

    public function testTheLiftTakesTheFreezeOffDisk(): void
    {
        $executor = $this->executorOnAMountedNode();
        $executor->enterActivating($this->freeze(), 'accept-7', null);

        $executor->enterInactive();

        $this->assertFileDoesNotExist($this->freezeFile());
    }

    public function testARestoredFreezeKeepsNoKeyOfTheConnectionsThatDied(): void
    {
        // Every accept key on the row was minted on a 101 that died with the daemon - the passes,
        // the browsers they admitted, and the initiator's own. What comes back is a freeze that
        // locks out everybody, which is the honest state of a node whose operation is gone.
        $this->mountNode();
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        $this->assertNotNull($view);

        $view->actions->restoreFromDisk(StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_VERIFYING,
            StateProtectedModeRuntime::operation => self::OPERATION,
            StateProtectedModeRuntime::initiatorAcceptKey => 'accept-7',
            StateProtectedModeRuntime::initiatorAgentType => self::INITIATOR_TYPE,
            StateProtectedModeRuntime::startedAt => self::STARTED_AT,
            StateProtectedModeRuntime::passHashes => ['hash-of-a-pass'],
            StateProtectedModeRuntime::admittedSessionTokenHashes => ['session-hash-9'],
        ]));

        $this->assertSame(StateProtectedModeRuntime::PHASE_VERIFYING, $view->phase);
        $this->assertSame(self::OPERATION, $view->operation);
        $this->assertSame(self::STARTED_AT, $view->startedAt);
        $this->assertNull($view->initiatorAcceptKey);
        $this->assertSame([], $view->passHashes);
        $this->assertSame([], $view->admittedSessionTokenHashes);
        $this->assertTrue($view->locksOut('accept-7', null));
        $this->assertTrue($view->locksOut('accept-9', 'session-hash-9'));
    }

    /**
     * A freeze row as a node under one carries it.
     *
     * @param string $phase Phase the freeze stands on
     * @return array<string, mixed> Row in the shape the store writes
     */
    private function row(string $phase): array
    {
        return StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => $phase,
            StateProtectedModeRuntime::operation => self::OPERATION,
            StateProtectedModeRuntime::initiatorAcceptKey => 'accept-7',
            StateProtectedModeRuntime::initiatorAgentType => self::INITIATOR_TYPE,
            StateProtectedModeRuntime::initiatorAgentIndex => 2,
            StateProtectedModeRuntime::initiatorNodeId => 'node-a',
            StateProtectedModeRuntime::startedAt => self::STARTED_AT,
            StateProtectedModeRuntime::activatedAt => self::STARTED_AT + 1,
            StateProtectedModeRuntime::passHashes => [],
            StateProtectedModeRuntime::admittedSessionTokenHashes => [],
        ])->toArray();
    }

    /**
     * @return ProtectedModeQuiesceData Freeze descriptor the executor is driven with
     */
    private function freeze(): ProtectedModeQuiesceData
    {
        return new ProtectedModeQuiesceData(
            operation: self::OPERATION,
            initiatorAgentType: self::INITIATOR_TYPE,
            initiatorAgentIndex: 2,
            initiatorNodeId: 'node-a',
        );
    }

    /**
     * Mounts the freeze row and makes this process its writer, as a node's master is.
     */
    private function mountNode(): void
    {
        Hilos::$rt = new FreezeStoreTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::create());
        Hilos::$cluster = new ClusterContext();
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
    }

    /**
     * @return DaemonProtectedModeExecutor Executor writing the row of a mounted node
     */
    private function executorOnAMountedNode(): DaemonProtectedModeExecutor
    {
        $this->mountNode();

        return new DaemonProtectedModeExecutor();
    }

    /**
     * @return ?string Phase the file on disk carries, or null when there is no file
     */
    private function persistedPhase(): ?string
    {
        return new ProtectedModeFreezeStore()->load()?->phase;
    }

    /**
     * @return string Absolute path of the freeze file these cases read and write
     */
    private function freezeFile(): string
    {
        return $this->logDirectory . '/' . ProtectedModeFreezeStore::FILE_NAME;
    }
}

/**
 * Project context that registers no runtime state of its own: the framework mount supplies the
 * freeze row.
 *
 * Named apart from its neighbours because the protected-mode test files share one namespace, so one
 * context name could not carry two classes and the suite would not load.
 */
final class FreezeStoreTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}
