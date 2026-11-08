<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Error;
use EventBufferEvent;
use Generator;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\PeerAddress;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerMarkers;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Tls\ClusterTlsConfig;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonDeparture;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\ReadyWork;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Client\HttpClient;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\HttpServer;
use Hilos\Socket\Server\ServerInterface;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Socket\Transport\TlsSocketTransport;
use Hilos\Tests\Unit\Socket\MutualTlsFixture;
use Hilos\Tests\Unit\Socket\TlsTestExchange;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Socket;
use Throwable;

/** Real native owners leave before their base, even with retained PHP aliases and a failed neighbor. */
final class DaemonManagerNativeRetirementTest extends TestCase
{
    private NativeRetirementManager $manager;
    private EventLoop $loop;
    private MutualTlsFixture $fixture;
    /** @var list<TlsTestExchange> Exchanges whose remote end remains alive through master retirement */
    private array $exchanges = [];
    /** @var list<Socket> Bare opposite ends, closed after the master-owned ends */
    private array $peers = [];
    /** @var list<ClientInterface> Retained client aliases */
    private array $clients = [];
    /** @var list<AbstractServer> Servers with real listener sockets */
    private array $servers = [];
    /** @var list<Socket> Exact client and listener descriptor aliases */
    private array $ownedSockets = [];
    /** @var list<Closure> Escaped callbacks from replaced and current client bindings */
    private array $escaped = [];
    /** @var list<EventBufferEvent> Native aliases retained through client and loop cleanup */
    private array $nativeAliases = [];
    /** @var list<string> Actual client close hooks in observation order */
    private array $closeOrder = [];
    /** @var array<string, mixed> Exact facade values restored after each case */
    private array $previous = [];
    private string|false $enabled;
    private string $log;

    /** @return iterable<string, array{bool, bool}> Completed shutdown and expired original basis, with and without browsers */
    public static function departures(): iterable
    {
        yield 'normal' => [false, true];
        yield 'ceiling' => [true, true];
        yield 'browserless normal' => [false, false];
        yield 'browserless ceiling' => [true, false];
    }

    /**
     * The real run() exit must dispose sockets, not merely remove event bindings.
     * The original shutdown basis is prepared explicitly; no production expiry is awaited.
     *
     * @param bool $ceiling Whether unread client output holds readiness past the original ceiling
     * @param bool $browsers Whether this node owns browser clients
     */
    #[DataProvider('departures')]
    public function testRunRetiresAllCurrentSlots(bool $ceiling, bool $browsers): void
    {
        $this->manager->bootstrap = function () use ($ceiling, $browsers): void {
            $this->loop = $this->manager->loop();
            $this->populate($browsers);
            $this->manager->handleShutdown();
            new ReflectionProperty(DaemonManager::class, 'shutdownStartTime')->setValue(
                $this->manager, microtime(true) - ($ceiling ? 21.0 : 0.0),
            );
            if (!$ceiling) {
                foreach ($this->servers as $server) {
                    if (!$server instanceof PeerServer) {
                        foreach ($server->getClients() as $client) {
                            $server->dropClient($client);
                        }
                    }
                }
            }
            $this->assertFalse(new ReflectionMethod(DaemonManager::class, 'shouldContinueRunning')->invoke($this->manager));
        };
        $this->assertSame(DaemonDeparture::Stopped, $this->manager->run());
        $this->assertRetired();
        $this->assertSame($browsers ? ['worker', 'browser'] : ['worker'], $this->closeOrder);
        $this->manager->retire();
        $this->assertRetired();
    }

