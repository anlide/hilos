<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Integration;

use Closure;
use Demo\EcommerceShop\Agents\ProductAgent;
use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\PageConstants;
use Demo\EcommerceShop\Core\Agent\EcommerceShopAgentManager;
use Demo\EcommerceShop\Core\Daemon\EcommerceShopAgentManagerDaemon;
use Demo\EcommerceShop\Core\Daemon\EcommerceShopWorkerManager;
use Demo\EcommerceShop\Core\Router\EcommerceShopSignalRouter;
use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Pages\DTO\ProductPageSubscribeParams;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\AgentIdleTracker;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Daemon\AgentStartSink;
use Hilos\Core\Agent\Exception\WorkerClientNotFoundException;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Page\ActionRouteConfig;
use Hilos\Core\Page\Exception\PageSignalRouterNotFoundException;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Page\SignalRouteConfig;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUnsubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use LogicException;
use ReflectionClass;
use ReflectionProperty;

/**
 * The agent of a product page address comes up with its first visitor and goes to sleep after the last one.
 *
 * The socket between master and worker is bridged in-process, as in the framework's person agent
 * case: its transport has tests of its own. Everything on either side of it is the shop's
 * production code - the master's agent manager, the worker's signal router, agent manager and
 * page router, the {@see ProductAgent} and its daemon proxy, the start, the idle verdict and the
 * stop. No real time passes: the silence is backdated past the window the shop declares.
 *
 * Requires the test DB reset (composer run test:db-prepare).
 */
final class ProductAgentLifecycleTest extends IntegrationTestCase
{
    private const string ADDRESS = 'lifecycle-desk-lamp';

    private const string AGENT_ID = AgentType::PRODUCT . ':' . self::ADDRESS;

    private const string ACCEPT_KEY = 'product-lifecycle-ak';

    private ?SignalRouter $previousRouter = null;

    private ProductAgentLifecycleWorkerManager $worker;

    private EcommerceShopAgentManagerDaemon $master;

    private ProductAgentLifecycleWorkerServer $server;

