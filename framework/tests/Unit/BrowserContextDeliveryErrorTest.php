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
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\PageResendOutcome;
use Hilos\Core\Page\PageResender;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Row\AbstractTableRow;
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
        Hilos::$table = null;
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
     * The delta this flush carries is one row, and it is not what goes out: the page it would have
     * applied that row to is gone, so the page is re-sent whole - by the page itself, through the
     * frame a subscribe is answered with (HIL-1236), which brings back its own part and the frames
     * it sends ahead of its answer, and not only the browser rows.
     */
    public function testTheFirstDeliveryAfterAFailureIsTheWholePage(): void
    {
        $this->flush(broken: true);
        $this->drain();
        $resender = new DeliveryErrorRecordingResender(PageResendOutcome::Answered);

        $this->flush(broken: false, resender: $resender);

        $this->assertSame([[DeliveryErrorContext::PAGE, 'ak-1', []]], $resender->calls);
        $this->assertSame([SignalTypeConstants::PAGE_RESPONSE], $this->drain());
    }

    /**
     * And once it has been made whole, the next change is an ordinary delta again: the flag is
     * cleared by the delivery that honored it, not left standing to re-send the page forever.
     */
    public function testDeliveryAfterTheRecoveryIsADeltaAgain(): void
    {
        $resender = new DeliveryErrorRecordingResender(PageResendOutcome::Answered);
        $this->flush(broken: true);
        $this->drain();
        $this->flush(broken: false, resender: $resender);
        $this->drain();

        $this->flush(broken: false, resender: $resender);

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(['1'], $this->rowKeysOf($signal->data->data));
        $this->assertCount(1, $resender->calls);
    }

    /**
     * A re-send whose page failed has already told the connection so - the page's router sent the
     * internal-error frame - so the mark is set without a second frame, and the next delivery
     * that succeeds owes the page whole once more.
     */
    public function testAReSendThatFailedIsTriedAgainOnTheNextDeliveryWithoutASecondErrorFrame(): void
    {
        $this->flush(broken: true);
        $this->drain();
        $resender = new DeliveryErrorRecordingResender(PageResendOutcome::Failed);

        $this->flush(broken: false, resender: $resender);

        $this->assertSame([SignalConstants::SUBSCRIPTION_PAGE_ERROR], $this->drain());
        $this->flush(broken: false, resender: $resender);
        $this->assertCount(2, $resender->calls);
        $this->assertSame([SignalConstants::SUBSCRIPTION_PAGE_ERROR], $this->drain());
    }

    /**
     * Nobody here to answer the page, and nothing went out: the connection is told its page could
     * not be delivered, once, and the mark stays for the delivery after.
     */
    public function testAPageNobodyServesHereIsToldAsAFailedDelivery(): void
    {
        $this->flush(broken: true);
        $this->drain();

        ob_start();
        $this->flush(broken: false);
        $logged = (string) ob_get_clean();

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(SignalConstants::SUBSCRIPTION_PAGE_ERROR, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $signal->data->data);
        $this->assertSame('internal_error', $signal->data->data->errorCode);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->assertStringContainsString('could not re-send a page nobody serves here', $logged);
        $this->assertFalse(Hilos::$sr?->markPageDeliveryFailure('ak-1'), 'the mark stands');
    }

    /**
     * A re-send the verdict refused leaves the subscription where a refused subscription stands:
     * told so, kept alive and unmarked, for the live road to promote once its guard passes.
     */
    public function testAReSendTheVerdictRefusedLeavesNoMark(): void
    {
        $this->flush(broken: true);
        $this->drain();

        $this->flush(broken: false, resender: new DeliveryErrorRecordingResender(PageResendOutcome::Refused));

        $this->assertSame([SignalConstants::SUBSCRIPTION_PAGE_ERROR], $this->drain());
        $this->assertTrue(Hilos::$sr?->markPageDeliveryFailure('ak-1'), 'no mark was standing');
    }

    /**
     * A window asked for after a failure lands on a scope the client wiped, so the page goes out
     * whole first and the window follows it.
     */
    public function testAWindowAskedForAfterAFailureFollowsTheWholePage(): void
    {
        $this->flush(broken: true);
        $this->drain();
        Hilos::$table = new DeliveryErrorWindowTableContext();
        Hilos::$table->configure();
        $context = new DeliveryErrorContext();
        $resender = new DeliveryErrorRecordingResender(PageResendOutcome::Answered);
        $context->bindPageResender($resender);

        $context->sendTableWindow(
            DeliveryErrorContext::PAGE,
            'ak-1',
            new TableViewportSubscription(tableKey: DeliveryErrorWindowTable::TABLE, limit: 10),
        );

        $this->assertCount(1, $resender->calls);
        $this->assertSame([SignalTypeConstants::PAGE_RESPONSE, SignalTypeConstants::TABLE_WINDOW], $this->drain());
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
     * @param ?PageResender $resender Who re-sends the page whole, or null for nobody bound
     */
    private function flush(bool $broken, ?PageResender $resender = null): void
    {
        $context = new DeliveryErrorContext($broken);
        if ($resender !== null) {
            $context->bindPageResender($resender);
        }
        $context->record(SourceChange::rtUpdated(DeliveryErrorRtContext::ROWS, '1', ['name' => 'Ada']));
        $context->flushToSignalRouter();
    }

    /**
     * Empties the signal queue so the next assertion reads only what the next flush queued.
     *
     * @return list<string> Names of the drained signals, oldest first
     */
    private function drain(): array
    {
        $names = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $names[] = $signal->signalName->getName();
        }

        return $names;
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
 * Stands for the worker: records every page it is asked to re-send, and puts on the wire what the
 * page's router would have sent for the outcome it was built with.
 */
final class DeliveryErrorRecordingResender implements PageResender
{
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> Page, connection and params of each re-send asked for */
    public array $calls = [];

    /**
     * @param PageResendOutcome $outcome What every re-send comes back with
     */
    public function __construct(private readonly PageResendOutcome $outcome)
    {
    }

    /**
     * @param string $page Page the subscription stands on
     * @param string $acceptKey Connection the page is owed to
     * @param array<string, mixed> $params Route params of the subscription
     * @return PageResendOutcome The outcome the fixture was built with
     * @throws InvalidArgumentException When a queued frame cannot be named
     */
    public function resendPage(string $page, string $acceptKey, array $params): PageResendOutcome
    {
        $this->calls[] = [$page, $acceptKey, $params];
        $frame = match ($this->outcome) {
            PageResendOutcome::Answered => [SignalTypeConstants::PAGE_RESPONSE, new PageResponseSignalData($page, new PagePayload())],
            PageResendOutcome::Refused => [
                SignalConstants::SUBSCRIPTION_PAGE_ERROR,
                new PageSubscriptionErrorSignalData($page, 401, 'unauthorized', 'Refused'),
            ],
            PageResendOutcome::Failed => [
                SignalConstants::SUBSCRIPTION_PAGE_ERROR,
                new PageSubscriptionErrorSignalData($page, 500, 'internal_error', 'Failed'),
            ],
            PageResendOutcome::Unserved => null,
        };
        if ($frame !== null) {
            Hilos::$sr?->queueSignal(
                signalSource: new SignalSource(SignalSource::WORKER),
                signalType: new SignalType(SignalTypeConstants::WS_USER),
                signalName: new SignalName($frame[0]),
                signalData: new WebSocketSignalData(data: $frame[1], targetAcceptKey: $acceptKey),
            );
        }

        return $this->outcome;
    }
}

final class DeliveryErrorWindowTableContext extends TableContext
{
    public function configure(): void
    {
        $this->register(DeliveryErrorWindowTable::TABLE, new DeliveryErrorWindowTable());
    }
}

/**
 * Table answering a window over one row held in memory.
 */
final class DeliveryErrorWindowTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'deliveryErrorWindow';

    /**
     * No source-change reaction in this fixture.
     *
     * @param SourceChange $change Source change (unused)
     * @return ?TableRowMutationDTO Always null
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        return null;
    }

    /**
     * Serializes a row into its internal browser-row envelope.
     *
     * @param AbstractTableRow $row Self-snapshot row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [self::TABLE => $row->toArray()],
        ];
    }

    /**
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory([['id' => 'a']], $query);
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
