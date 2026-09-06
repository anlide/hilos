<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * What the subscriber is told when its page stops being delivered (HIL-575).
 *
 * Before this the delivery paths failed in silence. The page in the browser simply stopped
 * moving: no frame said so, the client had nothing to render, and the person in front of it had
 * no way to tell a broken node from a quiet afternoon. The failure was contained per
 * subscription, which was right, and then never mentioned to the one subscription it cost.
 *
 * Three things have to hold at once, and they pull against each other. The subscriber must be
 * told. It must be told ONCE, because the defect is in a declaration or in the wiring and is
 * therefore present on every flush — ten frames a second for as long as it lasts. And when
 * delivery resumes it must be handed the whole page rather than a delta, because being told
 * cost it its page scope: the client wipes what it was holding so that a stale verdict cannot
 * outlive the error, and a delta would land on nothing.
 */
final class BrowserContextDeliveryErrorTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new DeliveryErrorRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->addRow(DeliveryErrorState::create('1', 'Ada'));
        Hilos::$rt->addRow(DeliveryErrorState::create('2', 'Grace'));

        Hilos::$sr->subscribeToPage(
            DeliveryErrorContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', DeliveryErrorContext::PAGE),
        );
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$rt = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testAFailedDeliveryTellsTheSubscriberOnceWithAScrubbedFiveHundred(): void
    {
        $this->flush(broken: true);

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame('ak-1', $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $signal->data->data);
        $this->assertSame(DeliveryErrorContext::PAGE, $signal->data->data->page);
        $this->assertSame(500, $signal->data->data->httpCode);
        $this->assertSame('internal_error', $signal->data->data->errorCode);
        $this->assertSame('Internal error while delivering the page', $signal->data->data->message);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * The second flush is the one that matters. A frame per flush would be a frame every worker
     * tick, and the subscriber would be told the same thing until the defect was fixed.
     */
    public function testTheSecondFailedFlushSaysNothing(): void
    {
        $this->flush(broken: true);
        $this->drain();

        $this->flush(broken: true);

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * The delta this flush carries is one row. The subscriber gets both, because the page it
     * would have applied that row to is gone.
     */
    public function testTheFirstDeliveryAfterAFailureIsTheWholePage(): void
    {
        $this->flush(broken: true);
        $this->drain();

        $this->flush(broken: false);

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(['1', '2'], $this->rowKeysOf($signal->data->data));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * And once it has been made whole, the next change is an ordinary delta again: the flag is
     * cleared by the delivery that honored it, not left standing to re-send the page forever.
     */
    public function testDeliveryAfterTheRecoveryIsADeltaAgain(): void
    {
        $this->flush(broken: true);
        $this->drain();
        $this->flush(broken: false);
        $this->drain();

        $this->flush(broken: false);

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(['1'], $this->rowKeysOf($signal->data->data));
    }

    /**
     * A subscription that never failed is not owed a snapshot, and asking for one on every
     * delivery would turn every delta into a full page.
     */
    public function testAHealthySubscriptionKeepsGettingDeltas(): void
    {
        $this->flush(broken: false);

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(['1'], $this->rowKeysOf($signal->data->data));
    }

    /**
     * A re-subscribe is a fresh page, so whatever this connection was last told about the old
     * one stops being owed: the snapshot the subscribe itself sends is the whole page already.
     */
    public function testResubscribingForgetsThatTheOldPageHadFailed(): void
    {
        $this->flush(broken: true);
        $this->drain();

        Hilos::$sr?->subscribeToPage(
            DeliveryErrorContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', DeliveryErrorContext::PAGE),
        );
        $this->flush(broken: false);

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(['1'], $this->rowKeysOf($signal->data->data));
    }

    /**
     * Records one change to row 1 and flushes it through a context in the requested state.
     *
     * A fresh context each time, because a worker's browser context memoizes the page config it
     * read and the fixture's answer changes between flushes; the state under test lives in the
     * subscription registry, which is not rebuilt.
     *
     * @param bool $broken Whether the page's guard declaration names a type nothing implements
     */
    private function flush(bool $broken): void
    {
        $context = new DeliveryErrorContext($broken);
        $context->record(SourceChange::rtUpdated(DeliveryErrorRtContext::ROWS, '1', ['name' => 'Ada']));
        $context->flushToSignalRouter();
    }

    /**
     * Empties the signal queue so the next assertion reads only what the next flush queued.
     */
    private function drain(): void
    {
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
            // Nothing: the frames themselves are asserted by the case that queued them.
        }
    }

    /**
     * Row keys the page response carries for the fixture's one table, in payload order.
     *
     * @param PageResponseSignalData $response Queued page response
     * @return list<string> Row keys the subscriber received
     */
    private function rowKeysOf(PageResponseSignalData $response): array
    {
        $table = $response->payload->tables[DeliveryErrorContext::TABLE] ?? null;
        $this->assertIsArray($table);
        $rows = $table[BrowserPageSignalData::rows] ?? null;
        $this->assertIsArray($rows);

        $keys = [];
        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $this->assertArrayHasKey(PagePayload::rowKey, $row);
            $keys[] = (string) $row[PagePayload::rowKey];
        }
        sort($keys);

        return $keys;
    }
}

