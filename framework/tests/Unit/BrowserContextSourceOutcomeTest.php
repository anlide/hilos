<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserGuardKey;
use Hilos\Core\Browser\Config\BrowserGuardType;
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
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\Exception\PageResourceNotFoundException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Exception\View\CollectionNotFoundException;
use Hilos\Hilos;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotReadableException;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * The two answers a browser source used to give as one (HIL-575).
 *
 * "No collection of that name" and "that collection would not be read" both left
 * {@see BrowserContext} as null, and every consumer above read the null as the first one. So a
 * page whose data was refused looked exactly like a page whose data had not been mounted yet:
 * an empty table on the wire, no line anywhere, and no way for the subscriber or the operator
 * to tell a project that is still wiring itself up from one that is wired wrong.
 *
 * They are not the same state and they do not resolve the same way. "Not mounted" is a moment
 * a starting project passes through and the page fills in by itself. "Refused" is a defect
 * that stays refused until somebody declares the reader, and answering it with an empty page
 * hides it for as long as it lasts.
 *
 * These cases pin both halves: the first outcome is still the quiet null it always was, and
 * the second one now leaves by the throw at every door the page reaches its rows through — the
 * snapshot, and the guard that decides whether the page may be seen at all.
 */
final class BrowserContextSourceOutcomeTest extends TestCase
{
    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$sr->subscribeToPage(
            SourceOutcomeContext::PAGE,
            new WebSocketPageSubscribeSignalDTO('ak-1', SourceOutcomeContext::PAGE),
        );
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$db = null;
        Hilos::$rt = null;
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testADatabaseCollectionNobodyMountedYetIsAnEmptyTable(): void
    {
        Hilos::$db = new SourceOutcomeDbContext(new CollectionNotFoundException('Db collection does not exist'));

        (new SourceOutcomeContext())->subscribeSnapshot(SourceOutcomeContext::PAGE, 'ak-1', new PageRouteParams([]));

        $this->assertDeliveredAnEmptyTable(SourceOutcomeContext::PAGE, SourceOutcomeContext::TABLE);
    }

    /**
     * The runtime half of the same answer. Both species are named in the one catch that
     * survived, so a leaf that later drops one of them fails here rather than in a demo.
     */
    public function testARuntimeCollectionNobodyMountedYetIsAnEmptyTable(): void
    {
        Hilos::$rt = new SourceOutcomeRtContext(new RtCollectionNotFoundException('Runtime collection does not exist'));

        (new SourceOutcomeRtPageContext())->subscribeSnapshot(
            SourceOutcomeRtPageContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        $this->assertDeliveredAnEmptyTable(SourceOutcomeRtPageContext::PAGE, SourceOutcomeRtPageContext::TABLE);
    }

    public function testADatabaseCollectionThatRefusedTheReadDoesNotBecomeAnEmptyTable(): void
    {
        Hilos::$db = new SourceOutcomeDbContext(new DbCollectionNotReadableException('nobody reads it here'));

        $this->expectException(DbCollectionNotReadableException::class);

        (new SourceOutcomeContext())->subscribeSnapshot(SourceOutcomeContext::PAGE, 'ak-1', new PageRouteParams([]));
    }

    public function testARuntimeCollectionThatRefusedTheReadDoesNotBecomeAnEmptyTable(): void
    {
        Hilos::$rt = new SourceOutcomeRtContext(new RtCollectionNotReadableException('nobody reads it here'));

        $this->expectException(RtCollectionNotReadableException::class);

        (new SourceOutcomeRtPageContext())->subscribeSnapshot(
            SourceOutcomeRtPageContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );
    }

    /**
     * The door the incident came through: a guard reads the same source, and on the old single
     * answer a refused read reached the subscriber as 404 — a verdict about a resource, reached
     * without ever looking at one.
     */
    public function testAGuardWhoseCollectionIsMissingStillRefusesTheResource(): void
    {
        Hilos::$db = new SourceOutcomeDbContext(new CollectionNotFoundException('Db collection does not exist'));

        $this->expectException(PageResourceNotFoundException::class);

        (new SourceOutcomeGuardedContext())->assertSubscriptionAccess(
            SourceOutcomeGuardedContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );
    }

    public function testAGuardWhoseReadWasRefusedDoesNotAnswerForTheResource(): void
    {
        Hilos::$db = new SourceOutcomeDbContext(new DbCollectionNotReadableException('nobody reads it here'));

        $this->expectException(DbCollectionNotReadableException::class);

        (new SourceOutcomeGuardedContext())->assertSubscriptionAccess(
            SourceOutcomeGuardedContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );
    }

    /**
     * The field read has the same two answers, and the same reason to keep them apart.
     *
     * A field an item does not carry is answered out of `toArray()`, which is how a view item
     * publishes more than its own getter lists. That fallback stays.
     */
    public function testAFieldTheGetterDoesNotListIsStillAnsweredFromTheRow(): void
    {
        $this->bootLiveRuntime();

        (new SourceOutcomeFieldContext())->subscribeSnapshot(
            SourceOutcomeFieldContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame(
            [SourceOutcomeFieldContext::TABLE => [BrowserPageSignalData::rows => [[
                PagePayload::rowKey => '1',
                PagePayload::slots => [SourceOutcomeLiveRtContext::ROWS => ['id' => '1', 'label' => 'Ada']],
            ]]]],
            $signal->data->data->payload->tables,
        );
    }

    /**
     * A field whose read was refused is not a field the item does not carry, and answering it
     * out of the row — or with null — would file a broken read under a missing name.
     */
    public function testAFieldWhoseReadWasRefusedIsNotAnsweredAtAll(): void
    {
        $this->bootLiveRuntime();

        $this->expectException(RtCollectionNotReadableException::class);

        (new SourceOutcomeRefusedFieldContext())->subscribeSnapshot(
            SourceOutcomeRefusedFieldContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );
    }

    /**
     * Mounts one runtime collection holding one row, for the two field cases above.
     */
    private function bootLiveRuntime(): void
    {
        $runtime = new SourceOutcomeLiveRtContext();
        $runtime->configure();
        $runtime->addRow(SourceOutcomeState::create('1', 'Ada'));
        Hilos::$rt = $runtime;
    }

    /**
     * Asserts the subscriber was served the page with the named table present and holding no rows.
     *
     * Present and empty rather than absent: "the project mounts it later" is a state the page is
     * legitimately in, and the client is meant to render the page around an empty table until it
     * fills in.
     *
     * @param string $page Page the snapshot answers for
     * @param string $browserKey Browser table key expected in the payload
     */
    private function assertDeliveredAnEmptyTable(string $page, string $browserKey): void
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal);
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
        $this->assertSame($page, $signal->data->data->pageKey);
        $this->assertSame([$browserKey => []], $signal->data->data->payload->tables);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }
}

