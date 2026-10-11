<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\Worker;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\DaemonSection\DTO\DaemonAgentRestartRequestSignalData;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\DTO\WorkerAgentRestartRequestDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Tests\Unit\Fixtures\PageAgentRestartTestManager;
use Hilos\Tests\Unit\Fixtures\PageAgentRestartTestWorkerManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/** Typed envelope, registered-worker entrance and unforgeable page-agent return address. */
final class WorkerAgentRestartRequestFrameTest extends TestCase
{
    private ?SignalRouter $previousRouter;

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousRouter;
        parent::tearDown();
    }

    public function testRoundtripAndFactoryKeepTheTypedReturnAddress(): void
    {
        foreach ([null, '2'] as $index) {
            $request = new DaemonAgentRestartRequestSignalData('room:12', 'node-a', 'accept-1', null, 'pages', $index);
            $frame = new WorkerAgentRestartRequestDTO($request);
            $restored = WorkerDTO::factoryWorkerDTO($frame->toJson());
            $this->assertInstanceOf(WorkerAgentRestartRequestDTO::class, $restored);
            $this->assertSame($frame->toArray(), $restored->toArray());
            $this->assertSame('worker_agent_restart_request', $restored->getType());
            $this->assertSame($index, $restored->data->replyAgentIndex);
            $this->assertNull($restored->data->requestId);
        }
    }

    #[DataProvider('malformedFrames')]
    public function testMalformedEnvelopeFailsTheNormalTransportParser(array $frame): void
    {
        $this->expectException(InvalidFormatException::class);
        WorkerDTO::factoryWorkerDTO(json_encode(['type' => WorkerAgentRestartRequestDTO::MESSAGE_TYPE] + $frame, JSON_THROW_ON_ERROR));
    }

    public static function malformedFrames(): array
    {
        return [
            [[]],
            [['payload' => null]],
            [['payload' => 'bad']],
            [['payload' => []]],
            [['payload' => ['agentId' => 'room:12', 'nodeId' => 'node-a', 'acceptKey' => 'accept-1']]],
            [['payload' => ['agentId' => false, 'nodeId' => 'node-a', 'acceptKey' => 'accept-1', 'replyAgentType' => 'pages']]],
            [['payload' => ['agentId' => 'room', 'nodeId' => 'node-a', 'acceptKey' => 'accept-1',
                'replyAgentType' => 'pages', 'requestId' => 7]]],
        ];
    }

    public function testRegisteredWorkerControlReachesTheMastersRegisteredDoor(): void
    {
        $manager = new PageAgentRestartTestManager();
        $client = $this->client($manager);
        $this->receive($client, new WorkerRegisterDTO(3, false));
        $this->receive($client, new WorkerAgentRestartRequestDTO($this->ask()));
        $this->assertCount(1, $manager->requests);
        $this->assertSame('room', $manager->requests[0]->payload['agentType']);
        $this->assertSame('12', $manager->requests[0]->payload['agentIndex']);
    }

    public function testUnregisteredConnectionCannotReachTheRestartDoor(): void
    {
        $manager = new PageAgentRestartTestManager();
        $client = $this->client($manager);
        try {
            $this->receive($client, new WorkerAgentRestartRequestDTO($this->ask()));
            $this->fail('Unregistered restart accepted');
        } catch (InvalidFormatException $failure) {
            $this->assertStringContainsString('registered worker', $failure->getMessage());
        }
        $this->assertSame([], $manager->requests);
    }

    public function testMissingMasterDoorIsAnExplicitWiringFailure(): void
    {
        $manager = new PageAgentRestartTestManager();
        new ReflectionProperty(WorkerServer::class, 'pageAgentRestartDoor')->setValue($manager->worker, null);
        $client = $this->client($manager);
        $this->receive($client, new WorkerRegisterDTO(3, false));
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no page agent restart door');
        $this->receive($client, new WorkerAgentRestartRequestDTO($this->ask()));
    }

    public function testWorkerDrainUsesTheTypedEnvelopeBeforeGenericAgentFallback(): void
    {
        $daemon = $this->createMock(WorkerDaemonClient::class);
        $daemon->method('isConnected')->willReturn(true);
        $daemon->expects($this->once())->method('send')->with($this->callback(
            fn ($frame): bool => $frame instanceof WorkerAgentRestartRequestDTO && $frame->data->toArray() === $this->ask()->toArray(),
        ));
        $this->queue(new SignalSource(SignalSource::AGENT, 'pages', '2'), $this->ask());
        new PageAgentRestartTestWorkerManager($daemon)->drain();
    }

    #[DataProvider('forgedSources')]
    public function testWorkerDrainRefusesAForgedReturnAddress(string $source, ?string $type, ?string $index): void
    {
        $daemon = $this->createMock(WorkerDaemonClient::class);
        $daemon->method('isConnected')->willReturn(true);
        $daemon->expects($this->never())->method('send');
        $this->queue(new SignalSource($source, $type, $index), $this->ask());
        $this->expectException(LogicException::class);
        new PageAgentRestartTestWorkerManager($daemon)->drain();
    }

    public static function forgedSources(): array
    {
        return [[SignalSource::WEBSOCKET, 'pages', '2'], [SignalSource::AGENT, 'forged', '2'], [SignalSource::AGENT, 'pages', '7']];
    }

    public function testWorkerDrainRefusesAnUntypedPayload(): void
    {
        $daemon = $this->createMock(WorkerDaemonClient::class);
        $daemon->method('isConnected')->willReturn(true);
        $daemon->expects($this->never())->method('send');
        $this->queue(new SignalSource(SignalSource::AGENT, 'pages', '2'), new SignalData());
        $this->expectException(LogicException::class);
        new PageAgentRestartTestWorkerManager($daemon)->drain();
    }

    private function ask(): DaemonAgentRestartRequestSignalData
    {
        return new DaemonAgentRestartRequestSignalData('room:12', 'node-a', 'accept-1', 'submit-1', 'pages', '2');
    }

    private function queue(SignalSource $source, DaemonAgentRestartRequestSignalData|SignalData $request): void
    {
        Hilos::$sr->queueSignal($source, new SignalType(SignalTypeConstants::DAEMON_AGENT_RESTART_REQUEST),
            new SignalName(SignalTypeConstants::DAEMON_AGENT_RESTART_REQUEST), $request);
    }

    private function client(PageAgentRestartTestManager $manager): WorkerClient
    {
        $client = new ReflectionClass(WorkerClient::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerClient::class, 'workerServer')->setValue($client, $manager->worker);
        return $client;
    }

    private function receive(WorkerClient $client, WorkerDTO $frame): void
    {
        new ReflectionMethod(WorkerClient::class, 'processMessage')->invoke($client, $frame->toJson());
    }
}
