<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use ReflectionMethod;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisteredDTO;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Socket;

/** Registration owns one acceptance deadline; native readiness drives it without a server tick. */
final class WorkerClientRegistrationEventLoopTest extends TestCase
{
    private EventLoop $loop;

    private WorkerClient $client;

    private RegistrationTestWorkerServer $server;

    private Socket $peer;

    private ?EnvAccessor $previousEnv;

    private ?SignalRouter $previousRouter;

    public function testLateAttachExpiresFromAcceptanceWithoutAnyTraffic(): void
    {
        $this->acceptedAt(hrtime(true) / 1e9 - 11.0);
        $this->bind();
        $this->pump();

        $this->assertTrue($this->client->shouldClose());
        $this->assertNull($this->client->getSocket());
        $this->assertFalse($this->loop->hasReadyWork());
        $this->assertNull($this->timer());
    }

    public function testPartialInputAndUnrelatedIoDoNotMoveTheDeadline(): void
    {
        $this->bind();
        $deadline = $this->timer()->getDeadline();
        $this->pump();
        socket_write($this->peer, '{');
        $this->pump();
        $this->loop->createReadyWork(static function (): void {})->markReady();
        $this->pump();

        $this->assertFalse($this->client->isRegistered());
        $this->assertFalse($this->client->shouldClose());
        $this->assertSame($deadline, $this->timer()->getDeadline());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    public function testRegistrationCancelsAnAlreadyActivatedExpiryAndAnnouncesOnce(): void
    {
        $this->acceptedAt(hrtime(true) / 1e9 - 11.0);
        $this->server->attachEventLoop($this->loop);
        $activated = false;
        $timer = null;
        $this->loop->createReadyWork(static function () use (&$activated, &$timer): void {
            $activated = $timer->getDeadline() === null;
        })->markReady();
        $this->bind();
        $timer = $this->timer();
        $this->register();
        $this->loop->tick();
        $this->assertTrue($activated, 'native expiry activated before registration canceled its queued owner');
        $this->assertNull($timer->getDeadline());
        $this->pump();

        $this->assertTrue($this->client->isRegistered());
        $this->assertFalse($this->client->shouldClose());
        $this->assertSame(new WorkerRegisteredDTO(1, false)->toJson() . "\n", socket_read($this->peer, 4096));
        $this->assertSame(1, $this->server->bootstrapCount);
        $this->assertSame(SignalConstants::WORKERS_READY, Hilos::$sr->getNextQueuedSignal()?->signalName->getName());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());

        $this->register();
        $this->pump();
        $this->assertSame(1, $this->server->bootstrapCount);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    public function testDetachCancelsQueuedExpiryAndRebindKeepsTheAcceptanceMoment(): void
    {
        $this->acceptedAt(hrtime(true) / 1e9 - 11.0);
        $this->loop->createReadyWork(function (): void {
            $this->client->detachEventLoop();
        })->markReady();
        $this->bind();
        $this->pump();
        $this->assertFalse($this->client->shouldClose());
        $this->assertNull($this->timer());
        $this->assertSame(0, $this->loop->registeredCount());

        $this->bind();
        $this->pump();
        $this->assertTrue($this->client->shouldClose());
        $this->assertNull($this->client->getSocket());
    }

    public function testFutureRebindAndRegisteredRebindHaveNoExtraWindow(): void
    {
        $this->bind();
        $deadline = $this->timer()->getDeadline();
        $this->client->detachEventLoop();
        $this->bind();
        $this->assertSame($deadline, $this->timer()->getDeadline());
        $this->register();
        $this->pump();
        $this->client->detachEventLoop();
        $this->bind();
        $this->pump();
        $this->assertNull($this->timer());
        $this->assertFalse($this->client->shouldClose());
    }

    public function testFailedReadyAnnouncementRetriesOnlyAtItsOwnDeadline(): void
    {
        $router = new RegistrationTestRouter();
        Hilos::$sr = $router;
        $this->server->attachEventLoop($this->loop);
        $this->bind();
        $this->register();
        $this->pump();
        $this->assertSame(1, $router->attempts);
        $this->assertSame(0, $this->server->bootstrapCount);
        $this->assertNull($router->getNextQueuedSignal());
        $this->assertFalse($this->loop->hasReadyWork());

        $this->pump();
        $this->assertSame(1, $router->attempts);
        $retry = new ReflectionProperty(WorkerServer::class, 'registrationRetry')->getValue($this->server);
        $retry->armAt(hrtime(true) / 1e9 - 1.0);
        $this->pump();
        $this->assertSame(2, $router->attempts);
        $this->assertSame(1, $this->server->bootstrapCount);
        $this->assertSame(SignalConstants::WORKERS_READY, $router->getNextQueuedSignal()?->signalName->getName());
        $this->assertNull($router->getNextQueuedSignal());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    public function testShutdownCancelsAnActivatedInitialReadinessFact(): void
    {
        $this->server->attachEventLoop($this->loop);
        $this->bind();
        $this->register();
        $this->loop->tick();
        $this->server->prepareShutdown();
        $this->pump();
        $this->assertSame(0, $this->server->bootstrapCount);
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertFalse($this->loop->hasReadyWork());
    }

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new SignalRouter();
        $this->loop = new EventLoop();
        $this->server = new ReflectionClass(RegistrationTestWorkerServer::class)->newInstanceWithoutConstructor();
        $manager = new RegistrationTestAgentManager();
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($this->server, $manager);
        new ReflectionProperty(WorkerServer::class, 'minRegular')->setValue($this->server, 1);
        new ReflectionProperty(WorkerServer::class, 'maxRegular')->setValue($this->server, 1);
        new ReflectionProperty(WorkerServer::class, 'minMonopolistic')->setValue($this->server, 0);
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $this->peer = $pair[1];
        $this->client = new WorkerClient($pair[0], $manager, $this->server);
        new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($this->server, $this->client);
    }

    protected function tearDown(): void
    {
        $this->server->detachEventLoop();
        $this->client->close();
        $this->loop->close();
        socket_close($this->peer);
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        parent::tearDown();
    }

    private function bind(): void
    {
        $this->client->attachEventLoop($this->loop, function (): void {
            $this->client->read();
            $this->client->onTick();
            $this->client->write();
            if ($this->client->shouldClose()) {
                $this->server->dropClient($this->client);
            }
        });
    }

    private function register(): void
    {
        $json = new WorkerRegisterDTO(1, false)->toJson() . "\n";
        $this->assertSame(strlen($json), socket_write($this->peer, $json));
    }

    private function pump(): void
    {
        for ($portion = 0; $portion < 8; ++$portion) {
            $this->loop->tick();
        }
    }

    /** @param float $at Original monotonic acceptance moment, brought to the past without waiting */
    private function acceptedAt(float $at): void
    {
        new ReflectionProperty(WorkerClient::class, 'connectTime')->setValue($this->client, $at);
    }

    /** @return ?EventLoopTimer Client-owned native deadline */
    private function timer(): ?EventLoopTimer
    {
        return new ReflectionProperty(WorkerClient::class, 'registrationTimer')->getValue($this->client);
    }
}

/** No process is started; the real registration gate owns the one bootstrap notification. */
final class RegistrationTestWorkerServer extends WorkerServer
{
    public int $bootstrapCount = 0;