/**
 * Serves one page off one runtime collection, with its page guard broken on demand.
 */
final class DeliveryErrorContext extends BrowserContext
{
    public const string PAGE = 'delivery_error_page';
    public const string TABLE = 'deliveryErrorRows';

    private const string SIGNAL = 'delivery_error_signal';

    /**
     * @param bool $broken Whether the page's guard declaration names a type nothing implements
     */
    public function __construct(private readonly bool $broken = false)
    {
        parent::__construct();
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Test page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if ($page !== self::PAGE) {
            return null;
        }

        return BrowserPageConfig::fromArray([
            BrowserConfigKey::SIGNAL => self::SIGNAL,
            BrowserConfigKey::GUARDS => $this->broken
                ? [[BrowserGuardKey::TYPE => 'no_such_guard_type']]
                : [],
        ]);
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Test page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return $page === self::PAGE
            ? BrowserPageBindings::fromArray([self::TABLE => []])
            : BrowserPageBindings::empty();
    }

    /**
     * @param string $browserKey Browser table key
     * @return ?BrowserSourceConfig Test browser-only table config
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        if ($browserKey !== self::TABLE) {
            return null;
        }

        return BrowserSourceConfig::fromArray([
            BrowserTableConfigKey::ROWS => [[
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::RT,
                    BrowserSourceKey::KEY => DeliveryErrorRtContext::ROWS,
                ],
                BrowserTableFieldKey::ROW_KEY => 'id',
                BrowserTableFieldKey::FIELDS => ['id', 'name'],
            ]],
        ]);
    }
}

/**
 * Runtime context holding the two rows the page reads.
 */
final class DeliveryErrorRtContext extends RtContext
{
    public const string ROWS = 'deliveryErrorSourceRows';

    public function configure(): void
    {
        $this->_stateCollections[self::ROWS] = DeliveryErrorStates::init();
        $this->setRepresent(self::ROWS, DeliveryErrorCollection::class);
    }

    /**
     * @param DeliveryErrorState $row Row to add to the collection
     */
    public function addRow(DeliveryErrorState $row): void
    {
        $this->_stateCollections[self::ROWS]->add($row);
    }
}

final class DeliveryErrorStates extends RtStates
{
    public const string STATE_CLASS = DeliveryErrorState::class;
}

final class DeliveryErrorState extends RtState
{
    private function __construct(
        public readonly string $id,
        public readonly string $name,
    ) {
        parent::__construct();
    }

    /**
     * @param string $id Row key
     * @param string $name Row label
     * @return self Row state
     */
    public static function create(string $id, string $name): self
    {
        return new self($id, $name);
    }

    /**
     * @return string Row key
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @param array<string, mixed> $row Raw row
     * @return static Row state
     */
    public static function fromRow(array $row): static
    {
        return new static((string)$row['id'], (string)$row['name']);
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}

final class DeliveryErrorCollection extends RtCollection
{
    /**
     * @param RtState $state Backing state
     * @return RtItem View item over the state
     */
    protected function createRtItem(RtState $state): RtItem
    {
        return new DeliveryErrorItem($state);
    }
}

final class DeliveryErrorItem extends RtItem
{
    /**
     * @param string $name Field name
     * @return mixed Field value
     */
    public function __get(string $name): mixed
    {
        $data = $this->toArray();

        return $data[$name] ?? parent::__get($name);
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return $this->getState()->toArray();
    }
}