    public function testThrowingCloseStillRetiresNeighborsAndDiscardsItsNewEffect(): void
    {
        $server = $this->server(new HttpServer('127.0.0.1', 0));
        $failure = new RuntimeException('close neighbor failed');
        $effect = null;
        $closed = 0;
        $client = new NativeRetirementHttpClient($this->pair(), function () use (&$effect, &$closed, $failure): void {
            ++$closed;
            new ReflectionMethod(DaemonManager::class, 'queueSignalEffect')->invoke($this->manager,
                static function (): Generator {
                    for ($step = 0; $step < 1000; ++$step) {
                        yield;
                    }
                });
            $state = new ReflectionProperty(DaemonManager::class, 'signalDispatchState')->getValue($this->manager);
            $effect = $state->effects[key($state->effects)];
            try {
                $this->manager->arm();
                $this->fail('a terminal callback rearmed the role');
            } catch (EventLoopException) {
                // Retirement has already closed this role lifetime.
            }
            throw $failure;
        });
        $this->slot($server, $client);
        $this->populate(true);
        $caught = null;
        try {
            $this->manager->retire();
        } catch (Throwable $actual) {
            $caught = $actual;
        }
        $this->assertSame($failure, $caught);
        $this->assertSame(1, $closed);
        $this->assertNotNull($effect);
        $this->assertFalse($effect->hasWork(), 'the effect created after client close must be canceled too');
        $this->assertNull(new ReflectionProperty(DaemonManager::class, 'signalDispatchState')->getValue($this->manager));
        $this->assertRetired();
        $this->manager->retire();
        $this->assertSame(1, $closed, 'a failed onClose is never replayed');
        $this->manager->lateAccept($server);
        $late = new HttpClient($this->pair());
        $this->slot($server, $late);
        $this->assertNull($late->getSocket(), 'a terminal callback cannot revive a client slot');
        $this->assertFalse($this->loop->hasReadyWork());
    }

    public function testFailedDetachKeepsItsDescriptorOpenWhileOtherClientsLeave(): void
    {
        $server = $this->server(new HttpServer('127.0.0.1', 0));
        $client = new NativeRetirementHttpClient($this->pair());
        $this->slot($server, $client);
        $socket = $client->getSocket();
        $client->refuseDetach = true;
        $this->slot($server, new HttpClient($this->pair()));
        try {
            $this->manager->retire();
            $this->fail('a failed retirement must be reported');
        } catch (RuntimeException $failure) {
            $this->assertSame('detach refused', $failure->getMessage());
        }
        $this->assertSame($socket, $client->getSocket());
        $this->assertTrue(socket_getsockname($socket, $address));
        $this->assertSame([$client], array_values($server->getClients()));
        $this->assertSame(0, $this->loop->registeredCount());
        $client->refuseDetach = false;
        $server->dropClient($client);
    }

    public function testThrowingOwnerDetachDoesNotSkipIndependentListenersOrClients(): void
    {
        $this->populate(true);
        $workers = $this->servers[0];
        $this->assertInstanceOf(NativeRetirementWorkers::class, $workers);
        $failure = new RuntimeException('worker owner detach failed');
        $workers->detachFailure = $failure;
        $caught = null;
        try {
            $this->manager->retire();
        } catch (Throwable $actual) {
            $caught = $actual;
        }
        $this->assertSame($failure, $caught);
        $this->assertRetired();
        $workers->detachFailure = null;
    }

    public function testTerminalCeilingCancelsPendingRawDialBeforeClosingItsSocket(): void
    {
        $listener = $this->fixture->listen();
        socket_getsockname($listener, $host, $port);
        $server = $this->peerServer();
        new ReflectionProperty(PeerServer::class, 'seeds')->setValue($server,
            [new PeerAddress($host, $port)]);
        $this->manager->registerServer($server);
        $this->servers[] = $server;
        $server->attachEventLoop($this->loop);
        $this->loop->tick();
        $dial = new ReflectionProperty(PeerServer::class, 'seedDials')->getValue($server)[0];
        $socket = $dial->socket;
        $this->assertNotNull($socket);
        $this->assertTrue($dial->connecting);
        $this->assertGreaterThan(0, $this->loop->registeredCount());
        $this->manager->retire();
        $this->assertNull($dial->socket);
        $this->assertNull($dial->ready);
        $this->assertNull($dial->timer);
        $this->assertSame(0, $this->loop->registeredCount());
        $this->assertClosed($socket);
        $this->loop->tick();
        $this->assertSame([], $server->getClients());
    }

