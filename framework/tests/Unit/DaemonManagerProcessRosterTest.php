<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Leadership;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalRouter;
use Hilos\DaemonSection\DaemonWorkerPicture;
use Hilos\DaemonSection\DTO\DaemonMasterProcessRosterSignalData;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/** The master builds only when due and never sends from a frozen node. */
final class DaemonManagerProcessRosterTest extends TestCase
{
    /** @var class-string<Hilos> */
    private string $previousAppClass;
    private ?RtContext $previousRt;
    private ?ClusterContext $previousCluster;
    private ?SignalRouter $previousRouter;

    protected function setUp(): void
    {
        $this->previousAppClass = Hilos::appClass();
        $this->previousRt = Hilos::$rt;
        $this->previousCluster = Hilos::$cluster;
        $this->previousRouter = Hilos::$sr;
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, ProcessRosterManagerTestHilos::class);
        Hilos::$rt = null;
        Hilos::$cluster = null;
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        Hilos::$rt = $this->previousRt;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$sr = $this->previousRouter;
        parent::tearDown();
    }

    public function testLostWholeFrameIsRepairedAfterAMinuteWithoutPerTickScan(): void
    {
        $manager = new ProcessRosterTestDaemonManager();
        $server = $manager->addRosterServer();
        $manager->tickRosterAt(100.0);
        self::assertCount(1, $server->delivered);
        self::assertSame(1, $server->rosterReads);
        $frame = $server->delivered[0]->signal->data->data;
        self::assertInstanceOf(DaemonMasterProcessRosterSignalData::class, $frame);
        self::assertSame([], $frame->roster->workers);
        self::assertSame([], $frame->roster->unplacedAgentIds);

        $manager->tickRosterAt(101.0);
        self::assertCount(1, $server->delivered);
        self::assertSame(1, $server->rosterReads);
        $manager->tickRosterAt(161.0);
        self::assertCount(2, $server->delivered, 'The next whole frame repairs a lost first frame');
        self::assertSame(2, $server->rosterReads);
    }

    public function testFreezeSkipsTheFrameAndLiftForcesAWholeReport(): void
    {
        $manager = new ProcessRosterTestDaemonManager();
        $server = $manager->addRosterServer();
        $runtime = new ProcessRosterFreezeRtContext();
        $runtime->hilosProtectedModeRuntime->phase = StateProtectedModeRuntime::PHASE_ACTIVE;
        Hilos::$rt = $runtime;

        $manager->tickRosterAt(100.0);
        self::assertSame([], $server->delivered);
        self::assertSame(0, $server->rosterReads);

        $runtime->hilosProtectedModeRuntime->phase = StateProtectedModeRuntime::PHASE_INACTIVE;
        $manager->tickRosterAt(101.0);
        self::assertCount(1, $server->delivered);
        self::assertSame(1, $server->rosterReads);
    }

    public function testNewNodeAgentStartOverridesAnOldBuildRetry(): void
    {
        $manager = new ProcessRosterTestDaemonManager();
        $server = $manager->addRosterServer();
        $server->failNextRosterRead = true;
        $manager->tickRosterAt(100.0);
        self::assertSame([], $server->delivered);
        $manager->tickRosterAt(101.0);
        self::assertSame(1, $server->rosterReads, 'A failed build is not retried every tick');

        $manager->onAgentStarted(HilosAgentType::HILOS_DAEMON_NODE);
        $manager->tickRosterAt(102.0);
        self::assertCount(1, $server->delivered);
        self::assertSame(2, $server->rosterReads);
    }

    public function testUnknownLeadershipCannotPublishAnAuthoritativeEmptyUnplacedList(): void
    {
        $manager = new ProcessRosterTestDaemonManager();
        $server = $manager->addRosterServer();
        $cluster = new ClusterContext();
        $cluster->registerLeadership(new ProcessRosterUnreadableLeadership());
        Hilos::$cluster = $cluster;

        $manager->tickRosterAt(100.0);
        self::assertSame([], $server->delivered);
        self::assertSame(0, $server->rosterReads);
    }
}

/** Daemon test double exposing one guarded roster step. */
final class ProcessRosterTestDaemonManager extends DaemonManager
{
    /** @return ProcessRosterManagerWorkerServer Worker source registered with this master */
    public function addRosterServer(): ProcessRosterManagerWorkerServer
    {
        $server = new ProcessRosterManagerWorkerServer();
        $this->registerServer($server);
        return $server;
    }

    /** @param float $now Controlled loop time */
    public function tickRosterAt(float $now): void
    {
        new ReflectionMethod(DaemonManager::class, 'tickProcessRoster')->invoke($this, $now);
    }

    /** @return SignalRouter Empty routing table for directly addressed master frames */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Manager that reports the node agent started */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new ProcessRosterManagerAgentManager();
    }
}

/** Worker source with no workers and a captured addressed frame. */
final class ProcessRosterManagerWorkerServer extends WorkerServer
{
    /** @var list<DaemonAgentMessageDTO> */
    public array $delivered = [];
    public int $rosterReads = 0;
    public bool $failNextRosterRead = false;

    public function __construct()
    {
    }

    /**
     * @return list<DaemonWorkerPicture> Known empty live roster
     * @throws LogicException When this case arranges a source failure
     */
    public function liveWorkerPictures(): array
    {
        $this->rosterReads++;
        if ($this->failNextRosterRead) {
            $this->failNextRosterRead = false;
            throw new LogicException('The worker roster is unavailable');
        }
        return [];
    }

    /**
     * @param string $agentType Receiver type
     * @param ?string $agentIndex Receiver index
     * @param DaemonAgentMessageDTO $messageDto Addressed master frame
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        $this->delivered[] = $messageDto;
    }

    protected function onStart(): void
    {
    }
}

/** Manager holding only the node agent for this lifecycle test. */
final class ProcessRosterManagerAgentManager extends AgentManagerDaemon
{
    /**
     * @param string $agentId Receiver id
     * @return bool The node agent has completed its start
     */
    public function isAgentStarted(string $agentId): bool
    {
        return $agentId === HilosAgentType::HILOS_DAEMON_NODE;
    }

    /**
     * @param string $agentType Unused agent type
     * @param ?string $agentIndex Unused instance index
     * @return AgentDaemonInterface Never returned
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('The roster test starts no agents');
    }
}

/** Runtime facade exposing only the phase the master is allowed to read. */
final class ProcessRosterFreezeRtContext extends RtContext
{
    public object $hilosProtectedModeRuntime;

    public function __construct()
    {
        $this->hilosProtectedModeRuntime = (object)['phase' => StateProtectedModeRuntime::PHASE_INACTIVE];
    }

    public function configure(): void
    {
    }
}

/** Topology with the node receiver declared as a per-node agent. */
abstract class ProcessRosterManagerTestHilos extends Hilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_DAEMON_NODE => [AgentRegistryKey::SCOPE => AgentScope::NODE],
    ];
}

/** Failure seam proving the roster never guesses leadership. */
final class ProcessRosterUnreadableLeadership implements Leadership
{
    /**
     * @return bool No verdict is available
     * @throws LogicException Every attempt to read leadership fails
     */
    public function amLeader(): bool
    {
        throw new LogicException('Leadership is unavailable');
    }

    /** @return ?string No known leader */
    public function leaderId(): ?string
    {
        return null;
    }

    /** @return bool No known leader WebSocket */
    public function leaderWebSocketOpen(): bool
    {
        return false;
    }

    /** @return bool No known quorum */
    public function hasQuorum(): bool
    {
        return false;
    }
}
