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
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\Worker\WorkerTickUnit;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\DTO\PageSubscriptionErrorSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * What a broken browser declaration costs on the reactive path (HIL-549).
 *
 * The declaration is static, so a mistake in it is not a bad request that goes away —
 * it is present on every single flush. Left to propagate, the throw would leave the
 * fan-out with nothing between it and `WorkerApplication`'s exit: the worker would go
 * down past its cleanup on every flush and be restarted into the same flush by
 * `ensureMinWorkers`, taking every other subscriber on that worker with it.
 *
 * So the fan-out traps it around ONE subscription. What is asserted is exactly that
 * blast radius: the healthy subscriber of the same flush still receives its rows, the
 * offending one receives nothing, and the flush itself completes.
 *
 * The trap was widened to any failure by HIL-574, which also moved the record out: the
 * fan-out names the subscription that failed and the worker's tick writes it down, so
 * the same failure is not described twice under two sets of rules.
 */
final class BrowserContextBrokenDeclarationFanoutTest extends TestCase
{
    /** @var ?BrokenDeclarationFanoutContext Context the last arranged flush ran on */
    private ?BrokenDeclarationFanoutContext $context = null;

    /** @var list<string> Accept keys the last drained queue told the page had failed */
    private array $refusedAcceptKeys = [];

