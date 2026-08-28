<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Placement\AgentLocation;
use Hilos\Cluster\WorkerPlacement;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Config\PageAgentIndexKey;
use Hilos\Core\Page\Config\PageAgentIndexSource;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Tests\Unit\Cluster\Peer\PeerSignalDTOTest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The two master deliveries built from a subscription record, once they ask placement (HIL-745).
 *
 * Both address an agent the ordinary route never resolved: the connection-close fan-out reaches
 * the instance a closing connection was subscribed to, and the unsubscribe of a replaced
 * subscription reaches the instance a tab has just left. Both built an AgentDestination by hand
 * and sent it into this node's own workers, whatever node the agent actually ran on. On a
 * follower that raised an AgentNotFoundException nobody catches, which by HIL-619 takes the node
 * out of the cluster; on a leader it started a second instance of an owner already running
 * elsewhere, which is the outcome placement exists to prevent.
 *
 * So the cases below are mostly about what does NOT happen locally. A per-instance page is the
 * carrier because no demo page is one yet, and the placement lookup is a fixture: the point is
 * which of the three answers each delivery gets, not how a real cluster arrives at them.
 *
 * What the mesh hop itself carries is out of reach here and stays uncovered: PeerServer is
 * final and takes a socket, so no unit test can watch a peer send, and nothing today pins
 * PeerServer::sendSignalToNode() beyond the shape of the frame it wraps
 * ({@see PeerSignalDTOTest}). What this file can hold to account - and does - is that a
 * non-local answer stops the local delivery dead, which is the half the defect lived in.
 */
final class DaemonManagerPlacedFanOutTest extends TestCase
{
    private const string ACCEPT_KEY = 'placed-fan-out-ak';

    private const string CHAT_AGENT = PlacedFanOutChatPage::SUBSCRIPTION_AGENT_TYPE;

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$browser = null;
        Hilos::$cluster = null;