    protected function setUp(): void
    {
        $this->previous = ['env' => Hilos::$env, 'sr' => Hilos::$sr, 'rt' => Hilos::$rt, 'cluster' => Hilos::$cluster];
        $this->enabled = getenv('CLUSTER_ENABLED');
        putenv('CLUSTER_ENABLED=false');
        Hilos::$env = new EnvAccessor();
        Hilos::$rt = null;
        Hilos::$cluster = null;
        $this->fixture = new MutualTlsFixture();
        $this->manager = new NativeRetirementManager();
        $this->loop = new EventLoop();
        $this->manager->seedLoop($this->loop);
        $this->log = (string)tempnam(sys_get_temp_dir(), 'hilos-retirement-');
        Logger::setLogFile($this->log);
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            if ($client instanceof NativeRetirementHttpClient) {
                $client->refuseDetach = false;
                $client->closed = null;
            }
            $client->close();
        }
        foreach ($this->servers as $server) {
            if ($server instanceof WorkerServer || $server instanceof PeerServer) {
                $server->detachEventLoop();
            }
            if ($server->getSocket() !== null) {
                $this->loop->unregister($server->getSocket());
                $server->stop();
            }
        }
        foreach ($this->exchanges as $exchange) {
            $exchange->close();
        }
        $this->loop->cleanup();
        foreach ($this->peers as $socket) {
            socket_close($socket);
        }
        $this->fixture->close();
        Logger::resetLogFile();
        unlink($this->log);
        foreach ($this->previous as $field => $value) {
            Hilos::$$field = $value;
        }
        putenv($this->enabled === false ? 'CLUSTER_ENABLED' : 'CLUSTER_ENABLED=' . $this->enabled);
    }

    /** @param bool $browsers Whether to include a real upgraded TLS browser */
    private function populate(bool $browsers): void
    {
        $workers = $this->server(new NativeRetirementWorkers($this->manager->agents()));
        $workers->attachEventLoop($this->loop);
        $worker = new NativeRetirementWorker($this->pair(), $this->manager->agents(), $workers);
        $worker->closed = function (): void { $this->closeOrder[] = 'worker'; };
        $this->slot($workers, $worker);
        $commands = $this->server(new CommandServer('127.0.0.1', 0));
        $this->slot($commands, new CommandClient($this->pair(), $commands));
        $http = $this->server(new HttpServer('127.0.0.1', 0));
        $this->slot($http, new HttpClient($this->pair()));
        $peers = $this->server($this->peerServer());
        foreach ([false, true] as $dialer) {
            $socket = $this->pair();
            $this->slot($peers, new PeerLink($socket, $peers,
                NodeIdentity::of('local', 'Local', NodeRole::Slave, []), $dialer, new PlainSocketTransport($socket)));
        }
        $exchange = $this->tlsExchange();
        $link = new PeerLink($exchange->sockets['accepting'], $peers,
            NodeIdentity::of('local', 'Local', NodeRole::Slave, []), false, $exchange->transports['accepting']);
        $this->slot($peers, $link, false);
        $exchange->bindClient('accepting', $link);
        if (!$browsers) {
            return;
        }
        $exchange = $this->tlsExchange();
        $browserServer = $this->server(new NativeRetirementBrowsers('127.0.0.1', 0));
        $browser = new NativeRetirementBrowser($exchange->sockets['accepting'], $exchange->transports['accepting']);
        $browser->closed = function () use ($worker): void {
            $this->assertNull($worker->getSocket(), 'the worker connection leaves before the browser');
            $this->closeOrder[] = 'browser';
        };
        // Both managers may retain aliases, but this transport remains on its original native base.
        $this->slot($browserServer, $browser, false);
        $exchange->bindClient('accepting', $browser);
        $exchange->transports['dialing']->write("GET / HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . "Sec-WebSocket-Key: MDEyMzQ1Njc4OWFiY2RlZg==\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $exchange->runUntil(fn(): bool => str_contains($exchange->received['dialing'], '101 Switching Protocols'));
        $this->assertSame([], $exchange->failures);
        $this->assertNotSame('', $browser->acceptKey);
    }

    /** @return TlsTestExchange Handshaked real TLS whose native aliases outlive retirement */
    private function tlsExchange(): TlsTestExchange
    {
        $authority = $this->fixture->issueAuthority('Retirement');
        $exchange = new TlsTestExchange($this->fixture, $this->fixture->issueNode('server', $authority),
            $this->fixture->issueNode('caller', $authority), null, $this->fixture->trustFile($authority));
        $this->exchanges[] = $exchange;
        $exchange->handshake();
        $this->assertSame([], $exchange->failures);
        $this->nativeAliases[] = new ReflectionProperty(TlsSocketTransport::class, 'event')->getValue($exchange->transports['accepting']);
        return $exchange;
    }

    /** @return PeerServer Actual listener and dial owner on a browserless slave */
    private function peerServer(): PeerServer
    {
        $authority = $this->fixture->issueAuthority('Peer retirement');
        $server = new PeerServer('127.0.0.1', 0, NodeIdentity::of('local', 'Local', NodeRole::Slave, []), [],
            new ClusterTlsConfig($this->fixture->issueNode('local', $authority), $this->fixture->trustFile($authority)),
            new PeerMarkers([], []));
        $server->prepareTlsContexts();
        return $server;
    }

    /**
     * @template T of AbstractServer
     * @param T $server Listener whose native READ watch belongs to the master
     * @return T Registered running listener
     */
    private function server(AbstractServer $server): AbstractServer
    {
        $this->servers[] = $server;
        $this->manager->registerServer($server);
        $server->start();
        $this->ownedSockets[] = $server->getSocket();
        $this->loop->registerRead($server->getSocket(), static function (): void {});
        return $server;
    }

    /**
     * @param AbstractServer $server Actual client roster owner
     * @param ClientInterface $client Socket/protocol owner
     * @param bool $bind Whether this client uses the master's loop instead of its existing TLS base
     */
    private function slot(AbstractServer $server, ClientInterface $client, bool $bind = true): void
    {
        $this->clients[] = $client;
        $this->ownedSockets[] = $client->getSocket();
        new ReflectionMethod($server, 'addClient')->invoke($server, $client);
        if ($bind) {
            $this->manager->bind($server, $client);
            if ($client->getSocket() === null) {
                return;
            }
            $work = new ReflectionProperty(AbstractClient::class, 'readyWork')->getValue($client);
            $this->escaped[] = new ReflectionProperty(ReadyWork::class, 'callback')->getValue($work);
            $this->manager->bind($server, $client);
            $this->assertTrue($work->isClosed());
        }
    }

    /** @return Socket Nonblocking master end, whose opposite end remains open */
    private function pair(): Socket
    {
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $this->peers[] = $pair[1];
        return $pair[0];
    }

    private function assertRetired(): void
    {
        foreach ($this->ownedSockets as $socket) {
            $this->assertClosed($socket);
        }
        foreach ($this->nativeAliases as $native) {
            $this->assertSame(0, $native->getEnabled(), 'a retained native alias has no live transport watch');
        }
        foreach ($this->clients as $client) {
            $this->assertNull($client->getSocket());
            $transport = new ReflectionProperty(AbstractClient::class, 'transport')->getValue($client);
            if ($transport instanceof TlsSocketTransport) {
                $this->assertNull(new ReflectionProperty(TlsSocketTransport::class, 'event')->getValue($transport));
            }
        }
        foreach ($this->servers as $server) {
            $this->assertSame([], $server->getClients());
            $this->assertNull($server->getSocket());
            $this->assertFalse($server->isRunning());
        }
        $this->assertSame(0, $this->loop->registeredCount());
        $this->assertFalse($this->loop->hasReadyWork());
        foreach ($this->escaped as $callback) {
            $callback();
        }
        $this->loop->tick();
        $this->assertFalse($this->loop->hasReadyWork());
    }

    /** @param Socket $socket Descriptor alias whose owner has closed it */
    private function assertClosed(Socket $socket): void
    {
        try {
            socket_getsockname($socket, $address);
            $this->fail('a retired descriptor is still open');
        } catch (Error $failure) {
            $this->assertStringContainsString('closed', $failure->getMessage());
        }
    }
}

