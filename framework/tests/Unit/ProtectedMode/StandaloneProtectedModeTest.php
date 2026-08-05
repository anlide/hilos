<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\ProtectedMode\ProtectedModeExecutor;
use Hilos\ProtectedMode\StandaloneProtectedMode;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the single-node freeze.
 *
 * The machine is driven through the request seam an initiator's daemon calls and observed through
 * a recording fake of the local-node port, so entry, refusal and release are pinned without a
 * daemon: entering runs the whole freeze in one tick and tells the initiator to go, a repeat
 * request is refused so the stopped-agent roster is not re-rolled, only the recorded initiator may
 * release, and a project that mounts no runtime row never gets a ready.
 */
final class StandaloneProtectedModeTest extends TestCase
{
    private const string INITIATOR_TYPE = 'backup';

    private const int INITIATOR_INDEX = 2;

    private FakeStandaloneExecutor $executor;

    private StandaloneProtectedMode $mode;

    protected function setUp(): void
    {
        $this->executor = new FakeStandaloneExecutor();
        $this->mode = new StandaloneProtectedMode($this->executor);
        $this->mount();
    }

    protected function tearDown(): void
    {
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testEnteringFreezesTheNodeAndSignalsTheInitiatorInOneTick(): void
    {
        $this->mode->requestEnable($this->enableData());

        $this->assertSame(['enterActivating', 'enterActive', 'notifyInitiatorReady'], $this->executor->calls);
        $this->assertSame('accept-9', $this->executor->activatingAcceptKey);
        $this->assertSame(self::INITIATOR_TYPE, $this->executor->freeze?->initiatorAgentType);
        $this->assertSame(self::INITIATOR_INDEX, $this->executor->freeze?->initiatorAgentIndex);
        // Nothing to name: the freeze never leaves this node, so it carries no node id.
        $this->assertNull($this->executor->freeze?->initiatorNodeId);
    }

    public function testRepeatedEnableIsDropped(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->executor->calls = [];

        $this->mode->requestEnable($this->enableData());

        $this->assertSame([], $this->executor->calls);
    }

    public function testInitiatorReleasesTheFreeze(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];

        $this->mode->requestDisable($this->disableData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertSame(['enterDeactivating', 'enterInactive'], $this->executor->calls);
    }

    public function testReleaseFromAnotherAgentIsDropped(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];

        $this->mode->requestDisable($this->disableData('chat', null));

        $this->assertSame([], $this->executor->calls);
    }

    public function testReleaseWithoutAnActiveFreezeIsDropped(): void
    {
        $this->mode->requestDisable($this->disableData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertSame([], $this->executor->calls);
    }

    public function testWithoutAMountedRuntimeRowTheModeNeitherEntersNorReportsReady(): void
    {
        // Fail-closed: the initiator waits for ready before it destroys anything, so refusing to
        // enter keeps it waiting instead of letting it run over a live system.
        Hilos::$rt = null;

        $this->mode->requestEnable($this->enableData());

        $this->assertSame([], $this->executor->calls);
    }

    /**
     * Mounts the framework-owned protected mode runtime row, as a real project boot does.
     */
    private function mount(): void
    {
        Hilos::$rt = new StandaloneProtectedModeTestRtContext();
        Hilos::$rt->mountFrameworkState();
    }

    /**
     * Records the initiator identity on the runtime row, standing in for the real executor's write.
     *
     * The release is authorized against the row rather than against the machine's own memory, so
     * with a fake executor the test has to put there what {@see DaemonProtectedModeExecutor::enterActivating()}
     * would have written. It writes through the same item actions the real executor uses, which is
     * why the daemon truth source is registered for the length of the write and dropped after: a
     * test that wrote the row any other way would stop proving the release path reads what the
     * executor actually leaves behind.
     *
     * @param string $agentType Initiator agent type to record
     * @param ?int $agentIndex Initiator agent index to record
     */
    private function recordInitiatorOnTheRuntimeRow(string $agentType, ?int $agentIndex): void
    {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            $this->fail('The protected mode runtime row is not mounted.');
        }

        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        try {
            $view->actions->enterActivating(
                new ProtectedModeQuiesceData('restore', $agentType, $agentIndex, null),
                null,
            );
            $view->actions->enterActive();
        } finally {
            RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);
        }
    }

    /**
     * @return ProtectedModeEnableSignalData Enable request of the single-node initiator
     */
    private function enableData(): ProtectedModeEnableSignalData
    {
        return new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorAgentType: self::INITIATOR_TYPE,
            initiatorAgentIndex: self::INITIATOR_INDEX,
            initiatorNodeId: null,
        );
    }

    /**
     * @param string $agentType Agent type asking for the release
     * @param ?int $agentIndex Agent index asking for the release
     * @return ProtectedModeDisableSignalData Release request of that agent
     */
    private function disableData(string $agentType, ?int $agentIndex): ProtectedModeDisableSignalData
    {
        return new ProtectedModeDisableSignalData(
            initiatorAgentType: $agentType,
            initiatorAgentIndex: $agentIndex,
        );
    }
}

final class StandaloneProtectedModeTestRtContext extends RtContext
{
    /**
     * Registers no project runtime state: the framework mount supplies the freeze row.
     */
    public function configure(): void
    {
    }
}

/**
 * Recording fake of the local-node port: captures the transitions and the freeze descriptor.
 */
final class FakeStandaloneExecutor implements ProtectedModeExecutor
{
    /** @var array<string> Ordered method names invoked */
    public array $calls = [];

    /** @var ?string Accept key passed to the most recent enterActivating call */
    public ?string $activatingAcceptKey = null;

    /** @var ?ProtectedModeQuiesceData Freeze descriptor passed to the most recent enterActivating call */
    public ?ProtectedModeQuiesceData $freeze = null;

    public function enterActivating(ProtectedModeQuiesceData $freeze, ?string $initiatorAcceptKey): void
    {
        $this->calls[] = 'enterActivating';
        $this->activatingAcceptKey = $initiatorAcceptKey;
        $this->freeze = $freeze;
    }

    public function enterActive(): void
    {
        $this->calls[] = 'enterActive';
    }

    public function enterDeactivating(): void
    {
        $this->calls[] = 'enterDeactivating';
    }

    public function enterInactive(): void
    {
        $this->calls[] = 'enterInactive';
    }

    public function notifyInitiatorReady(): void
    {
        $this->calls[] = 'notifyInitiatorReady';
    }
}
