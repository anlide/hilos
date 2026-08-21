<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeProgressSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\DTO\ProtectedModeRefreezeSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeVerifySignalData;
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
 * request never re-enters so the stopped-agent roster is not re-rolled - though the initiator of a
 * freeze that already stands is told ready again rather than refused - only the recorded initiator
 * may release, and a project that mounts no runtime row never gets a ready.
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

    public function testRepeatedEnableBeforeTheFreezeSettlesIsDropped(): void
    {
        // The fake executor writes no row, so the freeze is still on its way in - and an entry
        // run twice re-rolls the stopped-agent roster the release resumes against.
        $this->mode->requestEnable($this->enableData());
        $this->executor->calls = [];

        $this->mode->requestEnable($this->enableData());

        $this->assertSame([], $this->executor->calls);
    }

    public function testTheInitiatorOfASettledFreezeIsToldReadyAgainInsteadOfRefused(): void
    {
        // What an operator does after closing the verification window: the node stays frozen on
        // active precisely so another restore can run, so the enable that restore raises has to be
        // answered rather than dropped as a duplicate. Nothing is re-entered - the node is already
        // quiesced, which is the whole of what a ready asserts.
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];

        $this->mode->requestEnable($this->enableData());

        $this->assertSame(['notifyInitiatorReady'], $this->executor->calls);
    }

    public function testEnableFromAnotherAgentUnderASettledFreezeIsDropped(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];

        $this->mode->requestEnable($this->enableData('chat', null));

        $this->assertSame([], $this->executor->calls);
    }

    public function testEnableInsideTheVerificationWindowIsDropped(): void
    {
        // Only a settled freeze answers ready: inside the window the agents are back up, so the
        // node is not quiesced and an operation that believed a ready would run over live clients.
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->enterVerifyingOnTheRuntimeRow();
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

    public function testTheInitiatorOpensTheVerificationWindow(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];

        $this->mode->requestVerify(new ProtectedModeVerifySignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertSame(['enterVerifying'], $this->executor->calls);
    }

    public function testVerifyFromAnotherAgentIsDropped(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];

        $this->mode->requestVerify(new ProtectedModeVerifySignalData('chat', null));

        $this->assertSame([], $this->executor->calls);
    }

    public function testVerifyFromTheWrongPhaseIsDropped(): void
    {
        // The row is left at activating, which is where a freeze sits before every node has
        // quiesced: there is no finished operation to verify yet.
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX, activate: false);
        $this->executor->calls = [];

        $this->mode->requestVerify(new ProtectedModeVerifySignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertSame([], $this->executor->calls);
    }

    public function testAPassIsRecordedOnlyInsideTheWindow(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $pass = new ProtectedModePassSignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX, 'hash-a');

        // Active, not verifying: nobody may be let in yet.
        $this->mode->requestPass($pass);
        $this->assertSame([], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);

        $this->enterVerifyingOnTheRuntimeRow();
        $this->withDaemonTruthSource(fn() => $this->mode->requestPass($pass));

        $this->assertSame(['hash-a'], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
    }

    public function testOnlyTheFirstPassIsAnnouncedToTheFrozenBrowsers(): void
    {
        // Zero-to-one is the only step that changes anything on a stub: it swaps the sentence
        // saying nothing has been minted for the code field. A second mint would broadcast to
        // every frozen browser to tell them what they are already showing.
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->enterVerifyingOnTheRuntimeRow();
        $this->executor->calls = [];

        $this->withDaemonTruthSource(function (): void {
            $this->mode->requestPass(new ProtectedModePassSignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX, 'hash-a'));
            $this->mode->requestPass(new ProtectedModePassSignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX, 'hash-b'));
        });

        $this->assertSame(['hash-a', 'hash-b'], Hilos::$rt?->hilosProtectedModeRuntime?->passHashes);
        $this->assertSame(['announcePassIssued'], $this->executor->calls);
    }

    public function testAPassRefusedOutsideTheWindowAnnouncesNothing(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];

        $this->mode->requestPass(new ProtectedModePassSignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX, 'hash-a'));

        $this->assertSame([], $this->executor->calls);
    }

    public function testTheInitiatorStampsTheProgressMarkOnTheRow(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];
        $this->assertNull(Hilos::$rt?->hilosProtectedModeRuntime?->progressAt);

        $before = time();
        $this->withDaemonTruthSource(fn() => $this->mode->requestProgress($this->progressData(
            self::INITIATOR_TYPE,
            self::INITIATOR_INDEX,
        )));

        $stamped = Hilos::$rt?->hilosProtectedModeRuntime?->progressAt;
        $this->assertNotNull($stamped);
        $this->assertGreaterThanOrEqual($before, $stamped);
        // The mark moves nothing: it is the one request that reports rather than asks.
        $this->assertSame([], $this->executor->calls);
    }

    public function testProgressFromAnotherAgentIsDropped(): void
    {
        // An agent that did not freeze the node could otherwise keep a hung operation looking
        // alive for as long as it liked, which is the one thing the mark exists to expose.
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);

        $this->withDaemonTruthSource(fn() => $this->mode->requestProgress($this->progressData('chat', null)));

        $this->assertNull(Hilos::$rt?->hilosProtectedModeRuntime?->progressAt);
    }

    public function testProgressUnderNoFreezeIsDroppedWithoutWriting(): void
    {
        // A restore marks its acceptance before the freeze exists and its outcome after the
        // freeze has lifted; both are honest reports with nowhere to land, and neither is an
        // error. Run without the truth source on purpose: reaching the row here would throw.
        $this->mode->requestProgress($this->progressData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertNull(Hilos::$rt?->hilosProtectedModeRuntime?->progressAt);
        $this->assertSame([], $this->executor->calls);
    }

    public function testOpeningTheVerificationWindowCountsAsProgress(): void
    {
        // Otherwise the window would be reported stuck the moment it opened, for the silence of
        // the operation that just ended. It gets a full threshold of its own instead.
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);

        $before = time();
        $this->enterVerifyingOnTheRuntimeRow();

        $stamped = Hilos::$rt?->hilosProtectedModeRuntime?->progressAt;
        $this->assertNotNull($stamped);
        $this->assertGreaterThanOrEqual($before, $stamped);
    }

    public function testTheInitiatorClosesTheWindowBackToAFullFreeze(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->enterVerifyingOnTheRuntimeRow();
        $this->executor->calls = [];

        $this->mode->requestRefreeze(new ProtectedModeRefreezeSignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

        $this->assertSame(['reenterActive'], $this->executor->calls);
    }

    public function testRefreezeOutsideTheWindowIsDropped(): void
    {
        $this->mode->requestEnable($this->enableData());
        $this->recordInitiatorOnTheRuntimeRow(self::INITIATOR_TYPE, self::INITIATOR_INDEX);
        $this->executor->calls = [];

        $this->mode->requestRefreeze(new ProtectedModeRefreezeSignalData(self::INITIATOR_TYPE, self::INITIATOR_INDEX));

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
        Hilos::$rt->mountFeatureRuntime([]);
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
     * @param bool $activate Whether to advance the row to active, as a completed entry does
     */
    private function recordInitiatorOnTheRuntimeRow(string $agentType, ?int $agentIndex, bool $activate = true): void
    {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            $this->fail('The protected mode runtime row is not mounted.');
        }

        $this->withDaemonTruthSource(function () use ($view, $agentType, $agentIndex, $activate): void {
            $view->actions->enterActivating(
                new ProtectedModeQuiesceData('restore', $agentType, $agentIndex, null),
                null,
            );
            if ($activate) {
                $view->actions->enterActive();
            }
        });
    }

    /**
     * Moves the row into the verification window, standing in for the real executor's write.
     */
    private function enterVerifyingOnTheRuntimeRow(): void
    {
        $view = Hilos::$rt?->hilosProtectedModeRuntime;
        if ($view === null) {
            $this->fail('The protected mode runtime row is not mounted.');
        }

        $this->withDaemonTruthSource(static fn() => $view->actions->enterVerifying());
    }

    /**
     * Runs a write with the daemon registered as the runtime truth source, and drops it after.
     *
     * The row refuses a write from anyone else, and the registration is process-wide, so it is
     * held for exactly the length of the write rather than for the length of the test.
     *
     * @param callable(): void $write Write to run as the truth source
     */
    private function withDaemonTruthSource(callable $write): void
    {
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
        try {
            $write();
        } finally {
            RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);
        }
    }

    /**
     * @param string $agentType Agent type asking to enter, the recorded initiator by default
     * @param ?int $agentIndex Agent index asking to enter
     * @return ProtectedModeEnableSignalData Enable request of the single-node initiator
     */
    private function enableData(
        string $agentType = self::INITIATOR_TYPE,
        ?int $agentIndex = self::INITIATOR_INDEX,
    ): ProtectedModeEnableSignalData {
        return new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorAgentType: $agentType,
            initiatorAgentIndex: $agentIndex,
            initiatorNodeId: null,
        );
    }

    /**
     * @param string $agentType Agent type reporting the progress
     * @param ?int $agentIndex Agent index reporting the progress
     * @return ProtectedModeProgressSignalData Progress mark of that agent
     */
    private function progressData(string $agentType, ?int $agentIndex): ProtectedModeProgressSignalData
    {
        return new ProtectedModeProgressSignalData(
            initiatorAgentType: $agentType,
            initiatorAgentIndex: $agentIndex,
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

    public function enterVerifying(): void
    {
        $this->calls[] = 'enterVerifying';
    }

    public function announcePassIssued(): void
    {
        $this->calls[] = 'announcePassIssued';
    }

    public function reenterActive(): void
    {
        $this->calls[] = 'reenterActive';
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
