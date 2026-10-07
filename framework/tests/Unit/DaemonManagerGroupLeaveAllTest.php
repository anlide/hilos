<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Cluster\ClientMesh;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Group\DTO\GroupJoinSignalData;
use Hilos\Core\Group\DTO\GroupLeaveAllSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\WorkerGroupJoinDTO;
use Hilos\Socket\Worker\DTO\WorkerGroupLeaveAllDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The master's half of a change of person on a connection (HIL-1284).
 *
 * The leave is applied to the master's registry the moment the worker's frame arrives, as a join
 * is - so a join the same worker sends right after it survives. What is queued is only the
 * fan-out: every other worker of the node, never the sender, and every other node. A node that
 * receives the fan-out applies it to its registry and to all of its workers, and goes no further.
 */
final class DaemonManagerGroupLeaveAllTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-tab';
    private const string GROUP = 'hilos_notifications:7';
    private const string CARD_GROUP = 'hilos_data_export:9';

    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-group-leave-all-');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        unlink($this->logFile);
        Hilos::$sr = null;

        parent::tearDown();
    }

    public function testTheMasterDropsTheGroupsOnReceiptAndQueuesTheFanOutNamingTheSender(): void
    {
        $daemon = new GroupLeaveAllTestManager();
        $workers = $daemon->addWorkers(3);
        Hilos::$sr->subscribeToGroup(self::GROUP, $this->joinFrame(self::GROUP));
        Hilos::$sr->subscribeToGroup('hilos_notifications:3', $this->joinFrame('hilos_notifications:3', 'ak-other'));

        $workers->client(1)->receive(new WorkerGroupLeaveAllDTO(new GroupLeaveAllSignalData([self::ACCEPT_KEY])));

        $this->assertNull(Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, 'hilos_notifications'));
        $this->assertSame('hilos_notifications:3', Hilos::$sr->groupSubscriptionName('ak-other', 'hilos_notifications'));
        $signal = Hilos::$sr->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::GROUP_LEAVE_ALL, $signal->signalType->getType());
        $this->assertInstanceOf(GroupLeaveAllSignalData::class, $signal->data);
        $this->assertSame([self::ACCEPT_KEY], $signal->data->acceptKeys);
        $this->assertSame(1, $signal->data->exceptWorkerIndex);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testTheFanOutReachesEveryOtherWorkerAndEveryNodeButNotTheSender(): void
    {
        $daemon = new GroupLeaveAllTestManager();
        $workers = $daemon->addWorkers(3);
        $workers->client(1)->receive(new WorkerGroupLeaveAllDTO(new GroupLeaveAllSignalData([self::ACCEPT_KEY])));

        $daemon->dispatch();

        $frames = $workers->framesPerWorker();
        $this->assertSame([], $frames[1], 'the sender cleared its own mirror and may have joined again since');
        foreach ([0, 2] as $index) {
            $this->assertCount(1, $frames[$index]);
            $frame = WorkerDTO::factoryWorkerDTO($frames[$index][0]);
            $this->assertInstanceOf(WorkerGroupLeaveAllDTO::class, $frame);
            $this->assertSame([self::ACCEPT_KEY], $frame->data->acceptKeys);
            $this->assertNull($frame->data->exceptWorkerIndex);
        }
        $this->assertSame([[self::ACCEPT_KEY]], $daemon->announced);
    }

    /**
     * The block notice's export group joins right after the leave, from the same worker: the two
     * have to take effect in the order they were sent, and the fan-out must not undo the join.
     */
    public function testAJoinSentRightAfterTheLeaveSurvivesItAndItsFanOut(): void
    {
        $daemon = new GroupLeaveAllTestManager();
        $workers = $daemon->addWorkers(2);
        Hilos::$sr->subscribeToGroup(self::GROUP, $this->joinFrame(self::GROUP));

        $workers->client(0)->receive(new WorkerGroupLeaveAllDTO(new GroupLeaveAllSignalData([self::ACCEPT_KEY])));
        $workers->client(0)->receive(new WorkerGroupJoinDTO(new GroupJoinSignalData(self::CARD_GROUP, self::ACCEPT_KEY)));
        $daemon->dispatch();

        $this->assertNull(Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, 'hilos_notifications'));
        $this->assertSame(self::CARD_GROUP, Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, 'hilos_data_export'));
    }

    public function testANodeReceivingTheFanOutDropsTheGroupsAndTellsEveryWorkerWithoutAnEcho(): void
    {
        $daemon = new GroupLeaveAllTestManager();
        $workers = $daemon->addWorkers(2);
        Hilos::$sr->subscribeToGroup(self::GROUP, $this->joinFrame(self::GROUP));

        $daemon->deliverGroupLeaveAll('node-b', [self::ACCEPT_KEY]);

        $this->assertNull(Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, 'hilos_notifications'));
        foreach ($workers->framesPerWorker() as $frames) {
            $this->assertCount(1, $frames);
            $frame = WorkerDTO::factoryWorkerDTO($frames[0]);
            $this->assertInstanceOf(WorkerGroupLeaveAllDTO::class, $frame);
            $this->assertSame([self::ACCEPT_KEY], $frame->data->acceptKeys);
        }
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertSame([], $daemon->announced);
    }

    public function testANodeWithoutWorkersStillDropsTheGroupsOfItsRegistry(): void
    {
        $daemon = new GroupLeaveAllTestManager();
        Hilos::$sr->subscribeToGroup(self::GROUP, $this->joinFrame(self::GROUP));

        $daemon->deliverGroupLeaveAll('node-b', [self::ACCEPT_KEY]);

        $this->assertNull(Hilos::$sr->groupSubscriptionName(self::ACCEPT_KEY, 'hilos_notifications'));
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * @param string $group Full group name
     * @param string $acceptKey Joining connection
     * @return WebSocketGroupSubscribeSignalDTO Join as the registry records it
     */
    private function joinFrame(string $group, string $acceptKey = self::ACCEPT_KEY): WebSocketGroupSubscribeSignalDTO
    {
        return new WebSocketGroupSubscribeSignalDTO(
            acceptKey: $acceptKey,
            group: $group,
            params: [],
        );
    }
}