/**
 * Serves one page off one database source, so the source's answer is the whole outcome.
 */
class SourceOutcomeContext extends BrowserContext
{
    public const string PAGE = 'source_outcome_page';
    public const string TABLE = 'sourceOutcomeRows';
    public const string COLLECTION = 'sourceOutcomeRows';

    protected const string SIGNAL = 'source_outcome_signal';

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Test page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return $page === static::PAGE
            ? BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => static::SIGNAL])
            : null;
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Test page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return $page === static::PAGE
            ? BrowserPageBindings::fromArray([static::TABLE => []])
            : BrowserPageBindings::empty();
    }

    /**
     * @param string $browserKey Browser table key
     * @return ?BrowserSourceConfig Test browser-only table config
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        if ($browserKey !== static::TABLE) {
            return null;
        }

        return BrowserSourceConfig::fromArray([
            BrowserTableConfigKey::ROWS => [[
                BrowserTableFieldKey::SOURCE => [
                    BrowserSourceKey::TYPE => static::sourceType(),
                    BrowserSourceKey::KEY => static::COLLECTION,
                ],
                BrowserTableFieldKey::ROW_KEY => 'id',
                BrowserTableFieldKey::FIELDS => static::fields(),
            ]],
        ]);
    }

    /**
     * @return list<string> Fields the fixture table declares
     */
    protected static function fields(): array
    {
        return ['id'];
    }

    /**
     * @return string Source type the fixture page reads its rows from
     */
    protected static function sourceType(): string
    {
        return BrowserSourceType::DB;
    }
}

/**
 * The same page, reading the runtime context instead.
 */
final class SourceOutcomeRtPageContext extends SourceOutcomeContext
{
    public const string PAGE = 'source_outcome_rt_page';
    public const string TABLE = 'sourceOutcomeRtRows';
    public const string COLLECTION = 'sourceOutcomeRtRows';

    protected const string SIGNAL = 'source_outcome_rt_signal';

    protected static function sourceType(): string
    {
        return BrowserSourceType::RT;
    }
}

/**
 * The same source, behind a DB_EXISTS guard rather than behind a table.
 */
final class SourceOutcomeGuardedContext extends SourceOutcomeContext
{
    public const string PAGE = 'source_outcome_guarded_page';

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
            BrowserConfigKey::GUARDS => [[
                BrowserGuardKey::TYPE => BrowserGuardType::DB_EXISTS,
                BrowserGuardKey::SOURCE => [
                    BrowserSourceKey::TYPE => BrowserSourceType::DB,
                    BrowserSourceKey::KEY => self::COLLECTION,
                ],
                BrowserGuardKey::KEY => 'alpha',
            ]],
        ]);
    }

    /**
     * The guard is the whole page here; no table competes with it for the same source.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Empty bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return BrowserPageBindings::empty();
    }
}

/**
 * A database context whose every read answers with the injected failure.
 */
final class SourceOutcomeDbContext extends HilosDbContext
{
    /**
     * @param CollectionNotFoundException|DbCollectionNotReadableException $failure Answer to every read
     */
    public function __construct(
        private readonly CollectionNotFoundException|DbCollectionNotReadableException $failure,
    ) {
        parent::__construct();
    }