/** Runs the real terminal branch without starting workers or installing process-global handlers. */
final class NativeRetirementManager extends DaemonManager
{
    public ?Closure $bootstrap = null;

    /** @param EventLoop $loop Existing native test base */
    public function seedLoop(EventLoop $loop): void
    {
        $this->eventLoop = $loop;
    }

    /** @return EventLoop Actual base created by run() */
    public function loop(): EventLoop
    {
        return $this->eventLoop;
    }

    /** @return AgentManagerDaemon Real source and reader-interest owner */
    public function agents(): AgentManagerDaemon
    {
        return $this->agentManagerDaemon;
    }

    /**
     * @param ServerInterface $server Actual roster owner
     * @param ClientInterface $client Actual client
     */
    public function bind(ServerInterface $server, ClientInterface $client): void
    {
        $this->registerClientSocket($server, $client);
    }

    public function retire(): void
    {
        new ReflectionMethod(DaemonManager::class, 'retireNativeOwners')->invoke($this);
    }

    public function arm(): void
    {
        $this->armRoleTickAt(hrtime(true) / 1e9 + 60);
    }

    /** @param ServerInterface $server Already closed entrance */
    public function lateAccept(ServerInterface $server): void
    {
        $this->onServerAccept($server, null);
    }

    protected function setupErrorHandling(): void
    {
    }

