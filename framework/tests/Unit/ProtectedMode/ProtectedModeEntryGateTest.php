<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\ProtectedMode;

use Hilos\Cluster\ClusterContext;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeCircleSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModePassSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeProgressSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeRefreezeSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeVerifySignalData;
use Hilos\ProtectedMode\ProtectedModeAgentFreezer;
use Hilos\ProtectedMode\ProtectedModeEntryGate;
use Hilos\ProtectedMode\ProtectedModeSwitch;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the hold a freeze request waits out while the lift before it is still landing.
 *
 * What is pinned here is the order of two facts, because that order is the whole defect: a freeze
 * entered while a start this node asked for has not been reported takes the roster down mid-start,
 * and the agent whose report arrives next is killed as an orphan and never asked for again. So the
 * gate lets a request through on a settled roster, holds it on an unsettled one, releases it the
 * tick the roster settles, and - because a start that never lands must not make the node
 * unfreezable - goes through anyway once the hold has waited long enough.
 */
final class ProtectedModeEntryGateTest extends TestCase
{
    private FakeFreezeSwitch $switch;

    private FakeRosterFreezer $freezer;

    private ProtectedModeEntryGate $gate;

    protected function setUp(): void
    {
        $this->switch = new FakeFreezeSwitch();
        $this->freezer = new FakeRosterFreezer();
        $this->gate = new ProtectedModeEntryGate();

        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerProtectedMode($this->switch);
        Hilos::$cluster->registerProtectedModeAgentFreezer($this->freezer);
        Hilos::$cluster->registerProtectedModeEntryGate($this->gate);
    }

    protected function tearDown(): void
    {
        Hilos::$cluster = null;

        parent::tearDown();
    }

    public function testASettledRosterIsFrozenAtOnce(): void
    {
        $this->gate->requestEnter($this->enableData('restore'));

        $this->assertSame(['restore'], $this->switch->entered);
    }

    public function testAFreezeAskedForMidStartIsHeld(): void
    {
        $this->freezer->stillStarting = ['chat'];

        $this->gate->requestEnter($this->enableData('restore'));

        $this->assertSame([], $this->switch->entered);
    }

    public function testTheHeldFreezeEntersTheTickTheRosterSettles(): void
    {
        $this->freezer->stillStarting = ['chat'];
        $this->gate->requestEnter($this->enableData('restore'));

        $this->gate->tick();
        $this->assertSame([], $this->switch->entered);

        $this->freezer->stillStarting = [];
        $this->gate->tick();

        $this->assertSame(['restore'], $this->switch->entered);
        // And once, not once per tick from then on.
        $this->gate->tick();
        $this->assertSame(['restore'], $this->switch->entered);
    }

    public function testASecondRequestLetsTheHeldOneInFirst(): void
    {
        $this->freezer->stillStarting = ['chat'];
        $this->gate->requestEnter($this->enableData('restore'));

        $this->gate->requestEnter($this->enableData('import'));

        $this->assertSame(['restore', 'import'], $this->switch->entered);
    }

    public function testANodeWithNoFreezerFreezesAtOnce(): void
    {
        // Nothing to wait for: a process that registered no roster seam has no starts of its own
        // in flight, and holding a restore over a question nobody can answer would never end.
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerProtectedMode($this->switch);

        $this->gate->requestEnter($this->enableData('restore'));

        $this->assertSame(['restore'], $this->switch->entered);
    }

    /**
     * @param string $operation Operation name the freeze protects
     * @return ProtectedModeEnableSignalData Enable request as an initiator agent raises it
     */
    private function enableData(string $operation): ProtectedModeEnableSignalData
    {
        return new ProtectedModeEnableSignalData(
            operation: $operation,
            initiatorAcceptKey: null,
            initiatorSessionTokenHash: null,
            initiatorAgentType: 'backup',
            initiatorAgentIndex: null,
            initiatorNodeId: null,
        );
    }
}

/**
 * Recording fake of the freeze switch: names the operations that reached it, in order.
 */
final class FakeFreezeSwitch implements ProtectedModeSwitch
{
    /** @var list<string> Operations of the enable requests that reached the switch, in order */
    public array $entered = [];

    public function requestEnable(ProtectedModeEnableSignalData $data): void
    {
        $this->entered[] = $data->operation;
    }

    public function requestDisable(ProtectedModeDisableSignalData $data): void
    {
    }

    public function requestVerify(ProtectedModeVerifySignalData $data): void
    {
    }

    public function requestProgress(ProtectedModeProgressSignalData $data): void
    {
    }

    public function requestPass(ProtectedModePassSignalData $data): void
    {
    }

    public function requestCircle(ProtectedModeCircleSignalData $data): void
    {
    }

    public function requestRefreeze(ProtectedModeRefreezeSignalData $data): void
    {
    }

    public function onRosterStopped(): void
    {
    }

    public function onRosterResumed(): void
    {
    }
}

/**
 * Fake of the roster seam: answers whatever the test says is still starting.
 */
final class FakeRosterFreezer implements ProtectedModeAgentFreezer
{
    /** @var list<string> Ids the node is waiting on start reports for */
    public array $stillStarting = [];

    public function stopAgentsForProtectedMode(string $initiatorAgentType, ?string $initiatorAgentIndex): void
    {
    }

    public function resumeAgentsForProtectedMode(): void
    {
    }

    public function agentsStillStarting(): array
    {
        return $this->stillStarting;
    }
}