    protected function tearDown(): void
    {
        $this->refusedAcceptKeys = [];
        Hilos::$rt = null;
        Hilos::$sr = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testBrokenPageDeclarationSilencesOnlyItsOwnSubscription(): void
    {
        $this->arrangeFlush(BrokenDeclarationFanoutContext::BROKEN_PAGE);

        $this->assertSame(['ak-healthy'], $this->deliveredAcceptKeys());
    }

    public function testBrokenSourceDeclarationSilencesOnlyItsOwnSubscription(): void
    {
        $this->arrangeFlush(BrokenDeclarationFanoutContext::BROKEN_SOURCE_PAGE);

        $this->assertSame(['ak-healthy'], $this->deliveredAcceptKeys());
    }

    /**
     * The boundary is not about broken declarations, it is about one subscription: a
     * failure of another family - here the keyless row the worker-tick leaf was written
     * for - costs its own subscriber and nobody else.
     */
    public function testAFailureOfAnotherFamilySilencesOnlyItsOwnSubscription(): void
    {
        $this->arrangeFlush(BrokenDeclarationFanoutContext::KEYLESS_ROW_PAGE);

        $this->assertSame(['ak-healthy'], $this->deliveredAcceptKeys());
    }

    /**
     * The fan-out writes nothing itself; it says which subscription failed and on what,
     * and the worker's tick is what puts that in the journal and offers it to the project.
     */
    public function testTheFlushNamesTheSubscriptionThatFailed(): void
    {
        $contained = $this->arrangeFlush(BrokenDeclarationFanoutContext::KEYLESS_ROW_PAGE);

        $this->assertCount(1, $contained);
        $this->assertSame(WorkerTickUnit::BROWSER_SUBSCRIPTION, $contained[0]->unit);
        $this->assertSame(
            'page=' . BrokenDeclarationFanoutContext::KEYLESS_ROW_PAGE . ' acceptKey=ak-broken',
            $contained[0]->address,
        );
        $this->assertInstanceOf(TableRowKeyMissingException::class, $contained[0]->failure);
    }

    /**
     * A change set kept because the fan-out failed is the same frame again on the next
     * tick, and on every tick after it.
     */
    public function testTheChangeSetIsDroppedEvenWhenASubscriptionFailed(): void
    {
        $this->arrangeFlush(BrokenDeclarationFanoutContext::KEYLESS_ROW_PAGE);

        $this->assertFalse($this->context?->hasChanges());
    }

    public function testTheBrokenDeclarationWouldOtherwiseReachTheWorker(): void
    {
        $this->expectException(PageInternalErrorException::class);

        BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => ['not', 'a', 'name']]);
    }

    /**
     * A guard the page declared wrongly reaches this trap now (HIL-575).
     *
     * It did not before: the guard check answered "denied" to a malformed declaration the same
     * way it answers it to a connection without the rights, so the flush saw a subscriber that
     * was simply not allowed anything. The subscriber's page went blank and stayed blank, and
     * this trap — written for exactly this mistake — never ran. What the assertion is really
     * about is that second half: the silence is contained AND named.
     */
    public function testABrokenGuardDeclarationReachesTheFanoutTrap(): void
    {
        $contained = $this->arrangeFlush(BrokenDeclarationFanoutContext::BROKEN_GUARD_PAGE);

        $this->assertSame(['ak-healthy'], $this->deliveredAcceptKeys());
        $this->assertSame(['ak-broken'], $this->refusedAcceptKeys);
        $this->assertCount(1, $contained);
        $this->assertSame(
            'page=' . BrokenDeclarationFanoutContext::BROKEN_GUARD_PAGE . ' acceptKey=ak-broken',
            $contained[0]->address,
        );
        $this->assertInstanceOf(PageInternalErrorException::class, $contained[0]->failure);
    }

    /**
     * A subscription that failed HALFWAY through a flush sends nothing it collected first.
     *
     * The one case where the give-up set has to reach the second loop as well: change one built
     * its rows, change two gave up, and the rows of the first are sitting in the payload table.
     * Queued behind the error frame they would land on the scope the client wiped, and taking
     * them out of the table is also what keeps the mark standing - the payload loop clears it,
     * so a page that went out here would have the next flush announce the same failure again.
     */
    public function testRowsCollectedBeforeTheFailureAreNotQueuedBehindTheErrorFrame(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new BrokenDeclarationFanoutRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->addRow(BrokenDeclarationFanoutState::create('1', 'Ada'));
        Hilos::$rt->addRow(BrokenDeclarationFanoutState::create('2', 'Grace'));

        Hilos::$sr->subscribeToPage(
            BrokenDeclarationFanoutContext::LATE_FAILURE_PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-broken', BrokenDeclarationFanoutContext::LATE_FAILURE_PAGE),
        );

        $this->context = new BrokenDeclarationFanoutContext();
        $this->context->record(SourceChange::rtUpdated(BrokenDeclarationFanoutRtContext::ROWS, '1', ['name' => 'Ada']));
        $this->context->record(SourceChange::rtUpdated(BrokenDeclarationFanoutRtContext::ROWS, '2', ['name' => 'Grace']));
        $contained = $this->context->flushToSignalRouter();

        $this->assertCount(1, $contained);
        $this->assertSame([], $this->deliveredAcceptKeys());
        $this->assertSame(['ak-broken'], $this->refusedAcceptKeys);
    }

    /**
     * One failure per subscription, not one per change (HIL-575).
     *
     * The cause is a declaration or the wiring, so every change of the same flush reaches it
     * again: without the give-up set, a flush carrying five changes would write five identical
     * contained failures and five identical refusal lines under them. The subscriber has also
     * been told by then that its page could not be delivered, and rows arriving after that
     * frame would deny it.
     */
    public function testASubscriptionThatFailedIsGivenUpOnForTheRestOfTheFlush(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new BrokenDeclarationFanoutRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->addRow(BrokenDeclarationFanoutState::create('1', 'Ada'));
        Hilos::$rt->addRow(BrokenDeclarationFanoutState::create('2', 'Grace'));

        Hilos::$sr->subscribeToPage(
            BrokenDeclarationFanoutContext::BROKEN_GUARD_PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-broken', BrokenDeclarationFanoutContext::BROKEN_GUARD_PAGE),
        );

        $this->context = new BrokenDeclarationFanoutContext();
        $this->context->record(SourceChange::rtUpdated(BrokenDeclarationFanoutRtContext::ROWS, '1', ['name' => 'Ada']));
        $this->context->record(SourceChange::rtUpdated(BrokenDeclarationFanoutRtContext::ROWS, '2', ['name' => 'Grace']));
        $contained = $this->context->flushToSignalRouter();

        $this->assertCount(1, $contained);
    }

    /**
     * Subscribes one healthy connection and one to the named broken page, then flushes
     * a change both of them observe.
     *
     * @param string $brokenPage Page the flush is expected to fail on
     * @return list<ContainedFailure> Subscriptions the flush contained a failure for
     */
    private function arrangeFlush(string $brokenPage): array
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new BrokenDeclarationFanoutRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->addRow(BrokenDeclarationFanoutState::create('1', 'Ada'));

        Hilos::$sr->subscribeToPage(
            BrokenDeclarationFanoutContext::HEALTHY_PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-healthy', BrokenDeclarationFanoutContext::HEALTHY_PAGE),
        );
        Hilos::$sr->subscribeToPage(
            $brokenPage,
            new WebSocketPageSubscribeSignalDTO('ak-broken', $brokenPage),
        );

        $this->context = new BrokenDeclarationFanoutContext();
        $this->context->record(SourceChange::rtUpdated(BrokenDeclarationFanoutRtContext::ROWS, '1', ['name' => 'Ada']));

        return $this->context->flushToSignalRouter();
    }

    /**
     * Drains the queue, collecting who was served a page and who was told it failed.
     *
     * The two are read apart because since HIL-575 a contained failure is not silence: the
     * offending subscriber gets one error frame, and every assertion about the blast radius
     * still means "who received the page".
     *
     * @return list<string> Accept keys the flush queued a page response for, in queue order
     */
    private function deliveredAcceptKeys(): array
    {
        $acceptKeys = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
            if ($signal->signalName->getName() === SignalConstants::SUBSCRIPTION_PAGE_ERROR) {
                $this->assertInstanceOf(PageSubscriptionErrorSignalData::class, $signal->data->data);
                $this->refusedAcceptKeys[] = $signal->data->targetAcceptKey;

                continue;
            }

            $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
            $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            $acceptKeys[] = $signal->data->targetAcceptKey;
        }

        return $acceptKeys;
    }
}