        parent::tearDown();
    }

    /**
     * The whole defect in one case: the agent runs on node-B, and this node keeps its hands off.
     */
    public function testTheCloseFanOutDoesNotStartTheInstanceHereWhenItRunsOnAnotherNode(): void
    {
        $manager = $this->subscribedManager('42');
        $this->installPlacement([self::CHAT_AGENT . ':42' => AgentLocation::onNode('node-B')]);

        $this->queueClose();
        $manager->drainQueue();

        $this->assertNotContains(
            SignalTypeConstants::CONNECTION_CLOSE . '@' . self::CHAT_AGENT . ':42',
            $manager->deliveries(),
        );
    }

    /**
     * The lookup is asked about the agent the RECORD names, index and all. Asking about the
     * page's fallback agent instead would answer "here" for an instance living elsewhere, which
     * is the defect wearing a placement call.
     */
    public function testTheCloseFanOutAsksWhereTheSubscriptionsOwnInstanceRuns(): void
    {
        $manager = $this->subscribedManager('42');
        $placement = $this->installPlacement([self::CHAT_AGENT . ':42' => AgentLocation::onNode('node-B')]);

        $this->queueClose();
        $manager->drainQueue();

        $this->assertContains(self::CHAT_AGENT . ':42', $placement->asked);
    }

    /**
     * An address nobody knows is not an excuse to deliver locally, and not an occasion to tell
     * the browser anything either: the subscription this would answer is the one whose
     * connection has just gone. The fan-out ignores what the delivery reports for that reason,
     * which is also why no placement can be asked for from here - the delivery never asks, and
     * the only caller that does is the ordinary walk.
     */
    public function testAnUnknownAddressInTheCloseFanOutDeliversNothingAndAnswersNobody(): void
    {
        $manager = $this->subscribedManager('42');
        $this->installPlacement([self::CHAT_AGENT . ':42' => AgentLocation::unknown()]);

        $this->queueClose();
        $manager->drainQueue();

        $this->assertNotContains(
            SignalTypeConstants::CONNECTION_CLOSE . '@' . self::CHAT_AGENT . ':42',
            $manager->deliveries(),
        );
        $this->assertSame(0, $manager->unreachableAnswers);
    }

    /**
     * The ordinary walk reaching that very agent still silences the fan-out. Delivering again
     * would run one agent's close hook twice for one disconnect.
     */
    public function testTheCloseFanOutStaysSilentWhenTheWalkAlreadyAddressedTheSameInstance(): void
    {
        $manager = $this->subscribedManager(null);
        $this->installPlacement([]);

        $this->queueClose();
        $manager->drainQueue();

        $this->assertCount(1, $this->closesToTheFallbackAgent($manager));
    }

    /**
     * And it stays silent when the walk addressed that agent somewhere ELSE, which is the case
     * the shared marker interface was introduced for: the walk records whom it ADDRESSED, not
     * whom it reached. A subscription served by the page's fallback agent is normally served by
     * the project's lifecycle agent too, so an instance on another node would be sent the close
     * over the mesh by the walk and then sent it a second time by the fan-out - one disconnect,
     * two close hooks on the node that actually runs the agent.
     *
     * Both deliveries would leave this node over the mesh, so neither shows up in the local
     * deliveries: what tells them apart is the lookup. A silenced fan-out never gets as far as
     * asking where the agent runs, so one ask means the walk's and nobody else's.
     */
    public function testTheCloseFanOutStaysSilentWhenTheWalkAddressedThatAgentOnAnotherNode(): void
    {
        $manager = $this->subscribedManager(null);
        $placement = $this->installPlacement([
            PlacedFanOutFallbackAgent::AGENT_TYPE => AgentLocation::onNode('node-B'),
        ]);

        $this->queueClose();
        $manager->drainQueue();

        $this->assertSame([], $this->closesToTheFallbackAgent($manager));
        $this->assertSame(1, $this->timesAskedAboutTheFallbackAgent($placement));
    }

    /**
     * The same for the answer that names no node at all: unaddressable is still addressed, and a
     * fan-out that read it as "the walk did nothing" would go on to address it once more - this
     * time with nothing to stop the delivery being local, since an unknown address is what the
     * fan-out used to assume was here.
     */
    public function testTheCloseFanOutStaysSilentWhenTheWalkCouldNotPlaceThatAgent(): void
    {
        $manager = $this->subscribedManager(null);
        $placement = $this->installPlacement([
            PlacedFanOutFallbackAgent::AGENT_TYPE => AgentLocation::unknown(),
        ]);

        $this->queueClose();
        $manager->drainQueue();

        $this->assertSame([], $this->closesToTheFallbackAgent($manager));
        $this->assertSame(1, $this->timesAskedAboutTheFallbackAgent($placement));
    }

    /**
     * A single node answers "here" to everything, so the fan-out delivers exactly as it did
     * before the placement question existed.
     */
    public function testTheCloseFanOutDeliversLocallyOnASingleNode(): void
    {
        $manager = $this->subscribedManager('42');
        Hilos::$cluster = null;

        $this->queueClose();
        $manager->drainQueue();

        $this->assertContains(
            SignalTypeConstants::CONNECTION_CLOSE . '@' . self::CHAT_AGENT . ':42',
            $manager->deliveries(),
        );
    }

    /**
     * The tab moved to instance 43 and the agent of 42 lives on node-B, so the unsubscribe it is
     * owed is not this node's to deliver. Instance 43 is still addressed here, which is what
     * makes the missing delivery a decision rather than a stalled drain.
     */
    public function testTheReplacedUnsubscribeDoesNotGoLocalWhenTheOldInstanceRunsElsewhere(): void
    {
        $manager = $this->subscribedManager('42');
        $this->installPlacement([self::CHAT_AGENT . ':42' => AgentLocation::onNode('node-B')]);

        $this->queueSubscribe('43');
        $manager->drainQueue();

        $this->assertNotContains(
            SignalTypeConstants::PAGE_UNSUBSCRIBE . '@' . self::CHAT_AGENT . ':42',
            $manager->deliveries(),
        );
        $this->assertContains(
            SignalTypeConstants::PAGE_SUBSCRIBE . '@' . self::CHAT_AGENT . ':43',
            $manager->deliveries(),
        );
    }

    /**
     * An unknown address drops the unsubscribe with its log line. A page_unsubscribe owes nobody
     * an answer - least of all the tab that has already moved on to its next page - so nothing
     * is said to the browser about it.
     */
    public function testAnUnknownAddressDropsTheReplacedUnsubscribeAndAnswersNobody(): void
    {
        $manager = $this->subscribedManager('42');
        $this->installPlacement([self::CHAT_AGENT . ':42' => AgentLocation::unknown()]);

        $this->queueSubscribe('43');
        $manager->drainQueue();

        $this->assertNotContains(
            SignalTypeConstants::PAGE_UNSUBSCRIBE . '@' . self::CHAT_AGENT . ':42',
            $manager->deliveries(),
        );
        $this->assertSame(0, $manager->unreachableAnswers);
    }

    /**
     * On a single node the old instance is told first and the new one second, exactly as before.
     */
    public function testTheReplacedUnsubscribeIsDeliveredLocallyOnASingleNode(): void
    {
        $manager = $this->subscribedManager('42');
        Hilos::$cluster = null;

        $this->queueSubscribe('43');
        $manager->drainQueue();

        $this->assertSame(
            [
                SignalTypeConstants::PAGE_UNSUBSCRIBE . '@' . self::CHAT_AGENT . ':42',
                SignalTypeConstants::PAGE_SUBSCRIBE . '@' . self::CHAT_AGENT . ':43',
            ],
            $manager->deliveries(),
        );
    }

    /**
     * @param PlacedFanOutPlacement $placement Lookup the case installed
     * @return int Times the fallback agent's whereabouts were asked for during the drain
     */
    private function timesAskedAboutTheFallbackAgent(PlacedFanOutPlacement $placement): int
    {
        return count(array_filter(
            $placement->asked,
            static fn(string $agentId): bool => $agentId === PlacedFanOutFallbackAgent::AGENT_TYPE,
        ));
    }

    /**
     * @param PlacedFanOutTestManager $manager Master whose deliveries are being read
     * @return list<string> Connection closes this node delivered to the fallback agent, in order
     */
    private function closesToTheFallbackAgent(PlacedFanOutTestManager $manager): array
    {
        return array_values(array_filter(
            $manager->deliveries(),
            static fn(string $delivery): bool => str_starts_with(
                $delivery,
                SignalTypeConstants::CONNECTION_CLOSE . '@' . PlacedFanOutFallbackAgent::AGENT_TYPE,
            ),
        ));
    }

    /**
     * Mounts the fixture browser and router, subscribes one connection to the per-instance page,
     * and hands back a master with the setup deliveries already forgotten.
     *
     * The placement is installed by the CASE and not here, so the subscribe itself is always
     * resolved on a single node - what each case is about is the delivery that comes after it.
     *
     * @param ?string $chatId Instance the connection subscribes to, or null to be served by the fallback agent
     * @return PlacedFanOutTestManager Master exposing its queue drain and its deliveries
     */
    private function subscribedManager(?string $chatId): PlacedFanOutTestManager
    {
        $manager = new PlacedFanOutTestManager();
        Hilos::$browser = new PerInstanceTestBrowser();

        $this->queueSubscribe($chatId);
        $manager->drainQueue();
        $manager->forgetDeliveries();

        return $manager;
    }

    /**
     * Registers a placement lookup that records what it was asked about.
     *
     * An agent the map does not name is answered "here", which is what an ordinary node answers
     * for everything it runs.
     *
     * @param array<string, AgentLocation> $placements Agent id to the location it is at
     * @return PlacedFanOutPlacement Lookup the case can read its asks off
     */
    private function installPlacement(array $placements): PlacedFanOutPlacement
    {
        $placement = new PlacedFanOutPlacement($placements);
        $context = new ClusterContext();
        $context->registerWorkerPlacement($placement);
        Hilos::$cluster = $context;

        return $placement;
    }

    /**
     * @param ?string $chatId Instance to subscribe to, or null for a subscription naming none
     */
    private function queueSubscribe(?string $chatId): void
    {
        $params = $chatId === null ? [] : ['chatId' => $chatId];
        $this->queue(
            SignalTypeConstants::PAGE_SUBSCRIBE,
            PlacedFanOutChatPage::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, PlacedFanOutChatPage::PAGE, $params),
        );
    }

    private function queueClose(): void
    {
        $this->queue(
            SignalTypeConstants::CONNECTION_CLOSE,
            SignalTypeConstants::CONNECTION_CLOSE,
            new WebSocketCloseSignalDTO(self::ACCEPT_KEY),
        );
    }

    /**
     * @param string $signalType Signal type to queue
     * @param string $signalName Signal name to queue under
     * @param SignalDataInterface $data Payload as the WebSocket layer parsed it
     */
    private function queue(string $signalType, string $signalName, SignalDataInterface $data): void
    {
        Hilos::$sr->queueSignal(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType($signalType),
            new SignalName($signalName),
            $data,
        );
    }
}