    /**
     * @param string $name Collection name being read
     * @return never Never returns
     * @throws CollectionNotFoundException When the fixture stands in for an unmounted collection
     * @throws DbCollectionNotReadableException When the fixture stands in for a refused read
     */
    public function __get(string $name)
    {
        throw $this->failure;
    }

    public function configure(): void
    {
    }
}

/**
 * A runtime context whose every read answers with the injected failure.
 */
final class SourceOutcomeRtContext extends RtContext
{
    /**
     * @param RtCollectionNotFoundException|RtCollectionNotReadableException $failure Answer to every read
     */
    public function __construct(
        private readonly RtCollectionNotFoundException|RtCollectionNotReadableException $failure,
    ) {
        parent::__construct();
    }

    /**
     * @param string $name Collection name being read
     * @return never Never returns
     * @throws RtCollectionNotFoundException When the fixture stands in for an unmounted collection
     * @throws RtCollectionNotReadableException When the fixture stands in for a refused read
     */
    public function __get(string $name): never
    {
        throw $this->failure;
    }

    public function configure(): void
    {
    }
}

/**
 * Runtime context mounting one live collection, for the field-read cases.
 */
final class SourceOutcomeLiveRtContext extends RtContext
{
    public const string ROWS = 'sourceOutcomeLiveRows';

    public function configure(): void
    {
        $this->_stateCollections[self::ROWS] = SourceOutcomeStates::init();
        $this->setRepresent(self::ROWS, SourceOutcomeCollection::class);
    }

    /**
     * @param SourceOutcomeState $row Row to add to the collection
     */
    public function addRow(SourceOutcomeState $row): void
    {
        $this->_stateCollections[self::ROWS]->add($row);
    }
}

final class SourceOutcomeStates extends RtStates
{
    public const string STATE_CLASS = SourceOutcomeState::class;
}

final class SourceOutcomeState extends RtState
{
    private function __construct(
        public readonly string $id,
        public readonly string $label,
    ) {
        parent::__construct();
    }

    /**
     * @param string $id Row key
     * @param string $label Row label
     * @return self Row state
     */
    public static function create(string $id, string $label): self
    {
        return new self($id, $label);
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
        return new static((string)$row['id'], (string)$row['label']);
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label];
    }
}

final class SourceOutcomeCollection extends RtCollection
{
    /**
     * @param RtState $state Backing state
     * @return RtItem View item over the state
     */
    protected function createRtItem(RtState $state): RtItem
    {
        return new SourceOutcomeItem($state);
    }
}

/**
 * A view item that publishes `label` only through its row, and refuses one named field.
 */
final class SourceOutcomeItem extends RtItem
{
    /** Field whose read is refused, standing in for a collection this process may not read */
    public const string REFUSED_FIELD = 'refusedField';

    /**
     * @param string $name Field name
     * @return mixed Field value
     * @throws RtCollectionNotReadableException When the refused field is asked for
     * @throws RtItemPropertyNotFoundException When the item carries no such field
     */
    public function __get(string $name): mixed
    {
        if ($name === self::REFUSED_FIELD) {
            throw new RtCollectionNotReadableException('nobody reads it here');
        }

        // `label` deliberately absent: the getter lists the key alone, and the row below is
        // what answers for the rest.
        if ($name === 'id') {
            return $this->toArray()['id'];
        }

        return parent::__get($name);
    }

    /**
     * @return array<string, mixed> Row payload
     */
    public function toArray(): array
    {
        return $this->getState()->toArray();
    }
}

/**
 * Reads a field the item's getter does not list, so the row fallback is what answers.
 */
final class SourceOutcomeFieldContext extends SourceOutcomeContext
{
    public const string PAGE = 'source_outcome_field_page';
    public const string TABLE = 'sourceOutcomeFieldRows';
    public const string COLLECTION = SourceOutcomeLiveRtContext::ROWS;

    protected const string SIGNAL = 'source_outcome_field_signal';

    /**
     * @return list<string> Fields the fixture table declares
     */
    protected static function fields(): array
    {
        return ['id', 'label'];
    }

    protected static function sourceType(): string
    {
        return BrowserSourceType::RT;
    }
}

/**
 * The same table, declaring the one field whose read is refused.
 */
final class SourceOutcomeRefusedFieldContext extends SourceOutcomeContext
{
    public const string PAGE = 'source_outcome_refused_field_page';
    public const string TABLE = 'sourceOutcomeRefusedFieldRows';
    public const string COLLECTION = SourceOutcomeLiveRtContext::ROWS;

    protected const string SIGNAL = 'source_outcome_refused_field_signal';

    /**
     * @return list<string> Fields the fixture table declares
     */
    protected static function fields(): array
    {
        return ['id', SourceOutcomeItem::REFUSED_FIELD];
    }

    protected static function sourceType(): string
    {
        return BrowserSourceType::RT;
    }
}
