<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Generator;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Page\DTO\PageAccessReassessUserSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Incoming database facts, agent frames and page-access announcements share one effect queue.
 *
 * A busy head keeps a later frame out of the worker until the dispatcher reaches it, and the
 * earlier fact stays ahead. An empty queue still delivers inside the call that admitted the frame.
 */
final class DaemonManagerPeerInboundOrderTest extends TestCase
{
    /** @var string Node the replicas in these cases arrive from */
    public const string REMOTE_NODE = 'node-b';

    /** @var string Collection the database fact names */
    public const string COLLECTION = 'settings';

    /** @var string Row id the database fact names */
    public const string ROW_ID = '7';

    /** @var string Signal a neighbour forwarded to the agent that is already up */
    public const string FORWARDED_SIGNAL = 'peer_inbound_forwarded';

    /** @var int Person the page-access announcement names */
    public const int USER_ID = 41;

    private ?EventLoop $loop = null;

    private ?PeerInboundOrderTestManager $daemon = null;

    private bool $attached = false;

    /**
     * A database fact admitted before a forwarded agent frame reaches the worker first.
     * Until the dispatcher runs, the worker has neither.
     */
    public function testABusyQueueWritesDbSyncBeforeAForwardedAgentFrame(): void
    {
        $daemon = $this->busyManager();

        $daemon->applyRemoteDbSync(self::REMOTE_NODE, SignalTypeConstants::DB_SYNC_UPDATED, $daemon->dbSyncUpdated());
        $daemon->deliverSignalToAgent(PeerInboundOrderTestAgentDaemon::AGENT_TYPE, null, $daemon->forwardedSignal());

        $this->assertSame([], $daemon->workerServer->writtenFrames(), 'A busy queue writes nothing before the dispatcher runs');
        $this->drain($daemon);

        $this->assertSame([
            WorkerDbSyncUpdatedMessageDTO::class,
            DaemonAgentMessageDTO::class,
        ], $this->inboundFrames($daemon));
    }

    /**
     * A page-access re-decision waits behind the same neighbour's database fact.
     */
    public function testABusyQueueWritesDbSyncBeforeAPageAccessReDecision(): void
    {
        $daemon = $this->busyManager();

        $daemon->applyRemoteDbSync(self::REMOTE_NODE, SignalTypeConstants::DB_SYNC_UPDATED, $daemon->dbSyncUpdated());
        $daemon->deliverPageAccessReassess(self::REMOTE_NODE, $daemon->pageAccessReassess());

        $this->assertSame([], $daemon->workerServer->writtenFrames(), 'A busy queue writes nothing before the dispatcher runs');
        $this->drain($daemon);

        $this->assertSame([
            WorkerDbSyncUpdatedMessageDTO::class,
            WorkerPageAccessReassessMessageDTO::class,
        ], $this->inboundFrames($daemon));
    }

    /**
     * An empty queue delivers a forwarded frame inside the admitting call. The loop is on,
     * and no later dispatch pass is required.
     */
    public function testAnEmptyQueueWritesAForwardedAgentFrameInTheSameCall(): void
    {
        $daemon = $this->idleManager();

        $daemon->deliverSignalToAgent(PeerInboundOrderTestAgentDaemon::AGENT_TYPE, null, $daemon->forwardedSignal());

        $this->assertSame([DaemonAgentMessageDTO::class], $this->inboundFrames($daemon));
    }

    protected function tearDown(): void
    {
        try {
            if ($this->attached && $this->daemon !== null) {
                new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->daemon);
            }
        } finally {
            $this->loop?->close();
            $this->loop = null;
            $this->daemon = null;
            $this->attached = false;
            Hilos::$sr = null;
            Hilos::$db = null;
            parent::tearDown();
        }
    }

    private function idleManager(): PeerInboundOrderTestManager
    {
        $daemon = $this->manager();
        $daemon->workerServer->forgetFrames();

        return $daemon;
    }

    /**
     * Occupies the effect queue with a head that outlives the first portion, the way a
     * retained report does, so the next admitted frame cannot run yet.
     */
    private function busyManager(): PeerInboundOrderTestManager
    {
        $daemon = $this->manager();
        new ReflectionMethod(DaemonManager::class, 'queueSignalEffect')->invoke($daemon, static function (): Generator {
            yield;
            // 512 yields outlive one 64-step portion, so this head is still queued afterwards.
            for ($step = 0; $step < 512; $step++) {
                yield;
            }
        });
        $daemon->workerServer->forgetFrames();

        return $daemon;
    }

    private function manager(): PeerInboundOrderTestManager
    {
        $daemon = new PeerInboundOrderTestManager();
        $this->daemon = $daemon;
        $this->loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($daemon, $this->loop);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($daemon);
        $this->attached = true;

        return $daemon;
    }

    private function drain(PeerInboundOrderTestManager $daemon): void
    {
        for ($portion = 0; $portion < 32; $portion++) {
            $daemon->dispatch();
        }
    }

    /**
     * @return list<class-string<WorkerDTO>> Database, agent and page-access frames, in write order
     */
    private function inboundFrames(PeerInboundOrderTestManager $daemon): array
    {
        $classes = [];
        foreach ($daemon->workerServer->writtenFrames() as $frame) {
            $dto = WorkerDTO::factoryWorkerDTO($frame);
            if ($dto instanceof WorkerDbSyncUpdatedMessageDTO
                || $dto instanceof DaemonAgentMessageDTO
                || $dto instanceof WorkerPageAccessReassessMessageDTO
            ) {
                $classes[] = $dto::class;
            }
        }

        return $classes;
    }
}