/**
 * Placement lookup answering a fixed map and remembering every agent id it was asked about.
 */
final class PlacedFanOutPlacement implements WorkerPlacement
{
    /** @var list<string> Agent ids this lookup was asked to locate, in order */
    public array $asked = [];

    /**
     * @param array<string, AgentLocation> $placements Agent id to the location it is at
     */
    public function __construct(private readonly array $placements)
    {
    }

    public function locate(string $agentType, ?string $agentIndex): AgentLocation
    {
        $agentId = $agentIndex !== null ? "{$agentType}:{$agentIndex}" : $agentType;
        $this->asked[] = $agentId;

        return $this->placements[$agentId] ?? AgentLocation::here();
    }
}

/**
 * Master carrying a recording worker server and counting what it answers unreachable
 * subscriptions with, which is the one reaction the two record-driven deliveries must not have.
 */
final class PlacedFanOutTestManager extends DaemonManager
{
    /** @var int Times this master answered a subscription it could not carry */
    public int $unreachableAnswers = 0;

    /** The stand-in worker server the drain finds and delivers through */
    private PlacedFanOutTestWorkerServer $workerServer;

    public function __construct()
    {
        parent::__construct();

        $this->workerServer = new PlacedFanOutTestWorkerServer();
        $this->registerServer($this->workerServer);
    }