/**
 * Serves five pages off one runtime collection: one declared correctly, one whose page
 * declaration names a non-signal, one whose table joins a second source that names no
 * collection, one whose rows are declared correctly and give up while being built, and one
 * whose page-level guard names a type the framework does not know.
 */
final class BrokenDeclarationFanoutContext extends BrowserContext
{
    public const string HEALTHY_PAGE = 'broken_fanout_healthy_page';
    public const string BROKEN_PAGE = 'broken_fanout_broken_page';
    public const string BROKEN_SOURCE_PAGE = 'broken_fanout_broken_source_page';
    public const string KEYLESS_ROW_PAGE = 'broken_fanout_keyless_row_page';
    public const string BROKEN_GUARD_PAGE = 'broken_fanout_broken_guard_page';
    public const string LATE_FAILURE_PAGE = 'broken_fanout_late_failure_page';

    public const string HEALTHY_TABLE = 'healthyRows';
    public const string BROKEN_SOURCE_TABLE = 'brokenSourceRows';
    public const string KEYLESS_ROW_TABLE = 'keylessRows';
    public const string LATE_FAILURE_TABLE = 'lateFailureRows';

    /** Row key the late-failure table builds; every other key gives up. */
    public const string LATE_FAILURE_SURVIVING_KEY = '1';

    /** Computed field that gives up the way a placeholder row reaching a window does */
    public const string KEYLESS_FIELD = 'keylessField';

    private const string SIGNAL = 'broken_fanout_signal';

    private const array SOURCE = [
        BrowserSourceKey::TYPE => BrowserSourceType::RT,
        BrowserSourceKey::KEY => BrokenDeclarationFanoutRtContext::ROWS,
    ];

    /** A joined source that names no collection at all — the mistake under test. */
    private const array KEYLESS_SOURCE = [BrowserSourceKey::TYPE => BrowserSourceType::RT];

    /**
     * Gives up on the keyless table's rows, the way a placeholder row that reached a
     * window has no key to be addressed by.
     *
     * A computed field because that is where a fan-out runs code that can raise anything
     * at all: an ordinary field read is swallowed by the projector, and the exception
     * class here is the one the worker-tick leaf was written for.
     *
     * @param string $browserKey Browser table key
     * @param string $field Computed field name
     * @param int|string $rowKey Row key inside the table
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return mixed Never returns for the keyless table
     * @throws TableRowKeyMissingException When the keyless table's row is being built
     */
    protected function computeBrowserField(
        string $browserKey,
        string $field,
        int|string $rowKey,
        string $acceptKey,
        array $pageParams,
        array $browserParams,
        array $sources,
    ): mixed {
        if ($browserKey === self::KEYLESS_ROW_TABLE) {
            throw new TableRowKeyMissingException(self::class);
        }

        // The late-failure table builds its first row and gives up on the second, which is the
        // only way to arrange a flush that collected something before it failed.
        if ($browserKey === self::LATE_FAILURE_TABLE && (string) $rowKey !== self::LATE_FAILURE_SURVIVING_KEY) {
            throw new TableRowKeyMissingException(self::class);
        }

        return null;
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Test page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return match ($page) {
            self::HEALTHY_PAGE,
            self::BROKEN_SOURCE_PAGE,
            self::KEYLESS_ROW_PAGE,
            self::LATE_FAILURE_PAGE => BrowserPageConfig::fromArray([
                BrowserConfigKey::SIGNAL => self::SIGNAL,
            ]),
            self::BROKEN_PAGE => BrowserPageConfig::fromArray([
                BrowserConfigKey::SIGNAL => ['not', 'a', 'name'],
            ]),
            // Named correctly all the way down to the guard, which names a type nothing
            // implements: the page is readable, its own gate is not.
            self::BROKEN_GUARD_PAGE => BrowserPageConfig::fromArray([
                BrowserConfigKey::SIGNAL => self::SIGNAL,
                BrowserConfigKey::GUARDS => [[BrowserGuardKey::TYPE => 'no_such_guard_type']],
            ]),
            default => null,
        };
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Test page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return match ($page) {
            self::HEALTHY_PAGE, self::BROKEN_PAGE, self::BROKEN_GUARD_PAGE => BrowserPageBindings::fromArray([
                self::HEALTHY_TABLE => [],
            ]),
            self::BROKEN_SOURCE_PAGE => BrowserPageBindings::fromArray([self::BROKEN_SOURCE_TABLE => []]),
            self::KEYLESS_ROW_PAGE => BrowserPageBindings::fromArray([self::KEYLESS_ROW_TABLE => []]),
            self::LATE_FAILURE_PAGE => BrowserPageBindings::fromArray([self::LATE_FAILURE_TABLE => []]),
            default => BrowserPageBindings::empty(),
        };
    }