    /** @param bool $isMonopolistic Pool duty is outside this registration-only fixture */
    protected function startWorker(bool $isMonopolistic): void
    {
    }

    protected function onStart(): void
    {
    }

    protected function onInitialWorkersReady(): void
    {
        if (new ReflectionProperty(SignalRouter::class, 'queuedSignals')->getValue(Hilos::$sr)->isEmpty()) {
            throw new LogicException('bootstrap ran before the announcement was queued');
        }
        ++$this->bootstrapCount;
    }
}

/** Receive-only roster; no worker process or agent factory is needed. */
final class RegistrationTestAgentManager extends AgentManagerDaemon
{
    /**
     * @param string $agentType Unexpected agent type
     * @param ?string $agentIndex Unexpected instance
     * @return AgentDaemonInterface Never returned
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('registration tests start no agents');
    }
}

/** One real announcement refusal, followed by normal queueing. */
final class RegistrationTestRouter extends SignalRouter
{
    public int $attempts = 0;

    /**
     * @param SignalSourceInterface $signalSource Original source
     * @param SignalTypeInterface $signalType Original type
     * @param SignalNameInterface $signalName Original name
     * @param SignalDataInterface $signalData Original payload
     */
    public function queueSignal(
        SignalSourceInterface $signalSource,
        SignalTypeInterface $signalType,
        SignalNameInterface $signalName,
        SignalDataInterface $signalData,
    ): void {
        if (++$this->attempts === 1) {
            throw new InvalidArgumentException('test announcement refusal');
        }
        parent::queueSignal($signalSource, $signalType, $signalName, $signalData);
    }
}