    /**
     * Runs the private queue drain the daemon loop runs at the end of each iteration.
     */
    public function drainQueue(): void
    {
        new ReflectionClass(DaemonManager::class)->getMethod('dispatchSignals')->invoke($this);
    }

    /**
     * @return list<string> Deliveries as `<signal type>@<agent type>[:<index>]`, in order
     */
    public function deliveries(): array
    {
        return $this->workerServer->deliveries;
    }

    /**
     * Clears the recorded deliveries so a case can assert only what came after its setup.
     */
    public function forgetDeliveries(): void
    {
        $this->workerServer->deliveries = [];
    }

    /**
     * Counts the answer instead of queueing it, which is what this file is watching for.
     *
     * @param SignalDTO $signal Signal that could not be carried to the node hosting its agent
     */
    protected function answerUnreachableSubscription(SignalDTO $signal): void
    {
        $this->unreachableAnswers++;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new PlacedFanOutTestRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new PlacedFanOutTestAgentManagerDaemon();
    }
}

final class PlacedFanOutTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * A worker server that records the handoff instead of starting a process.
 */
final class PlacedFanOutTestWorkerServer extends WorkerServer
{
    /** @var list<string> Deliveries as `<signal type>@<agent type>[:<index>]`, in order */
    public array $deliveries = [];

    public function __construct()
    {
    }

    /**
     * @param string $agentType Agent type the signal was routed to
     * @param ?string $agentIndex Agent index for an indexed agent, or null
     * @param DaemonAgentMessageDTO $messageDto Signal wrapped for the worker
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        $address = $agentIndex === null ? $agentType : "{$agentType}:{$agentIndex}";
        $this->deliveries[] = $messageDto->signal->signalType->getType() . '@' . $address;
    }

    protected function onStart(): void
    {
    }
}

/**
 * A page served by the agent of ONE chat, which is what makes the address worth placing.
 */
final class PlacedFanOutChatPage extends AbstractPage
{
    public const string PAGE = 'placed_fan_out_chat';

    public const string SUBSCRIPTION_AGENT_TYPE = 'placed_fan_out_chat_agent';

    public const array SUBSCRIPTION_AGENT_INDEX = [
        PageAgentIndexKey::SOURCE => PageAgentIndexSource::PARAM,
        PageAgentIndexKey::PARAM => 'chatId',
        PageAgentIndexKey::FALLBACK_AGENT_TYPE => PlacedFanOutFallbackAgent::AGENT_TYPE,
    ];
}

/**
 * The agent a subscription naming no instance falls back to, and the one the router hands
 * WebSocket lifecycle signals - the ordinary project shape, and what makes a doubled
 * connection_close reachable at all.
 */
final class PlacedFanOutFallbackAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'placed_fan_out_fallback_agent';

    /**
     * No-op stop hook for placed fan-out tests.
     */
    public function onStop(): void
    {
    }
}

final class PlacedFanOutFallbackAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'placed_fan_out_fallback_agent';
}

final class PlacedFanOutTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for placed fan-out tests.
     */
    public function configure(): void
    {
    }
}

final class PlacedFanOutTestHilos extends Hilos
{
    public const array PAGES = [
        PlacedFanOutChatPage::PAGE => PlacedFanOutChatPage::class,
    ];

    public const array AGENTS = [
        PlacedFanOutFallbackAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => PlacedFanOutFallbackAgent::class,
            AgentRegistryKey::DAEMON => PlacedFanOutFallbackAgentDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new PlacedFanOutTestDbContext();
    }
}

/**
 * Router reading the placed fan-out fixture topology.
 */
final class PlacedFanOutTestRouter extends SignalRouter
{
    /**
     * Returns the fixture facade for topology reads.
     *
     * @return class-string<Hilos> Fixture facade class
     */
    protected function hilosClass(): string
    {
        return PlacedFanOutTestHilos::class;
    }

    /**
     * Returns the fixture owner of WebSocket lifecycle signals.
     *
     * @return ?string Fallback agent type
     */
    protected function getDefaultWebSocketLifecycleAgentType(): ?string
    {
        return PlacedFanOutFallbackAgent::AGENT_TYPE;
    }
}

/**
 * Browser context fixture answering one identity, which the per-instance address resolution
 * consults before it decides a subscription can be addressed at all.
 */
final class PlacedFanOutTestBrowser extends BrowserContext
{
    /**
     * Returns a settled anonymous identity, standing in for the project's connection registry.
     *
     * @param string $acceptKey Subscriber accept key (unused in the fixture)
     * @return ConnectionIdentity Identity every accept key is reported with
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(null);
    }
}