    protected function setupSignalHandlers(): void
    {
    }

    protected function processEventLoop(): void
    {
        $bootstrap = $this->bootstrap;
        $this->bootstrap = null;
        $bootstrap?->__invoke();
    }

    /** @return SignalRouter Original real FIFO */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Real source facts without a project agent factory */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new NativeRetirementAgents();
    }
}

/** Holds real worker clients and native queues, with no OS worker needed for socket retirement. */
final class NativeRetirementWorkers extends WorkerServer
{
    public ?Throwable $detachFailure = null;

    /** @param AgentManagerDaemon $agents Existing real worker roster */
    public function __construct(AgentManagerDaemon $agents)
    {
        $this->host = '127.0.0.1';
        $this->port = 0;
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($this, $agents);
        foreach (['minRegular', 'minMonopolistic', 'maxRegular'] as $field) {
            new ReflectionProperty(WorkerServer::class, $field)->setValue($this, 0);
        }
    }

    protected function onStart(): void
    {
    }

    public function detachEventLoop(): void
    {
        parent::detachEventLoop();
        if ($this->detachFailure !== null) {
            throw $this->detachFailure;
        }
    }
}

/** Real browser listener; direct accepted clients are installed by the fixture. */
final class NativeRetirementBrowsers extends WebSocketServer
{
    protected function onStart(): void
    {
    }

    /**
     * @param Socket $socket Accepted descriptor
     * @return WebSocketClientInterface Native protocol owner
     */
    protected function onCreateClient($socket): WebSocketClientInterface
    {
        return new NativeRetirementBrowser($socket);
    }
}

/** Retains the actual upgrade, native liveness timer and connection-close publication. */
final class NativeRetirementBrowser extends WebSocketClient
{
    public ?Closure $closed = null;

    protected function onClose(): void
    {
        parent::onClose();
        $this->closed?->__invoke();
    }

    /**
     * @param array<string, string> $headers Upgrade headers
     * @param string $acceptKey New identity
     * @param array<string, string> $cookies Browser cookies
     * @param ?string $clientIp Observed peer
     * @param RequestQueryParams $queryParams Request parameters
     */
    protected function onHandshake(array $headers, string $acceptKey, array $cookies, ?string $clientIp, RequestQueryParams $queryParams): void
    {
    }
}

/** Observes actual worker source cleanup before the browser's close hook. */
final class NativeRetirementWorker extends WorkerClient
{
    public ?Closure $closed = null;

    protected function onClose(): void
    {
        parent::onClose();
        $this->closed?->__invoke();
    }
}

/** A real HTTP transport whose departure may refuse after FD retirement. */
final class NativeRetirementHttpClient extends HttpClient
{
    public bool $refuseDetach = false;

    /**
     * @param Socket $socket Real socket
     * @param ?Closure(): void $closed Additional departure bookkeeping
     */
    public function __construct(Socket $socket, public ?Closure $closed = null)
    {
        parent::__construct($socket);
    }

    public function detachEventLoop(): void
    {
        if ($this->refuseDetach) {
            throw new RuntimeException('detach refused');
        }
        parent::detachEventLoop();
    }

    protected function onClose(): void
    {
        parent::onClose();
        $this->closed?->__invoke();
    }
}

/** No project agent is needed to observe actual transport and source disposal. */
final class NativeRetirementAgents extends AgentManagerDaemon
{
    /**
     * @param string $agentType Unused type
     * @param ?string $agentIndex Unused index
     * @return AgentDaemonInterface Never returned
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('retirement starts no agent');
    }
}
