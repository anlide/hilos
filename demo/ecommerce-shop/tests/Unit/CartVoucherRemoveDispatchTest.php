<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Demo\EcommerceShop\Constants\AgentType;
use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Daemon\EcommerceShopDaemonManager;
use Demo\EcommerceShop\Core\Socket\Client\EcommerceShopWebSocketClient;
use Demo\EcommerceShop\Core\Socket\Server\EcommerceShopWebSocketServer;
use Demo\EcommerceShop\Pages\CartPage;
use Demo\EcommerceShop\Pages\DTO\CartStepQuantityActionDTO;
use Demo\EcommerceShop\Pages\DTO\CartVoucherApplyActionDTO;
use Demo\EcommerceShop\Pages\DTO\CartVoucherRemoveActionDTO;
use Demo\EcommerceShop\Pages\MainPage;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Socket\WebSocket\DTO\DeliveryCompleteSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Socket;

/** Runs the shop's real browser entry, master dispatch and local WebSocket delivery with an inert worker transport. */
final class CartVoucherRemoveDispatchTest extends TestCase
{
    private const string REQUEST_ID = 'remove-voucher-request';

    private ?SignalRouter $previousRouter;
    private EcommerceShopDaemonManager $master;
    private EcommerceShopWebSocketServer $server;
    private WorkerServer&MockObject $worker;

    /** @var list<array{EcommerceShopWebSocketClient, Socket}> Shop connections and their browser ends */
    private array $connections = [];

    /** @return iterable<string, array{?string}> Subscription records with no route for Remove */
    public static function unservedPages(): iterable
    {
        yield 'no subscription' => [null];
        yield 'another page' => [MainPage::PAGE];
        yield 'unbound cart' => [CartPage::PAGE];
    }

    #[DataProvider('unservedPages')]
    public function testUnservedRemoveRepliesOnlyToItsBrowser(?string $page): void
    {
        [$client, $browser] = $this->connect('initiator');
        [$other, $otherBrowser] = $this->connect('other');
        if ($page !== null) {
            Hilos::$sr->subscribeToPage($page, new WebSocketPageSubscribeSignalDTO($client->acceptKey, $page));
        }
        $this->worker->expects(self::never())->method('sendSignalToAgent');

        $this->sendRemove($client, self::REQUEST_ID);
        $this->drain();

        $this->assertRemoveRefusal($client, $browser);
        self::assertSame('', $this->writeAndRead($other, $otherBrowser));
    }

