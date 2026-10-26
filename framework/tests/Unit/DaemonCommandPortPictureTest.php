<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\DaemonSection\DaemonNodeStanding;
use Hilos\DaemonSection\DTO\DaemonMasterStandingSignalData;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Server\HttpServer;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** The standing sampler reads registered listeners without opening sockets or starting processes. */
final class DaemonCommandPortPictureTest extends TestCase
{
    private ?ClusterContext $previousCluster;
    private ?RtContext $previousRt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousCluster = Hilos::$cluster;
        $this->previousRt = Hilos::$rt;
        Hilos::$cluster = null;
        Hilos::$rt = null;
    }

    protected function tearDown(): void
    {
        Hilos::$cluster = $this->previousCluster;
        Hilos::$rt = $this->previousRt;
        parent::tearDown();
    }

    public function testMissingCommandListenerStaysNullEvenWithHttp(): void
    {
        $manager = new ReflectionClass(CommandPortPictureManager::class)->newInstanceWithoutConstructor();
        self::assertNull($this->sample($manager)->commandPort);
        $manager->registerServer(new HttpServer('127.0.0.1', 8080));
        self::assertNull($this->sample($manager)->commandPort);
    }

    public function testCommandPortComesFromTheRegisteredServer(): void
    {
        foreach ([0, 9013, 65535] as $port) {
            $manager = new ReflectionClass(CommandPortPictureManager::class)->newInstanceWithoutConstructor();
            $manager->registerServer(new HttpServer('127.0.0.1', 8080));
            $command = new CommandServer('127.0.0.1', $port);
            $manager->registerServer($command);
            self::assertSame($port, $command->getPort());
            self::assertSame($command->getPort(), $this->sample($manager)->commandPort);
        }
    }

    public function testPortZeroAndNullTransitionsPublishBeforeTheMinuteRepair(): void
    {
        $manager = new ReflectionClass(CommandPortPictureManager::class)->newInstanceWithoutConstructor();
        $agents = $this->createStub(AgentManagerDaemon::class);
        $agents->method('isAgentStarted')->willReturn(true);
        new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->setValue($manager, $agents);
        $tick = new ReflectionMethod(DaemonManager::class, 'tickStanding');
        $tick->invoke($manager, 100.0);
        self::assertCount(1, $manager->standingFrames);
        self::assertNull($manager->standingFrames[0]->standing->commandPort);

        $manager->registerServer(new CommandServer('127.0.0.1', 0));
        $tick->invoke($manager, 101.0);
        self::assertCount(2, $manager->standingFrames);
        self::assertSame(0, $manager->standingFrames[1]->standing->commandPort);
        $tick->invoke($manager, 102.0);
        self::assertCount(2, $manager->standingFrames);

        new ReflectionProperty(DaemonManager::class, 'servers')->setValue($manager, []);
        $tick->invoke($manager, 103.0);
        self::assertCount(3, $manager->standingFrames);
        self::assertNull($manager->standingFrames[2]->standing->commandPort);
    }

    /** @return DaemonNodeStanding Real private master sampler result */
    private function sample(DaemonManager $manager): DaemonNodeStanding
    {
        return new ReflectionMethod(DaemonManager::class, 'sampleStanding')->invoke($manager);
    }
}

final class CommandPortPictureManager extends DaemonManager
{
    /** @var list<DaemonMasterStandingSignalData> Frames addressed to the local node */
    public array $standingFrames = [];

    public function sendToAgent(string $agentType, ?string $agentIndex, string $signalName, SignalDataInterface $data): void
    {
        if (!$data instanceof DaemonMasterStandingSignalData) {
            throw new LogicException('The standing test expects only standing frames');
        }
        $this->standingFrames[] = $data;
    }

    protected function createSignalRouter(): SignalRouter
    {
        throw new LogicException('The command port test never boots a daemon');
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        throw new LogicException('The command port test never starts agents');
    }
}