    /**
     * @param string $browserKey Browser table key
     * @return ?BrowserSourceConfig Test browser-only table config
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        $healthyRow = [
            BrowserTableFieldKey::SOURCE => self::SOURCE,
            BrowserTableFieldKey::ROW_KEY => 'id',
            BrowserTableFieldKey::FIELDS => ['id', 'name'],
        ];

        return match ($browserKey) {
            self::HEALTHY_TABLE => BrowserSourceConfig::fromArray([
                BrowserTableConfigKey::ROWS => [$healthyRow],
            ]),
            // Declared correctly in every way; the computed field below is what gives up
            // while the row is being built.
            self::KEYLESS_ROW_TABLE => BrowserSourceConfig::fromArray([
                BrowserTableConfigKey::ROWS => [
                    [
                        BrowserTableFieldKey::SOURCE => self::SOURCE,
                        BrowserTableFieldKey::ROW_KEY => 'id',
                        BrowserTableFieldKey::FIELDS => ['id', 'name'],
                        BrowserTableFieldKey::COMPUTED => [self::KEYLESS_FIELD],
                    ],
                ],
            ]),
            self::LATE_FAILURE_TABLE => BrowserSourceConfig::fromArray([
                BrowserTableConfigKey::ROWS => [
                    [
                        BrowserTableFieldKey::SOURCE => self::SOURCE,
                        BrowserTableFieldKey::ROW_KEY => 'id',
                        BrowserTableFieldKey::FIELDS => ['id', 'name'],
                        BrowserTableFieldKey::COMPUTED => [self::KEYLESS_FIELD],
                    ],
                ],
            ]),
            // The first row source is well-formed, so the change is observed and the row
            // is built; the join below is what the build then trips over.
            self::BROKEN_SOURCE_TABLE => BrowserSourceConfig::fromArray([
                BrowserTableConfigKey::ROWS => [
                    $healthyRow,
                    [
                        BrowserTableFieldKey::SOURCE => self::KEYLESS_SOURCE,
                        BrowserTableFieldKey::ROW_KEY => 'id',
                        BrowserTableFieldKey::FIELDS => ['id'],
                    ],
                ],
            ]),
            default => null,
        };
    }
}

/**
 * Runtime context holding the one collection both tables read.
 */
final class BrokenDeclarationFanoutRtContext extends RtContext
{
    public const string ROWS = 'brokenFanoutRows';

    public function configure(): void
    {
        $this->_stateCollections[self::ROWS] = BrokenDeclarationFanoutStates::init();
        $this->setRepresent(self::ROWS, BrokenDeclarationFanoutCollection::class);
    }

    /**
     * @param BrokenDeclarationFanoutState $row Row to add to the collection
     */
    public function addRow(BrokenDeclarationFanoutState $row): void
    {
        $this->_stateCollections[self::ROWS]->add($row);
    }
}

final class BrokenDeclarationFanoutStates extends RtStates
{
    public const string STATE_CLASS = BrokenDeclarationFanoutState::class;
}

final class BrokenDeclarationFanoutState extends RtState
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

final class BrokenDeclarationFanoutCollection extends RtCollection
{
    /**
     * @param RtState $state Backing state
     * @return RtItem View item over the state
     */
    protected function createRtItem(RtState $state): RtItem
    {
        return new BrokenDeclarationFanoutItem($state);
    }
}

final class BrokenDeclarationFanoutItem extends RtItem
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
