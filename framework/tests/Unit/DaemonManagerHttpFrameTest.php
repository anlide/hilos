<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\API\Router\HttpRouter;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\DaemonSection\DTO\DaemonMasterHttpSignalData;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Server\HttpServer;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** The master samples route counts on change, on hour rollover, and for minute repair. */
final class DaemonManagerHttpFrameTest extends TestCase
{
    private ?RtContext $previousRt;
    private ?ClusterContext $previousCluster;

    /** @throws EnvException When the router's test env cannot be loaded */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRt = Hilos::$rt;
        $this->previousCluster = Hilos::$cluster;
        Hilos::$rt = null;
        Hilos::$cluster = null;
        if (Hilos::$env === null) {
            Hilos::initEnv(dirname(__DIR__, 2));
        }
    }

    protected function tearDown(): void
    {
        Hilos::$rt = $this->previousRt;
        Hilos::$cluster = $this->previousCluster;
        parent::tearDown();
    }

    public function testFirstFrameChangedAnswerAndMinuteRepair(): void
    {
        $manager = new HttpFrameTestDaemonManager();
        $router = new HttpRouter();
        $router->addRoute(HttpConstants::METHOD_POST, '/a', static fn (): array => []);
        $router->addRoute(HttpConstants::METHOD_GET, '/a', static fn (): array => []);
        $router->addAgentRoute(HttpConstants::METHOD_GET, '/z', 'fixture_agent');
        $manager->attachHttp($router);
        $start = time();

        $manager->tickHttpAt((float)$start);
        self::assertCount(1, $manager->frames);
        self::assertSame('standalone', $manager->frames[0]->nodeId);
        self::assertSame('127.0.0.1', $manager->frames[0]->http->host);
        self::assertSame(8080, $manager->frames[0]->http->port);
        self::assertSame($router->traffic()->countingSince(), $manager->frames[0]->http->countingSince);
        self::assertSame(['GET /a', 'POST /a', 'GET /z'], array_map(
            static fn ($route): string => "{$route->method} {$route->path}",
            $manager->frames[0]->http->routes,
        ));
        self::assertSame('fixture_agent', $manager->frames[0]->http->routes[2]->agentType);
        self::assertSame(0, $manager->frames[0]->http->routes[0]->tally->requests);

        $router->traffic()->record(HttpConstants::METHOD_GET, '/a', 503, 1001, $start);
        $manager->tickHttpAt($start + 0.5);
        self::assertCount(1, $manager->frames);
        $manager->tickHttpAt($start + 1.0);
        self::assertCount(2, $manager->frames);
        self::assertSame(1, $manager->frames[1]->http->routes[0]->tally->serverErrors);
        self::assertSame(1, $manager->frames[1]->http->routes[0]->tally->slow);

        $manager->tickHttpAt($start + 59.0);
        self::assertCount(2, $manager->frames);
        $manager->tickHttpAt($start + 61.0);
        self::assertCount(3, $manager->frames);
    }

    public function testHourRolloverRemovesExpiredCountsWithoutAnotherAnswer(): void
    {
        $manager = new HttpFrameTestDaemonManager();
        $router = new HttpRouter();
        $router->addRoute(HttpConstants::METHOD_GET, '/a', static fn (): array => []);
        $manager->attachHttp($router);
        $start = intdiv(time(), TimeConstants::SECONDS_PER_HOUR) * TimeConstants::SECONDS_PER_HOUR;
        $router->traffic()->record(HttpConstants::METHOD_GET, '/a', 200, 4, $start);

        $manager->tickHttpAt((float)$start);
        self::assertSame(1, $manager->frames[0]->http->routes[0]->tally->requests);
        $manager->tickHttpAt((float)($start + 24 * TimeConstants::SECONDS_PER_HOUR));
        self::assertCount(2, $manager->frames);
        self::assertSame(0, $manager->frames[1]->http->routes[0]->tally->requests);
        self::assertNull($manager->frames[1]->http->routes[0]->tally->slowestMs);
    }

    public function testFreezeAgentPresenceAndMissingHttpServer(): void
    {
        $manager = new HttpFrameTestDaemonManager();
        $router = new HttpRouter();
        $manager->attachHttp($router);
        $start = time();
        $manager->agents()->started = false;
        $manager->tickHttpAt((float)$start);
        self::assertSame([], $manager->frames);

        $manager->agents()->started = true;
        $freeze = new HttpFrameTestRtContext();
        $freeze->hilosProtectedModeRuntime->phase = StateProtectedModeRuntime::PHASE_ACTIVE;
        Hilos::$rt = $freeze;
        $manager->tickHttpAt($start + 1.0);
        self::assertSame([], $manager->frames);

        $freeze->hilosProtectedModeRuntime->phase = StateProtectedModeRuntime::PHASE_INACTIVE;
        $manager->tickHttpAt($start + 2.0);
        self::assertCount(1, $manager->frames);
        $manager->onAgentStarted(HilosAgentType::HILOS_DAEMON_NODE);
        $manager->tickHttpAt($start + 2.5);
        self::assertCount(2, $manager->frames);

        $withoutServer = new HttpFrameTestDaemonManager();
        $withoutServer->setRouter($router);
        $withoutServer->tickHttpAt((float)$start);
        self::assertSame([], $withoutServer->frames);
    }

    public function testFailedDeliveryWaitsOneMinuteBeforeRetry(): void
    {
        $manager = new HttpFrameTestDaemonManager();
        $router = new HttpRouter();
        $manager->attachHttp($router);
        $manager->refuse = true;
        $start = time();
        $manager->tickHttpAt((float)$start);
        self::assertSame(1, $manager->attempts);
        $router->traffic()->recordUnrouted(404, 0, $start);
        $manager->refuse = false;
        $manager->tickHttpAt($start + 1.0);
        self::assertSame(1, $manager->attempts);
        $manager->tickHttpAt($start + 59.0);
        self::assertSame(1, $manager->attempts);
        $manager->tickHttpAt($start + 60.0);
        self::assertSame(2, $manager->attempts);
        self::assertCount(1, $manager->frames);
    }

    public function testAgentRestartForcesAFrameAfterARefusal(): void
    {
        $manager = new HttpFrameTestDaemonManager();
        $manager->attachHttp(new HttpRouter());
        $manager->refuse = true;
        $start = time();
        $manager->tickHttpAt((float)$start);
        self::assertSame(1, $manager->attempts);

        $manager->refuse = false;
        $manager->onAgentStarted(HilosAgentType::HILOS_DAEMON_NODE);
        $manager->tickHttpAt($start + 0.5);
        self::assertSame(2, $manager->attempts);
        self::assertCount(1, $manager->frames);
    }
}