    public function testPendingSubscriptionHoldsTheRefusalUntilItsCompletion(): void
    {
        [$client, $browser] = $this->connect('initiator');
        $this->worker->expects(self::once())->method('sendSignalToAgent')
            ->with(MainPage::SUBSCRIPTION_AGENT_TYPE, null, self::callback(
                static fn (DaemonAgentMessageDTO $message): bool =>
                    $message->signal->signalType->getType() === SignalTypeConstants::PAGE_SUBSCRIBE,
            ));

        $this->receive($client, [
            SignalPayloadConstants::FIELD_TYPE => SignalTypeConstants::PAGE_SUBSCRIBE,
            SignalPayloadConstants::FIELD_PAGE => MainPage::PAGE,
        ]);
        $pending = $client->pendingPageSubscribe();
        self::assertNotNull($pending?->deliveryId);
        $this->drain();
        $this->sendRemove($client, self::REQUEST_ID);
        $this->drain();

        self::assertTrue($client->isDeliveryPending(MainPage::PAGE, $pending->deliveryId));
        self::assertSame('', $this->writeAndRead($client, $browser));

        $this->master->deliverSignalToClient($client->acceptKey, new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::WS_USER),
            new SignalName(SignalConstants::DELIVERY_COMPLETE),
            new WebSocketSignalData(new DeliveryCompleteSignalData(MainPage::PAGE, $pending->deliveryId), targetAcceptKey: $client->acceptKey),
        ));
        $this->drain();

        self::assertFalse($client->isDeliveryPending(MainPage::PAGE, $pending->deliveryId));
        $this->assertRemoveRefusal($client, $browser);
    }

    public function testDepartedOriginalCannotSendTheRefusalToAReplacement(): void
    {
        [$client, $browser] = $this->connect('reused-address');
        $this->worker->expects(self::never())->method('sendSignalToAgent');
        $this->sendRemove($client, self::REQUEST_ID);
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertInstanceOf(SignalDTO::class, $signal);

        // Advance the ordinary dispatch continuation to the boundary after it captures the socket.
        $dispatcher = new ReflectionMethod(DaemonManager::class, 'signalDispatcher')->invoke($this->master);
        $dispatch = $dispatcher->createSignalDispatch($signal, false, $this->worker, $this->server, null, null, null);
        while ($dispatch->hasWork() && !$dispatch->clientResolved) {
            $dispatcher->runSignalPortion($dispatch, 1);
        }
        self::assertSame($client, $dispatch->clientTarget?->getClient());

        $this->server->dropClient($client);
        [$replacement, $replacementBrowser] = $this->connect($client->acceptKey);
        self::assertSame($replacement, $this->server->clientTarget($client->acceptKey)?->getClient());
        $dispatcher->runSignalPortion($dispatch, 64);

        self::assertFalse($dispatch->hasWork());
        self::assertFalse($client->hasPendingWrite());
        self::assertSame('', new PlainSocketTransport($browser)->read(8192));
        self::assertSame('', $this->writeAndRead($replacement, $replacementBrowser));
    }

    /** @return iterable<string, array{mixed, ?string}> Browser input and proof carried through IPC */
    public static function removeProofs(): iterable
    {
        $proof = str_repeat('a', 64);
        yield 'proof' => [$proof, $proof];
        yield 'wrong JSON type' => [['proof' => $proof], null];
        yield 'wrong string format' => ['invalid', null];
        yield 'omitted' => [null, null];
    }

    #[DataProvider('removeProofs')]
    public function testBoundRemoveReachesTheCartOwnerWithoutATransportRefusal(mixed $input, ?string $proof): void
    {
        [$client, $browser] = $this->connect('initiator');
        Hilos::$sr->subscribeToPage(CartPage::PAGE, new WebSocketPageSubscribeSignalDTO($client->acceptKey, CartPage::PAGE));
        Hilos::$sr->bindPageAgent($client->acceptKey, CartPage::PAGE, AgentType::GUEST_CARTS_LIBRARY, null);
        $this->worker->expects(self::once())->method('sendSignalToAgent')
            ->with(AgentType::GUEST_CARTS_LIBRARY, null, self::callback(static function (DaemonAgentMessageDTO $message) use ($proof): bool {
                $signal = DaemonAgentMessageDTO::fromJson($message->toJson())->signal;
                self::assertSame(EcommerceShopSignalConstants::CART_REMOVE_VOUCHER, $signal->signalName->getName());
                self::assertInstanceOf(WebSocketActionSignalDTO::class, $signal->data);
                self::assertSame(self::REQUEST_ID, $signal->data->requestId);
                self::assertSame(['expectedVoucherId' => 17, 'ownerKind' => CartStepQuantityActionDTO::GUEST], $signal->data->data);
                self::assertSame($proof, $signal->data->pageSubscriptionProof);
                self::assertNull($signal->data->pageSubscriptionDeliveryId);

                return true;
            }));

        $this->sendRemove($client, self::REQUEST_ID, $input);
        $this->drain();

        self::assertSame('', $this->writeAndRead($client, $browser));
    }

    public function testUnservedApplyAndUntrackedRemoveKeepTheirExistingDisposition(): void
    {
        [$client, $browser] = $this->connect('initiator');
        $this->worker->expects(self::never())->method('sendSignalToAgent');

        $this->receive($client, [
            SignalPayloadConstants::FIELD_TYPE => SignalTypeConstants::ACTION,
            SignalPayloadConstants::FIELD_ACTION => EcommerceShopSignalConstants::CART_APPLY_VOUCHER,
            SignalPayloadConstants::FIELD_DATA => new CartVoucherApplyActionDTO('FLOWERS', CartStepQuantityActionDTO::GUEST)->toArray(),
            SignalPayloadConstants::FIELD_REQUEST_ID => 'apply-voucher-request',
        ]);
        $this->sendRemove($client, null);
        $this->drain();

        self::assertSame('', $this->writeAndRead($client, $browser));
    }

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        $this->master = new EcommerceShopDaemonManager();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, new EventLoop());
        $this->server = new EcommerceShopWebSocketServer('127.0.0.1', 0);
        $this->worker = $this->getMockBuilder(WorkerServer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['onStart', 'ensureAgentUp', 'sendSignalToAgent'])
            ->getMock();
        $this->master->registerServer($this->server);
        $this->master->registerServer($this->worker);
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as [$client, $browser]) {
            $this->server->dropClient($client);
            socket_close($browser);
        }
        Hilos::$sr = $this->previousRouter;
    }

    /** @return array{EcommerceShopWebSocketClient, Socket} Shop socket and its nonblocking browser end */
    private function connect(string $acceptKey): array
    {
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        self::assertTrue(socket_set_nonblock($pair[0]));
        self::assertTrue(socket_set_nonblock($pair[1]));
        $client = new EcommerceShopWebSocketClient($pair[0]);
        new ReflectionProperty(WebSocketClient::class, 'handshakeCompleted')->setValue($client, true);
        $client->acceptKey = $acceptKey;
        new ReflectionMethod(WebSocketServer::class, 'addClient')->invoke($this->server, $client);
        $this->connections[] = [$client, $pair[1]];
        return [$client, $pair[1]];
    }

    private function sendRemove(EcommerceShopWebSocketClient $client, ?string $requestId, mixed $proof = null): void
    {
        $frame = [
            SignalPayloadConstants::FIELD_TYPE => SignalTypeConstants::ACTION,
            SignalPayloadConstants::FIELD_ACTION => EcommerceShopSignalConstants::CART_REMOVE_VOUCHER,
            SignalPayloadConstants::FIELD_DATA => new CartVoucherRemoveActionDTO(17, CartStepQuantityActionDTO::GUEST)->toArray(),
        ];
        if ($requestId !== null) {
            $frame[SignalPayloadConstants::FIELD_REQUEST_ID] = $requestId;
        }
        if ($proof !== null) {
            $frame['pageSubscriptionProof'] = $proof;
            $frame['pageSubscriptionDeliveryId'] = 'browser-supplied';
        }
        $this->receive($client, $frame);
    }

    /** @param array<string, mixed> $frame Browser text frame before encoding */
    private function receive(EcommerceShopWebSocketClient $client, array $frame): void
    {
        new ReflectionMethod(WebSocketClient::class, 'onFrame')->invoke($client, json_encode($frame, JSON_THROW_ON_ERROR));
    }

    private function drain(): void
    {
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        $runnable = new ReflectionMethod(DaemonManager::class, 'hasRunnableSignalDispatch');
        do {
            $dispatch->invoke($this->master);
        } while ($runnable->invoke($this->master));
    }

    private function assertRemoveRefusal(EcommerceShopWebSocketClient $client, Socket $browser): void
    {
        $bytes = $this->writeAndRead($client, $browser);
        self::assertNotSame('', $bytes);
        self::assertSame(0x81, ord($bytes[0]));
        $length = ord($bytes[1]);
        $header = 2;
        if ($length === 126) {
            $length = unpack('n', substr($bytes, 2, 2))[1];
            $header = 4;
        }
        self::assertSame($header + $length, strlen($bytes), 'Exactly one physical text frame');
        self::assertSame([
            'type' => 'action_error',
            'data' => [
                'action' => 'cart_remove_voucher',
                'reason' => 'This cart is no longer available.',
                'requestId' => self::REQUEST_ID,
            ],
            'outcome' => 'fail',
            'requestId' => self::REQUEST_ID,
        ], json_decode(substr($bytes, $header), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('', $this->writeAndRead($client, $browser));
    }

    private function writeAndRead(EcommerceShopWebSocketClient $client, Socket $browser): string
    {
        $client->write();
        $transport = new PlainSocketTransport($browser);
        $bytes = '';
        while (($chunk = $transport->read(8192)) !== false && $chunk !== '') {
            $bytes .= $chunk;
        }
        return $bytes;
    }
}
