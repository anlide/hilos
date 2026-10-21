<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\Worker;

use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Socket\WebSocket\DTO\DeliveryCompleteSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageUpdateSubscriptionSignalDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDeliverySettingsDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Worker settings use a strict typed frame on the existing IPC factory. */
final class DeliveryWorkerFrameTest extends TestCase
{
    public function testSettingsRoundtrip(): void
    {
        $frame = new WorkerDeliverySettingsDTO(175);
        self::assertSame('worker_delivery_settings', WorkerConstants::MESSAGE_DELIVERY_SETTINGS);
        self::assertSame('worker_delivery_settings', $frame->getType());
        self::assertSame(['type' => 'worker_delivery_settings', 'windowMs' => 175], $frame->toArray());
        $restored = WorkerDTO::factoryWorkerDTO($frame->toJson());
        self::assertInstanceOf(WorkerDeliverySettingsDTO::class, $restored);
        self::assertSame(175, $restored->windowMs);
        self::assertSame($frame->toArray(), $restored->toArray());
    }

    /** @param array<string, mixed> $payload Malformed settings frame */
    #[DataProvider('invalidPayloads')]
    public function testInvalidSettingsAreRejected(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        WorkerDTO::factoryWorkerDTO(json_encode(
            ['type' => 'worker_delivery_settings', ...$payload], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid window fields */
    public static function invalidPayloads(): iterable
    {
        yield 'missing' => [[]];
        foreach ([null, false, true, '1000', 1.0, 1.5, [], 0, -1] as $index => $value) {
            yield (string)$index => [['windowMs' => $value]];
        }
    }

    public function testDirectConstructionRejectsANonpositiveWindow(): void
    {
        $this->expectException(InvalidFormatException::class);
        new WorkerDeliverySettingsDTO(0);
    }

    public function testLegacyPageSubscriptionFramesHaveNoDeliveryId(): void
    {
        $subscribe = WebSocketPageSubscribeSignalDTO::fromArray([
            WebSocketPageSubscribeSignalDTO::ACCEPT_KEY => 'accept-1',
            WebSocketPageSubscribeSignalDTO::PAGE => 'profile',
        ]);
        $update = WebSocketPageUpdateSubscriptionSignalDTO::fromArray([
            WebSocketPageUpdateSubscriptionSignalDTO::ACCEPT_KEY => 'accept-1',
            WebSocketPageUpdateSubscriptionSignalDTO::PAGE => 'profile',
            WebSocketPageUpdateSubscriptionSignalDTO::DELIVERY_ID => null,
        ]);

        self::assertNull($subscribe->deliveryId);
        self::assertNull($update->deliveryId);
        self::assertArrayNotHasKey(WebSocketPageSubscribeSignalDTO::DELIVERY_ID, $subscribe->toArray());
        self::assertArrayNotHasKey(WebSocketPageUpdateSubscriptionSignalDTO::DELIVERY_ID, $update->toArray());

        $emptySubscribe = WebSocketPageSubscribeSignalDTO::fromArray([
            WebSocketPageSubscribeSignalDTO::ACCEPT_KEY => 'accept-1',
            WebSocketPageSubscribeSignalDTO::DELIVERY_ID => '',
        ]);
        $emptyUpdate = WebSocketPageUpdateSubscriptionSignalDTO::fromArray([
            WebSocketPageUpdateSubscriptionSignalDTO::ACCEPT_KEY => 'accept-1',
            WebSocketPageUpdateSubscriptionSignalDTO::DELIVERY_ID => '',
        ]);

        self::assertNull($emptySubscribe->deliveryId);
        self::assertNull($emptyUpdate->deliveryId);
    }

    public function testSubscribeDeliveryIdAndTableWindowsSurviveWorkerAndPeerRoundtrip(): void
    {
        $subscribe = new WebSocketPageSubscribeSignalDTO(
            acceptKey: 'accept-1',
            page: 'profile',
            params: ['section' => 'security'],
            tableWindows: ['sessions' => new TableWindowDescriptorDTO(limit: 25, pageIndex: 2)],
            deliveryId: 'accept-1:3',
        );
        $signal = $this->pageSignal(SignalTypeConstants::PAGE_SUBSCRIBE, 'profile', $subscribe);

        self::assertSame([
            WebSocketPageSubscribeSignalDTO::ACCEPT_KEY => 'accept-1',
            WebSocketPageSubscribeSignalDTO::PAGE => 'profile',
            WebSocketPageSubscribeSignalDTO::PARAMS => ['section' => 'security'],
            WebSocketPageSubscribeSignalDTO::TABLE_WINDOWS => ['sessions' => [
                TableWindowDescriptorDTO::LIMIT => 25,
                TableWindowDescriptorDTO::PAGE_INDEX => 2,
            ]],
            WebSocketPageSubscribeSignalDTO::DELIVERY_ID => 'accept-1:3',
        ], $subscribe->toArray());

        [$workerSignal, $peerSignal] = $this->roundTripWorkerAndPeer($signal);

        foreach ([$workerSignal, $peerSignal] as $restoredSignal) {
            self::assertInstanceOf(WebSocketPageSubscribeSignalDTO::class, $restoredSignal->data);
            self::assertSame($subscribe->toArray(), $restoredSignal->data->toArray());
            self::assertSame('profile', $restoredSignal->data->page);
            self::assertSame(['section' => 'security'], $restoredSignal->data->params);
            self::assertSame(
                ['sessions' => [TableWindowDescriptorDTO::LIMIT => 25, TableWindowDescriptorDTO::PAGE_INDEX => 2]],
                array_map(
                    static fn(TableWindowDescriptorDTO $window): array => $window->toArray(),
                    $restoredSignal->data->tableWindows,
                ),
            );
            self::assertSame('accept-1:3', $restoredSignal->data->deliveryId);
        }
    }

    public function testUpdateSubscriptionDeliveryIdSurvivesWorkerAndPeerRoundtrip(): void
    {
        $update = new WebSocketPageUpdateSubscriptionSignalDTO(
            acceptKey: 'accept-2',
            page: 'profile',
            params: ['section' => 'sessions'],
            deliveryId: 'accept-2:4',
        );
        $signal = $this->pageSignal(SignalTypeConstants::PAGE_UPDATE_SUBSCRIPTION, 'profile', $update);

        self::assertSame([
            WebSocketPageUpdateSubscriptionSignalDTO::ACCEPT_KEY => 'accept-2',
            WebSocketPageUpdateSubscriptionSignalDTO::PAGE => 'profile',
            WebSocketPageUpdateSubscriptionSignalDTO::PARAMS => ['section' => 'sessions'],
            WebSocketPageUpdateSubscriptionSignalDTO::DELIVERY_ID => 'accept-2:4',
        ], $update->toArray());

        [$workerSignal, $peerSignal] = $this->roundTripWorkerAndPeer($signal);

        foreach ([$workerSignal, $peerSignal] as $restoredSignal) {
            self::assertInstanceOf(WebSocketPageUpdateSubscriptionSignalDTO::class, $restoredSignal->data);
            self::assertSame($update->toArray(), $restoredSignal->data->toArray());
            self::assertSame('profile', $restoredSignal->data->page);
            self::assertSame(['section' => 'sessions'], $restoredSignal->data->params);
            self::assertSame('accept-2:4', $restoredSignal->data->deliveryId);
        }
    }

    /** @param class-string $dtoClass Page subscription payload class */
    #[DataProvider('invalidDeliveryIds')]
    public function testPageSubscriptionDeliveryIdsAreStrictlyTyped(string $dtoClass, mixed $deliveryId): void
    {
        $this->expectException(InvalidFormatException::class);
        $dtoClass::fromArray([
            WebSocketPageSubscribeSignalDTO::ACCEPT_KEY => 'accept-1',
            WebSocketPageSubscribeSignalDTO::PAGE => 'profile',
            WebSocketPageSubscribeSignalDTO::DELIVERY_ID => $deliveryId,
        ]);
    }

    /** @return iterable<string, array{class-string, mixed}> Invalid delivery token values */
    public static function invalidDeliveryIds(): iterable
    {
        foreach ([WebSocketPageSubscribeSignalDTO::class, WebSocketPageUpdateSubscriptionSignalDTO::class] as $dtoClass) {
            foreach ([false, true, 1, 1.5, [], new stdClass()] as $index => $deliveryId) {
                yield $dtoClass . '-' . $index => [$dtoClass, $deliveryId];
            }
        }
    }

    public function testDeliveryCompleteSignalSurvivesAddressedWorkerAndPeerRoundtrip(): void
    {
        $completion = new DeliveryCompleteSignalData('profile', 'accept-3:5');
        $signal = new SignalDTO(
            signalSource: new SignalSource(SignalSource::WORKER),
            signalType: new SignalType(SignalTypeConstants::WS_USER),
            signalName: new SignalName(SignalConstants::DELIVERY_COMPLETE),
            data: new WebSocketSignalData($completion, targetAcceptKey: 'accept-3'),
        );
        $expectedSignal = [
            'signalSource' => ['source' => SignalSource::WORKER, 'type' => null, 'index' => null],
            'signalType' => SignalTypeConstants::WS_USER,
            'signalName' => SignalConstants::DELIVERY_COMPLETE,
            'data' => [
                'data' => [
                    DeliveryCompleteSignalData::PAGE => 'profile',
                    DeliveryCompleteSignalData::DELIVERY_ID => 'accept-3:5',
                ],
                'dataType' => DeliveryCompleteSignalData::class,
                'targetAcceptKey' => 'accept-3',
            ],
            'dataType' => WebSocketSignalData::class,
            'meta' => [],
        ];

        self::assertSame('hilos_delivery_complete', SignalConstants::DELIVERY_COMPLETE);
        self::assertSame($expectedSignal, $signal->toArray());

        [$workerSignal, $peerSignal] = $this->roundTripWorkerAndPeer($signal);

        foreach ([$workerSignal, $peerSignal] as $restoredSignal) {
            self::assertSame($expectedSignal, $restoredSignal->toArray());
            self::assertSame(SignalTypeConstants::WS_USER, $restoredSignal->signalType->getType());
            self::assertSame(SignalConstants::DELIVERY_COMPLETE, $restoredSignal->signalName->getName());
            self::assertInstanceOf(WebSocketSignalData::class, $restoredSignal->data);
            self::assertSame('accept-3', $restoredSignal->data->targetAcceptKey);
            self::assertInstanceOf(DeliveryCompleteSignalData::class, $restoredSignal->data->data);
            self::assertSame('profile', $restoredSignal->data->data->page);
            self::assertSame('accept-3:5', $restoredSignal->data->data->deliveryId);
        }
    }

    /** @param array<string, mixed> $payload Completion payload missing or mistyping required values */
    #[DataProvider('invalidDeliveryCompletePayloads')]
    public function testInvalidDeliveryCompletePayloadsAreRejected(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);
        DeliveryCompleteSignalData::fromArray($payload);
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid completion payloads */
    public static function invalidDeliveryCompletePayloads(): iterable
    {
        yield 'missing page' => [[DeliveryCompleteSignalData::DELIVERY_ID => 'accept-1:1']];
        yield 'missing delivery id' => [[DeliveryCompleteSignalData::PAGE => 'profile']];
        yield 'empty page' => [[DeliveryCompleteSignalData::PAGE => '', DeliveryCompleteSignalData::DELIVERY_ID => 'accept-1:1']];
        yield 'empty delivery id' => [[DeliveryCompleteSignalData::PAGE => 'profile', DeliveryCompleteSignalData::DELIVERY_ID => '']];
        yield 'non-string page' => [[DeliveryCompleteSignalData::PAGE => 7, DeliveryCompleteSignalData::DELIVERY_ID => 'accept-1:1']];
        yield 'non-string delivery id' => [[DeliveryCompleteSignalData::PAGE => 'profile', DeliveryCompleteSignalData::DELIVERY_ID => false]];
    }

    /**
     * @param string $signalType Signal routing type
     * @param string $page Page used as the signal name
     * @param SignalDataInterface $data Page subscription payload
     * @return SignalDTO Routed page subscription signal
     */
    private function pageSignal(string $signalType, string $page, SignalDataInterface $data): SignalDTO
    {
        return new SignalDTO(
            signalSource: new SignalSource(SignalSource::WEBSOCKET),
            signalType: new SignalType($signalType),
            signalName: new SignalName($page),
            data: $data,
        );
    }

    /**
     * @param SignalDTO $signal Signal crossing the worker and peer transports
     * @return array{SignalDTO, SignalDTO} Restored worker and peer signal
     */
    private function roundTripWorkerAndPeer(SignalDTO $signal): array
    {
        $workerFrame = WorkerDTO::factoryWorkerDTO((new WorkerAgentMessageDTO('agent-1', $signal))->toJson());
        self::assertInstanceOf(WorkerAgentMessageDTO::class, $workerFrame);

        $peerFrame = new PeerSignalDTO('node-A', 'node-B', 'page_agent', 'profile', $workerFrame->signal);
        $restoredPeerFrame = PeerDTO::fromWire($peerFrame->toJson());
        self::assertInstanceOf(PeerSignalDTO::class, $restoredPeerFrame);

        return [$workerFrame->signal, $restoredPeerFrame->signal];
    }
}