final class HttpFrameTestDaemonManager extends DaemonManager
{
    /** @var list<DaemonMasterHttpSignalData> Captured master frames */
    public array $frames = [];
    public int $attempts = 0;
    public bool $refuse = false;

    /** @param HttpRouter $router Router whose counts are sampled */
    public function attachHttp(HttpRouter $router): void
    {
        $this->setRouter($router);
        $this->registerServer(new HttpServer('127.0.0.1', 8080));
    }

    /** @param HttpRouter $router Router to assign without a server */
    public function setRouter(HttpRouter $router): void
    {
        $this->httpRouter = $router;
    }

    /** @return HttpFrameTestAgentManager Mutable receiver presence */
    public function agents(): HttpFrameTestAgentManager
    {
        return $this->agentManagerDaemon;
    }

    /** @param float $now Controlled loop time */
    public function tickHttpAt(float $now): void
    {
        new ReflectionMethod(DaemonManager::class, 'tickHttpFrame')->invoke($this, $now);
    }

    /**
     * @param string $agentType Receiver type
     * @param ?string $agentIndex Receiver index
     * @param string $signalName Signal name
     * @param SignalDataInterface $data HTTP frame
     * @throws LogicException When this test refuses delivery
     */
    public function sendToAgent(string $agentType, ?string $agentIndex, string $signalName, SignalDataInterface $data): void
    {
        TestCase::assertSame(HilosAgentType::HILOS_DAEMON_NODE, $agentType);
        TestCase::assertNull($agentIndex);
        TestCase::assertSame(HilosSignalConstants::DAEMON_MASTER_HTTP, $signalName);
        TestCase::assertInstanceOf(DaemonMasterHttpSignalData::class, $data);
        $this->attempts++;
        if ($this->refuse) {
            throw new LogicException('Delivery refused by test');
        }
        $this->frames[] = $data;
    }

    /** @return SignalRouter Empty route table for directly addressed frames */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Manager with mutable node-agent presence */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new HttpFrameTestAgentManager();
    }
}

final class HttpFrameTestAgentManager extends AgentManagerDaemon
{
    public bool $started = true;

    /**
     * @param string $agentId Receiver id
     * @return bool Whether the node agent is up
     */
    public function isAgentStarted(string $agentId): bool
    {
        return $this->started && $agentId === HilosAgentType::HILOS_DAEMON_NODE;
    }

    /**
     * @param string $agentType Unused agent type
     * @param ?string $agentIndex Unused instance index
     * @return AgentDaemonInterface Never returned
     * @throws LogicException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('The HTTP frame test starts no agents');
    }
}

final class HttpFrameTestRtContext extends RtContext
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
