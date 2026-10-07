<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\DaemonSection\DaemonProcessRoster;
use Hilos\DaemonSection\DTO\DaemonMasterProcessRosterSignalData;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/** The node sends a complete frame at start, on change and periodically. */
final class DaemonNodePictureTest extends TestCase
{
    private ?ClusterContext $previousCluster = null;
    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        $this->previousCluster = Hilos::$cluster;
        $this->previousRouter = Hilos::$sr;
        Hilos::$cluster = null;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$cluster = $this->previousCluster;
        Hilos::$sr = $this->previousRouter;
        parent::tearDown();
    }

    public function testStandaloneStartAndTheTwoReportIntervals(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        $first = Hilos::$sr?->getNextQueuedSignal();
        self::assertSame(HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT, $first?->signalName->getName());
        $payload = $first?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $payload);
        self::assertSame('standalone', $payload->picture->nodeId);
        self::assertSame(NodeRole::Master, $payload->picture->role);

        $now = microtime(true);
        $agent->updatePicture(new NodeDaemonPicture('standalone', NodeRole::Slave, 1));
        $agent->reportIfDue($now + 4.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        $agent->reportIfDue($now + 6.0);
        $changed = Hilos::$sr?->getNextQueuedSignal()?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $changed);
        self::assertSame(NodeRole::Slave, $changed->picture->role);
        self::assertGreaterThan(1, $changed->picture->sampledAt);

        $agent->reportIfDue($now + 65.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        $agent->reportIfDue($now + 67.0);
        self::assertNotNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testNodeWireRejectsAnInvalidIdentityOrRole(): void
    {
        $good = new DaemonNodePictureSignalData(new NodeDaemonPicture('n1', NodeRole::Master, 12));
        self::assertEquals($good, DaemonNodePictureSignalData::fromArray($good->toArray()));

        $this->expectException(InvalidFormatException::class);
        DaemonNodePictureSignalData::fromArray(['nodeId' => 'n1', 'role' => 'leader', 'sampledAt' => 12]);
    }

    public function testMasterFrameReplacesProcessesAndChangesTheNextWholeReport(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        $roster = new DaemonProcessRoster([], [], 1);
        $wire = new DaemonMasterProcessRosterSignalData('standalone', $roster);

        $agent->onSignalAgent(
            new AgentSignalData(data: DaemonMasterProcessRosterSignalData::fromArray($wire->toArray())),
            SignalSource::DAEMON,
            HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER,
        );
        $agent->reportIfDue(microtime(true) + 6.0);
        $reported = Hilos::$sr?->getNextQueuedSignal()?->data?->data;
        self::assertInstanceOf(DaemonNodePictureSignalData::class, $reported);
        self::assertEquals($roster, $reported->picture->processes);
    }

    public function testForeignSenderOrNodeIsRefused(): void
    {
        $agent = new DaemonNodeAgent();
        $agent->onStart();
        Hilos::$sr?->getNextQueuedSignal();
        foreach ([
            [SignalSource::AGENT, 'standalone'],
            [SignalSource::DAEMON, 'other-node'],
        ] as [$sender, $nodeId]) {
            try {
                $agent->onSignalAgent(
                    new AgentSignalData(data: new DaemonMasterProcessRosterSignalData($nodeId, new DaemonProcessRoster([], null, 0))),
                    $sender,
                    HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER,
                );
                self::fail('Foreign master roster was accepted');
            } catch (AgentException) {
            }
        }
        $agent->reportIfDue(microtime(true) + 6.0);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
    }
}
