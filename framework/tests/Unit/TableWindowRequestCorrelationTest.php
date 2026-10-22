<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowRefusedSignalData;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketTableViewportSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Scalar window correlation survives the actual wire and IPC serializers. */
final class TableWindowRequestCorrelationTest extends TestCase
{
    /** @return iterable<string, array{class-string, array<string, mixed>}> Untracked payloads */
    public static function payloads(): iterable
    {
        yield 'request' => [WebSocketTableViewportSignalDTO::class, ['acceptKey' => 'ak', 'page' => 'p', 'tableKey' => 't', 'limit' => 10]];
        yield 'window' => [TableWindowSignalData::class, [
            'page' => 'p', 'tableKey' => 't', 'rows' => [], 'totalCount' => 0, 'totalExact' => true, 'limit' => 10,
        ]];
        yield 'refusal' => [TableWindowRefusedSignalData::class, ['page' => 'p', 'tableKey' => 't', 'errorCode' => 'internal_error']];
    }

    /**
     * @param class-string $class DTO class
     * @param array<string, mixed> $payload Untracked wire payload
     */
    #[DataProvider('payloads')]
    public function testAbsentIdIsOmittedAndValidIdSurvivesScalarRoundTrip(string $class, array $payload): void
    {
        $untracked = $class::fromArray($payload);
        $this->assertNull($untracked->requestId);
        $this->assertArrayNotHasKey('requestId', $untracked->toArray());

        $tracked = $class::fromArray([...$payload, 'requestId' => 'mount:epoch:2']);
        $restored = $class::fromJson($tracked->toJson());
        $this->assertSame('mount:epoch:2', $restored->requestId);
        $this->assertSame($tracked->toArray(), $restored->toArray());
        $this->assertSame('mount:epoch:2', (clone $restored)->requestId);

        $signal = new SignalDTO(
            new SignalSource(SignalSource::WORKER),
            new SignalType(SignalTypeConstants::WS_USER),
            new SignalName('table-reply'),
            new WebSocketSignalData($tracked, targetAcceptKey: 'ak'),
        );
        $worker = WorkerDTO::factoryWorkerDTO(new WorkerAgentMessageDTO(null, $signal)->toJson());
        $this->assertInstanceOf(WorkerAgentMessageDTO::class, $worker);
        $this->assertInstanceOf(WebSocketSignalData::class, $worker->signal->data);
        $this->assertSame('ak', $worker->signal->data->targetAcceptKey);
        $this->assertInstanceOf($class, $worker->signal->data->data);
        $this->assertSame('mount:epoch:2', $worker->signal->data->data->requestId);
        $this->assertArrayNotHasKey('requestId', $worker->signal->data->toArray());
    }

    /** @return iterable<string, array{class-string, array<string, mixed>}> Malformed wire payloads */
    public static function invalidPayloads(): iterable
    {
        foreach (self::payloads() as $name => [$class, $payload]) {
            foreach (['empty' => '', 'int' => 1, 'bool' => false, 'array' => [], 'null' => null] as $kind => $invalid) {
                yield $name . '-' . $kind => [$class, [...$payload, 'requestId' => $invalid]];
            }
        }
    }

    /**
     * @param class-string $class DTO class
     * @param array<string, mixed> $payload Malformed wire payload
     */
    #[DataProvider('invalidPayloads')]
    public function testMalformedPresentIdIsRefused(string $class, array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        $class::fromArray($payload);
    }

    public function testRequestSurvivesMasterWorkerPeerAndWorkerCopy(): void
    {
        $request = new WebSocketTableViewportSignalDTO(acceptKey: 'ak', page: 'p', tableKey: 't', requestId: 'mount:1');
        $signal = new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::TABLE_VIEWPORT),
            new SignalName('p'),
            $request,
        );
        $masterCopy = SignalDTO::fromJson($signal->toJson());
        $peer = PeerDTO::fromWire(new PeerSignalDTO('node-a', 'node-b', 'page-agent', '1', $masterCopy)->toJson());
        $this->assertInstanceOf(PeerSignalDTO::class, $peer);
        $worker = WorkerDTO::factoryWorkerDTO(new DaemonAgentMessageDTO('page-agent:1', $peer->signal)->toJson());
        $this->assertInstanceOf(DaemonAgentMessageDTO::class, $worker);
        $copy = SignalDTO::fromArray($worker->signal->toArray());
        $this->assertInstanceOf(WebSocketTableViewportSignalDTO::class, $copy->data);
        $this->assertSame($request->toArray(), $copy->data->toArray());
        $this->assertSame(SignalSource::WEBSOCKET, $copy->signalSource->getSource());
        $this->assertSame('p', $copy->signalName->getName());
        $this->assertSame([], $copy->meta);
    }
}
