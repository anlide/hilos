<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tests\Unit;

use Demo\EcommerceShop\Constants\EcommerceShopSignalConstants;
use Demo\EcommerceShop\Core\Socket\Client\EcommerceShopWebSocketClient;
use Demo\EcommerceShop\Database\Object\Item\Category;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalPayloadConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Analytics\AnalyticsJournalRecord;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Hilos;
use Hilos\Socket\WebSocket\DTO\WebSocketActionSignalDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/** Exercises the project client's action entry with a socketless connection. */
final class EcommerceShopWebSocketClientTest extends TestCase
{
    private const array CART_STEP = ['lineId' => 19, 'ownerKind' => 'guest', 'step' => 1];
    private const string REQUEST_ID = 'request-19';

    private ?SignalRouter $previousSignalRouter = null;
    private ?AnalyticsCollector $previousCollector = null;
    private EcommerceShopWebSocketClient $client;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousCollector = Hilos::$ac;
        Hilos::$sr = new SignalRouter();
        Hilos::$ac = new AnalyticsCollector();

        $this->client = new ReflectionClass(EcommerceShopWebSocketClient::class)->newInstanceWithoutConstructor();
        $this->client->acceptKey = 'browser-key';
        Hilos::$ac->openWsConnection($this->client->acceptKey, null);
        Hilos::$ac->flush();
        self::assertSame(HilosSignalConstants::ANALYTICS_JOURNAL_APPEND, Hilos::$sr->getNextQueuedSignal()?->signalName->getName());
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$ac = $this->previousCollector;
        parent::tearDown();
    }

    /**
     * @param string $action Registered project action
     * @param array<string, mixed> $data Browser action payload
     */
    #[DataProvider('acceptedActions')]
    public function testRegisteredActionIsQueuedAndRecorded(string $action, array $data): void
    {
        $this->receiveAction($action, $data);

        $queued = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($queued);
        self::assertSame(SignalTypeConstants::ACTION, $queued->signalType->getType());
        self::assertSame($action, $queued->signalName->getName());
        self::assertInstanceOf(WebSocketActionSignalDTO::class, $queued->data);
        self::assertSame($this->client->acceptKey, $queued->data->acceptKey);
        self::assertSame(self::REQUEST_ID, $queued->data->requestId);
        self::assertSame($data, $queued->data->data);

        Hilos::$ac->flush();
        $journal = Hilos::$sr->getNextQueuedSignal();
        self::assertSame(HilosSignalConstants::ANALYTICS_JOURNAL_APPEND, $journal?->signalName->getName());
        self::assertInstanceOf(AgentSignalData::class, $journal->data);
        self::assertInstanceOf(AnalyticsJournalAppendSignalData::class, $journal->data->data);
        self::assertCount(1, $journal->data->data->lines);
        $record = json_decode($journal->data->data->lines[0], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(AnalyticsJournalRecord::TYPE_USER_ACTION, $record[AnalyticsJournalRecord::KEY_TYPE]);
        self::assertSame($action, $record[AnalyticsJournalRecord::KEY_ACTION]);
        self::assertFalse(Hilos::$sr->hasQueuedSignals());
    }

    /** @return iterable<string, array{string, array<string, mixed>}> Registered actions and their browser payloads */
    public static function acceptedActions(): iterable
    {
        yield 'cart step' => [EcommerceShopSignalConstants::CART_STEP_QUANTITY, self::CART_STEP];
        yield 'category move with wrapper' => [
            EcommerceShopSignalConstants::CATEGORY_MOVE_UP,
            [SignalPayloadConstants::FIELD_DATA => [Category::id => 21]],
        ];
    }

    /** @param mixed $data Invalid cart-step payload */
    #[DataProvider('invalidCartSteps')]
    public function testInvalidCartStepReceivesOnlyACorrelatedRefusal(mixed $data): void
    {
        $action = EcommerceShopSignalConstants::CART_STEP_QUANTITY;
        $this->receiveAction($action, $data);

        $reply = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($reply);
        self::assertSame(SignalTypeConstants::WS_USER, $reply->signalType->getType());
        self::assertInstanceOf(WebSocketSignalData::class, $reply->data);
        self::assertSame($this->client->acceptKey, $reply->data->targetAcceptKey);

        Hilos::$ac->flush();
        self::assertFalse(Hilos::$sr->hasQueuedSignals());

        $daemon = $this->getMockBuilder(DaemonManager::class)
            ->onlyMethods(['createSignalRouter', 'createAgentManagerDaemon'])
            ->disableOriginalConstructor()
            ->getMock();
        $frame = $daemon->prepareSignalFrame($reply);
        self::assertNotNull($frame);
        self::assertSame([
            'type' => SignalConstants::ACTION_ERROR,
            'data' => ['action' => $action, 'reason' => SignalConstants::ACTION_FAILED_REASON, 'requestId' => self::REQUEST_ID],
            'outcome' => 'fail',
            'requestId' => self::REQUEST_ID,
        ], json_decode($frame->json, true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{mixed}> Payloads refused at the cart WebSocket entry */
    public static function invalidCartSteps(): iterable
    {
        yield 'scalar data' => [19];
        yield 'too few fields' => [['lineId' => 19, 'ownerKind' => 'guest']];
        yield 'extra field' => [[...self::CART_STEP, 'quantity' => 2]];
        yield 'nested field' => [[...self::CART_STEP, 'lineId' => [19]]];
        yield 'float step' => [[...self::CART_STEP, 'step' => 1.0]];
    }

    /**
     * @param string $action Registered project action
     * @param mixed $data JSON action payload
     */
    private function receiveAction(string $action, mixed $data): void
    {
        new ReflectionMethod(EcommerceShopWebSocketClient::class, 'onFrame')->invoke($this->client, json_encode([
            SignalPayloadConstants::FIELD_TYPE => SignalTypeConstants::ACTION,
            SignalPayloadConstants::FIELD_ACTION => $action,
            SignalPayloadConstants::FIELD_REQUEST_ID => self::REQUEST_ID,
            SignalPayloadConstants::FIELD_DATA => $data,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