/**
 * Daemon with one recording worker and one agent that has already started.
 */
final class PeerInboundOrderTestManager extends DaemonManager
{
    public readonly PeerInboundOrderTestWorkerServer $workerServer;

    public function __construct()
    {
        parent::__construct();

        $this->workerServer = new PeerInboundOrderTestWorkerServer();
        // The recording server never binds a socket, so the roster the delivery door reads is set here.
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue(
            $this->workerServer,
            $this->getAgentManagerDaemon(),
        );
        $this->registerServer($this->workerServer);
        $this->declareWorkerReads();
        $this->seatAgent();
        Hilos::$db = new PeerInboundOrderTestDbContext();
    }

    /**
     * Builds the database fact these cases admit, as a worker of the writing node produces it.
     */
    public function dbSyncUpdated(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::DB_SYNC_UPDATED),
            new SignalName(SignalConstants::DB_SYNC_UPDATED),
            new DbSyncUpdatedSignalData(
                DaemonManagerPeerInboundOrderTest::COLLECTION,
                DaemonManagerPeerInboundOrderTest::ROW_ID,
                ['title' => 'Ada'],
            ),
        );
    }

    /**
     * Builds the frame a neighbour forwarded to the agent that is already up.
     */
    public function forwardedSignal(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName(DaemonManagerPeerInboundOrderTest::FORWARDED_SIGNAL),
            new SignalData([]),
        );
    }

    /**
     * Builds a by-user page-access announcement.
     */
    public function pageAccessReassess(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::PAGE_ACCESS_REASSESS_USER),
            new SignalName(SignalConstants::PAGE_ACCESS_REASSESS_USER),
            new PageAccessReassessUserSignalData(DaemonManagerPeerInboundOrderTest::USER_ID),
        );
    }

    /**
     * Runs one dispatcher portion.
     */
    public function dispatch(): void
    {
        $drain = Closure::bind(
            static function (DaemonManager $daemon): void {
                $daemon->dispatchSignals();
            },
            null,
            DaemonManager::class,
        );

        $drain($this);
    }

    /**
     * Keeps routing inside this process: these cases deliver through the doors directly.
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /**
     * The agent these cases deliver to is seated by the fixture, not started on demand.
     */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new PeerInboundOrderTestAgentManager();
    }

    /**
     * Tells the master this worker reads the collection the database fact names.
     */
    private function declareWorkerReads(): void
    {
        $this->getAgentManagerDaemon()->handleSourceInterest(
            new WorkerSourceInterestDTO([], [DaemonManagerPeerInboundOrderTest::COLLECTION]),
            PeerInboundOrderTestWorkerClient::WORKER_INDEX,
        );
    }

    /**
     * Seats the agent the way a finished start does, so a forwarded frame is written through.
     */
    private function seatAgent(): void
    {
        $agent = new PeerInboundOrderTestAgentDaemon();
        $agent->setWorkerClient($this->workerServer->worker);
        $agentId = PeerInboundOrderTestAgentDaemon::AGENT_TYPE;
        $this->getAgentManagerDaemon()->addAgent(
            $agentId,
            $agent,
            PeerInboundOrderTestWorkerClient::WORKER_INDEX,
            false,
        );
        $this->getAgentManagerDaemon()->handleAgentStarted(new WorkerAgentStartedDTO($agentId, $agentId));
    }
}

/**
 * Starts no agent. The fixture seats the one these cases deliver to.
 */
final class PeerInboundOrderTestAgentManager extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Never returned; this fixture seats its agent itself
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('the inbound-order fixture seats its agent itself');
    }
}

/**
 * Mounts nothing. Where a row lands is not what these cases read.
 */
final class PeerInboundOrderTestDbContext extends HilosDbContext
{
    public function configure(): void
    {
    }
}

/**
 * One socketless worker link that keeps every frame the daemon writes.
 */
final class PeerInboundOrderTestWorkerServer extends WorkerServer
{
    public readonly PeerInboundOrderTestWorkerClient $worker;

    public function __construct()
    {
        $this->worker = new PeerInboundOrderTestWorkerClient();
        $this->addClient($this->worker);
    }

    /**
     * @return list<string> Raw frames written to the worker, in order
     */
    public function writtenFrames(): array
    {
        return $this->worker->writtenFrames();
    }

    public function forgetFrames(): void
    {
        $this->worker->forgetFrames();
    }

    protected function onStart(): void
    {
    }
}

/**
 * A worker link that records what was written to it.
 */
final class PeerInboundOrderTestWorkerClient extends WorkerClient
{
    /** @var int Index the daemon addresses this link by */
    public const int WORKER_INDEX = 1;

    /** @var list<string> Raw frames the daemon wrote to this link, in order */
    private array $written = [];

    public function __construct()
    {
        $this->setWorkerIndex(self::WORKER_INDEX);
    }

    public function isRegistered(): bool
    {
        return true;
    }

    /**
     * @param string $data Frame the daemon wants written to this worker
     */
    public function send(string $data): void
    {
        $this->written[] = $data;
    }

    /**
     * @return list<string> Raw frames written to this worker, in order
     */
    public function writtenFrames(): array
    {
        return $this->written;
    }

    public function forgetFrames(): void
    {
        $this->written = [];
    }
}

/**
 * Agent daemon linked to the recording worker from the moment it is seated.
 */
final class PeerInboundOrderTestAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = 'peer_inbound_order_agent';

    /**
     * Shares the fixture's one worker.
     */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }
}