/**
 * Daemon with socketless worker links that records what it announces to other nodes.
 */
final class GroupLeaveAllTestManager extends DaemonManager
{
    /** @var list<list<string>> Accept keys announced to the other nodes, once per announcement */
    public array $announced = [];

    /**
     * @param int $count Worker links to open
     * @return GroupLeaveAllTestWorkerServer Registered worker server
     */
    public function addWorkers(int $count): GroupLeaveAllTestWorkerServer
    {
        $server = new GroupLeaveAllTestWorkerServer();
        for ($index = 0; $index < $count; $index++) {
            $server->addWorker();
        }
        $this->registerServer($server);

        return $server;
    }

    /**
     * Runs the loop step that dispatches whatever is queued.
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

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new GroupLeaveAllTestAgentManagerDaemon();
    }

    /**
     * @param ?ClientMesh $mesh Peer server of this node, null in these cases
     * @param list<string> $acceptKeys Connections leaving every group
     */
    protected function announceGroupLeaveAllToPeers(?ClientMesh $mesh, array $acceptKeys): void
    {
        $this->announced[] = $acceptKeys;
    }
}

/**
 * No agent starts in the leave-all tests.
 */
final class GroupLeaveAllTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type to start
     * @param ?string $agentIndex Agent index to start
     * @return AgentDaemonInterface Never returned
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('the leave-all test starts no agent');
    }
}

/**
 * Worker pool of socketless links that record each outgoing frame.
 */
final class GroupLeaveAllTestWorkerServer extends WorkerServer
{
    public function __construct()
    {
    }

    public function addWorker(): void
    {
        $client = new GroupLeaveAllTestWorkerClient();
        $client->setWorkerIndex(count($this->clients));
        new ReflectionProperty(WorkerClient::class, 'agentManager')->setValue(
            $client,
            new GroupLeaveAllTestAgentManagerDaemon(),
        );
        $this->clients[] = $client;
    }

    /**
     * @param int $index Worker index
     * @return GroupLeaveAllTestWorkerClient Link of that worker
     */
    public function client(int $index): GroupLeaveAllTestWorkerClient
    {
        $client = $this->clients[$index];
        assert($client instanceof GroupLeaveAllTestWorkerClient);

        return $client;
    }

    /**
     * @return list<list<string>> Frames by worker
     */
    public function framesPerWorker(): array
    {
        $frames = [];
        foreach ($this->clients as $client) {
            assert($client instanceof GroupLeaveAllTestWorkerClient);
            $frames[] = $client->frames;
        }

        return $frames;
    }

    protected function onStart(): void
    {
    }
}

/**
 * Socketless worker link: frames from the worker are fed through the ordinary parser, frames to it are recorded.
 */
final class GroupLeaveAllTestWorkerClient extends WorkerClient
{
    /** @var list<string> Frames written to this worker */
    public array $frames = [];

    public function __construct()
    {
    }

    /**
     * @param WorkerDTO $frame Frame the worker sent
     * @throws InvalidFormatException When the frame cannot be decoded
     * @throws InvalidArgumentException When handling the frame cannot name a queued signal
     * @throws AgentDaemonCreationFailedException When handling the frame cannot create an agent
     * @throws HilosException When the buffered frame refuses to become a DTO
     */
    public function receive(WorkerDTO $frame): void
    {
        $this->readBuffer .= $frame->toJson() . "\n";
        $this->processReadBuffer();
    }

    /**
     * @param string $message Frame sent to this worker
     */
    public function send(string $message): void
    {
        $this->frames[] = $message;
    }
}