    /** Bridges the shop's master and worker managers through the addressed-frame door. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRouter = Hilos::$sr;
        $this->worker = new ProductAgentLifecycleWorkerManager();
        $this->master = new EcommerceShopAgentManagerDaemon();
        $this->server = new ReflectionClass(ProductAgentLifecycleWorkerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($this->server, $this->master);
        $this->server->worker = $this->worker;
        $this->server->daemonManager = $this->master;
    }

    /** Releases what a started agent of the address took up and restores the router. */
    protected function tearDown(): void
    {
        TruthSourceRegistry::unregisterAgent(self::AGENT_ID);
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::AGENT_ID));
        SourceInterestRegistry::releaseConsumer(SourceConsumer::page(self::ACCEPT_KEY));
        ExecutionContext::setCurrentAgentId(null);
        Hilos::$sr = $this->previousRouter;
        parent::tearDown();
    }

    /** A subscription to the product page of an address raises exactly the agent of that address. */
    public function testASubscriptionToTheProductPageRaisesTheAgentOfItsAddress(): void
    {
        $this->sendSubscribeFrame();

        self::assertSame([self::AGENT_ID], $this->server->startedAgentIds);
        $agent = $this->worker->agents->getAgent(self::AGENT_ID);
        self::assertInstanceOf(ProductAgent::class, $agent);
        self::assertSame(self::ADDRESS, $agent->getIndex());
        self::assertSame(self::ADDRESS, $agent->address());
        self::assertSame([self::AGENT_ID], array_keys($this->worker->agents->getAgents()));
    }

    /** A visitor still on the page keeps the agent of its address up through a silence past the window. */
    public function testAVisitorOnThePageKeepsTheAgentUpThroughTheIdleWindow(): void
    {
        $this->subscribeInWorkerMirror();
        $this->sendSubscribeFrame();

        $this->worker->idleAndTick(self::AGENT_ID);

        self::assertTrue($this->worker->agents->hasAgent(self::AGENT_ID));
        self::assertSame([], $this->worker->daemonClientDouble->stoppedAgentIds());
    }

    /** The last visitor gone and the window passed, the agent stops; the next visitor raises a new one. */
    public function testTheAgentSleepsAfterTheLastVisitorAndTheNextVisitorRaisesANewOne(): void
    {
        $this->subscribeInWorkerMirror();
        $this->sendSubscribeFrame();
        $first = $this->worker->agents->getAgent(self::AGENT_ID);
        self::assertInstanceOf(ProductAgent::class, $first);

        $this->worker->handleDaemonMessage(new DaemonAgentMessageDTO(self::AGENT_ID, new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::PAGE_UNSUBSCRIBE),
            new SignalName(PageConstants::PRODUCT),
            new WebSocketPageUnsubscribeSignalDTO(self::ACCEPT_KEY),
        )));
        self::assertNull(Hilos::$sr->pageSubscription(self::ACCEPT_KEY));
        $this->worker->idleAndTick(self::AGENT_ID);

        self::assertFalse($this->worker->agents->hasAgent(self::AGENT_ID));
        self::assertSame([self::AGENT_ID], $this->worker->daemonClientDouble->stoppedAgentIds());
        $this->master->removeAgent(self::AGENT_ID); // The worker's agent_stopped report clears the master record.

        $this->sendSubscribeFrame();
        $second = $this->worker->agents->getAgent(self::AGENT_ID);
        self::assertInstanceOf(ProductAgent::class, $second);
        self::assertNotSame($first, $second);
        self::assertSame([self::AGENT_ID, self::AGENT_ID], $this->server->startedAgentIds);
    }

    /** Puts the visitor's subscription to the address into the worker's mirror, served by the agent of the address. */
    private function subscribeInWorkerMirror(): void
    {
        Hilos::$sr->subscribeToPage(PageConstants::PRODUCT, $this->subscribeData());
        Hilos::$sr->bindPageAgent(self::ACCEPT_KEY, PageConstants::PRODUCT, AgentType::PRODUCT, self::ADDRESS);
    }

    /** Hands the master's addressed-frame door the visitor's subscription to the product page of the address. */
    private function sendSubscribeFrame(): void
    {
        try {
            $this->server->sendSignalToAgent(
                AgentType::PRODUCT,
                self::ADDRESS,
                new DaemonAgentMessageDTO(self::AGENT_ID, new SignalDTO(
                    new SignalSource(SignalSource::WEBSOCKET),
                    new SignalType(SignalTypeConstants::PAGE_SUBSCRIBE),
                    new SignalName(PageConstants::PRODUCT),
                    $this->subscribeData(),
                )),
            );
        } catch (WorkerClientNotFoundException) {
            // The frame has already entered the start path. This test has no socket on which to
            // deliver its body after the worker reports ready.
        }
    }

    /**
     * @return WebSocketPageSubscribeSignalDTO The visitor's subscription to the product page of the address
     */
    private function subscribeData(): WebSocketPageSubscribeSignalDTO
    {
        return new WebSocketPageSubscribeSignalDTO(
            self::ACCEPT_KEY,
            PageConstants::PRODUCT,
            [ProductPageSubscribeParams::SLUG => self::ADDRESS],
        );
    }
}

/**
 * Worker-side harness: the shop worker's router, agent manager and page router, and the real idle tick.
 *
 * The shop's {@see EcommerceShopWorkerManager} is final; this one builds what it builds and adds
 * the in-process link and the backdated silence.
 */
final class ProductAgentLifecycleWorkerManager extends WorkerManager
{
    public EcommerceShopAgentManager $agents;

    public ProductAgentLifecycleDaemonClient $daemonClientDouble;

    /** Builds a worker without a transport socket while retaining its normal lifecycle. */
    public function __construct()
    {
        parent::__construct(1);
        $this->daemonClientDouble = new ProductAgentLifecycleDaemonClient();
        $this->daemonClient = $this->daemonClientDouble;
    }

    /**
     * @param string $agentId Agent whose silence is backdated past the window the shop declares for it
     * @throws LogicException When the worker has no idle tracker or the agent declares no window
     */
    public function idleAndTick(string $agentId): void
    {
        $tracker = new ReflectionProperty(WorkerManager::class, 'agentIdleTracker')->getValue($this);
        if (!$tracker instanceof AgentIdleTracker) {
            throw new LogicException('Worker has no idle tracker');
        }
        $window = AgentRegistry::idleTimeout(Hilos::AGENTS[AgentType::PRODUCT])
            ?? throw new LogicException('The product agent declares no idle window');
        $tracker->noteAddressed($agentId, microtime(true) - ($window + 1));
        $tick = Closure::bind(static function (WorkerManager $manager): void {
            $manager->tickAgents();
        }, null, WorkerManager::class);
        $tick($this);
    }

    /**
     * @return SignalRouter The shop's signal router
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new EcommerceShopSignalRouter();
    }

    /**
     * @return AgentManager The shop's agent manager, kept for the cases to read
     */
    protected function createAgentManager(): AgentManager
    {
        $this->agents = new EcommerceShopAgentManager();

        return $this->agents;
    }

    /**
     * Builds the page router the shop worker builds.
     *
     * @param AgentInterface $agent Agent that serves the pages
     * @return PageSignalRouter Page router over the shop's page topology
     * @throws PageSignalRouterNotFoundException When the agent serves no pages
     */
    protected function createPageSignalRouter(AgentInterface $agent): PageSignalRouter
    {
        if (!$agent instanceof PageAgentInterface) {
            throw new PageSignalRouterNotFoundException($agent::class);
        }

        return new PageSignalRouter(
            new HilosPageFactory($agent, Hilos::class),
            new ActionRouteConfig(Hilos::getPageActionRoutes()),
            new SignalRouteConfig(Hilos::getPageSignalRoutes()),
            $agent::THROTTLE_VERDICT_SIGNAL,
        );
    }
}

/** A connected, in-process link that accepts the worker's lifecycle reports. */
final class ProductAgentLifecycleDaemonClient extends WorkerDaemonClient
{
    /** @var list<WorkerDTO|array<string, mixed>> Worker lifecycle reports */
    public array $sent = [];

    /** The test link has no operating-system socket. */
    public function __construct()
    {
    }

    /**
     * @return bool True while this in-process link receives lifecycle reports
     */
    public function isConnected(): bool
    {
        return true;
    }

    /**
     * @param WorkerDTO|array<string, mixed> $data Worker lifecycle report
     */
    public function send(WorkerDTO|array $data): void
    {
        $this->sent[] = $data;
    }

    /**
     * @return list<string> Agent ids reported stopped to the master
     */
    public function stoppedAgentIds(): array
    {
        $stopped = [];
        foreach ($this->sent as $message) {
            if (is_array($message) && ($message[WorkerDTO::TYPE] ?? null) === WorkerConstants::MESSAGE_AGENT_STOPPED) {
                $stopped[] = (string)$message[AgentConstants::FIELD_AGENT_ID];
            }
        }

        return $stopped;
    }
}

/** Master-side addressed-frame door bridged to the worker manager for this scenario. */
final class ProductAgentLifecycleWorkerServer extends WorkerServer
{
    /** @var list<string> Agent ids started by addressed frames */
    public array $startedAgentIds = [];

    public ProductAgentLifecycleWorkerManager $worker;

    public EcommerceShopAgentManagerDaemon $daemonManager;

    /**
     * @param string $agentType Addressed agent type
     * @param ?string $agentIndex Addressed page address
     * @param ?AgentStartSink $immediateStartSink Synchronous reports for this call only; deferred reports use the registered sink
     */
    protected function startAgent(string $agentType, ?string $agentIndex = null, ?AgentStartSink $immediateStartSink = null): void
    {
        $agentId = $this->buildAgentId($agentType, $agentIndex);
        $this->startedAgentIds[] = $agentId;
        $this->worker->handleDaemonMessage(new AgentStartDTO($agentId));
        $this->daemonManager->addAgent(
            $agentId,
            $this->daemonManager->instantiateAgentDaemon($agentType, $agentIndex),
            0,
            false,
        );
    }

    /** The server loop is not started; an addressed frame calls the delivery door directly. */
    protected function onStart(): void
    {
    }
}
