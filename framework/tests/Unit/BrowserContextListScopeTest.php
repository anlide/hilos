<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Scoped LIST deltas address current and former anchor owners, including every queued deletion. */
final class BrowserContextListScopeTest extends TestCase
{
    private const array SUBSCRIBERS = ['a-one' => '1', 'a-two' => '1', 'b-one' => '2', 'c-one' => '3'];

    private ListScopeBrowserContext $context;
    private string $kind;
    private string $vocabulary;
    private string $list;

    /** @var array<string, array<int, array<string, mixed>>> Source rows private to this test invocation */
    private array $rows;

    /** @return iterable<string, array{string, string}> Source kinds and mutation dictionaries */
    public static function sourceCases(): iterable
    {
        yield 'DB columns' => [SourceChange::KIND_DB, 'columns'];
        yield 'DB objects' => [SourceChange::KIND_DB, 'objects'];
        yield 'RT properties' => [SourceChange::KIND_RT, 'objects'];
    }

    /**
     * @param string $kind DB or RT anchor and joined sources
     * @param string $vocabulary Changed-value dictionary
     */
    #[DataProvider('sourceCases')]
    public function testAnchorLifecycleReachesOnlyItsSubscribers(string $kind, string $vocabulary): void
    {
        $this->subscribe($kind, $vocabulary);
        $created = $this->anchor(104, 104, 1, 'created');
        $this->rows[ListScopeSources::ANCHORS][104] = $created;
        $this->assertDelivery($this->change(ListScopeSources::ANCHORS, 104, TableMutationType::Create, $created), [
            'a-one' => [PagePayload::items => [$this->item($created)]],
            'a-two' => [PagePayload::items => [$this->item($created)]],
        ]);

        $updated = $this->rows[ListScopeSources::ANCHORS][104] = array_replace($created, ['label' => 'updated']);
        $this->assertDelivery($this->change(ListScopeSources::ANCHORS, 104, TableMutationType::Update, ['label' => 'updated']), [
            'a-one' => [PagePayload::items => [$this->item($updated)]],
            'a-two' => [PagePayload::items => [$this->item($updated)]],
        ]);

        unset($this->rows[ListScopeSources::ANCHORS][104]);
        $this->assertDelivery($this->change(ListScopeSources::ANCHORS, 104, TableMutationType::Delete, $updated), [
            'a-one' => [PagePayload::deleted => [104]],
            'a-two' => [PagePayload::deleted => [104]],
        ]);
    }

    /**
     * @param string $kind DB or RT anchor and joined sources
     * @param string $vocabulary Changed-value dictionary
     */
    #[DataProvider('sourceCases')]
    public function testWhereMoveUsesPartialPreviousAndTheUnchangedWhereField(string $kind, string $vocabulary): void
    {
        $this->subscribe($kind, $vocabulary);
        $moved = $this->rows[ListScopeSources::ANCHORS][101] = $this->anchor(101, 101, 2);
        $this->assertDelivery($this->change(
            ListScopeSources::ANCHORS, 101, TableMutationType::Update, ['ownerId' => 2], ['ownerId' => 1],
        ), [
            'a-one' => [PagePayload::deleted => [101]],
            'a-two' => [PagePayload::deleted => [101]],
            'b-one' => [PagePayload::items => [$this->item($moved)]],
        ]);

        $this->rows[ListScopeSources::ANCHORS][401]['ownerId'] = 2;
        $this->assertDelivery($this->change(
            ListScopeSources::ANCHORS, 401, TableMutationType::Update, ['ownerId' => 2], ['ownerId' => 1],
        ), []);
    }

    /**
     * @param string $kind DB or RT anchor and joined sources
     * @param string $vocabulary Changed-value dictionary
     */
    #[DataProvider('sourceCases')]
    public function testManyLifecycleRebuildsOnlyTheMatchingAnchor(string $kind, string $vocabulary): void
    {
        $this->subscribe($kind, $vocabulary);
        $child = ['id' => 901, 'anchorKey' => 101, 'label' => 'child'];
        $this->rows[ListScopeSources::CHILDREN][901] = $child;
        $this->assertDelivery($this->change(ListScopeSources::CHILDREN, 901, TableMutationType::Create, $child), [
            'a-one' => [PagePayload::items => [$this->item($this->anchor(101, 101, 1), [$child])]],
            'a-two' => [PagePayload::items => [$this->item($this->anchor(101, 101, 1), [$child])]],
        ]);

        $child['label'] = 'updated child';
        $this->rows[ListScopeSources::CHILDREN][901] = $child;
        $this->assertDelivery($this->change(ListScopeSources::CHILDREN, 901, TableMutationType::Update, ['label' => 'updated child']), [
            'a-one' => [PagePayload::items => [$this->item($this->anchor(101, 101, 1), [$child])]],
            'a-two' => [PagePayload::items => [$this->item($this->anchor(101, 101, 1), [$child])]],
        ]);

        unset($this->rows[ListScopeSources::CHILDREN][901]);
        $this->assertDelivery($this->change(ListScopeSources::CHILDREN, 901, TableMutationType::Delete, $child), [
            'a-one' => [PagePayload::items => [$this->item($this->anchor(101, 101, 1))]],
            'a-two' => [PagePayload::items => [$this->item($this->anchor(101, 101, 1))]],
        ]);
    }

    /**
     * @param string $kind DB or RT anchor and joined sources
     * @param string $vocabulary Changed-value dictionary
     */
    #[DataProvider('sourceCases')]
    public function testManyMoveUpdatesBothSlotsAndLeavesTheThirdSubscriberAlone(string $kind, string $vocabulary): void
    {
        $this->subscribe($kind, $vocabulary, withChild: true);
        $child = $this->rows[ListScopeSources::CHILDREN][901] = ['id' => 901, 'anchorKey' => 201, 'label' => 'child'];
        $this->assertDelivery($this->change(
            ListScopeSources::CHILDREN, 901, TableMutationType::Update, ['anchorKey' => 201], ['anchorKey' => 101],
        ), [
            'a-one' => [PagePayload::items => [$this->item($this->anchor(101, 101, 1))]],
            'a-two' => [PagePayload::items => [$this->item($this->anchor(101, 101, 1))]],
            'b-one' => [PagePayload::items => [$this->item($this->anchor(201, 201, 2), [$child])]],
        ]);
    }

    /**
     * @param string $kind DB or RT anchor and joined sources
     * @param string $vocabulary Changed-value dictionary
     */
    #[DataProvider('sourceCases')]
    public function testDeletedAliasUsesLogicalItemKeyDespiteAForeignKeyEqualToStorageId(string $kind, string $vocabulary): void
    {
        $this->subscribe($kind, $vocabulary, aliased: true);
        unset($this->rows[ListScopeSources::ANCHORS][201]);
        $this->assertDelivery($this->change(ListScopeSources::ANCHORS, 201, TableMutationType::Delete, $this->anchor(201, 101, 1)), [
            'a-one' => [PagePayload::deleted => [101]],
            'a-two' => [PagePayload::deleted => [101]],
        ]);
    }

    /**
     * @param string $kind DB or RT anchor and joined sources
     * @param string $vocabulary Changed-value dictionary
     */
    #[DataProvider('sourceCases')]
    public function testLogicalKeyMoveDeletesTheOldKeyAndAddsTheNewKeyOnlyInItsScope(string $kind, string $vocabulary): void
    {
        $this->subscribe($kind, $vocabulary, aliased: true);
        $moved = $this->rows[ListScopeSources::ANCHORS][201] = $this->anchor(201, 501, 1);
        $this->assertDelivery($this->change(
            ListScopeSources::ANCHORS, 201, TableMutationType::Update, ['logicalKey' => 501], ['logicalKey' => 101],
        ), [
            'a-one' => [PagePayload::items => [$this->item($moved)], PagePayload::deleted => [101]],
            'a-two' => [PagePayload::items => [$this->item($moved)], PagePayload::deleted => [101]],
        ]);
    }

    /**
     * @param string $kind DB or RT anchor and joined sources
     * @param string $vocabulary Changed-value dictionary
     */
    #[DataProvider('sourceCases')]
    public function testListWithoutWhereKeepsItsSharedItemsAndDeletions(string $kind, string $vocabulary): void
    {
        $this->subscribe($kind, $vocabulary, unscoped: true);
        $updated = $this->rows[ListScopeSources::ANCHORS][101] = $this->anchor(101, 101, 1, 'updated');
        $section = [PagePayload::items => [$this->item($updated)]];
        $this->assertDelivery($this->change(ListScopeSources::ANCHORS, 101, TableMutationType::Update, ['label' => 'updated']), [
            'a-one' => $section, 'a-two' => $section, 'b-one' => $section, 'c-one' => $section,
        ]);
        unset($this->rows[ListScopeSources::ANCHORS][101]);
        $this->assertDelivery($this->change(ListScopeSources::ANCHORS, 101, TableMutationType::Delete, $updated), [
            'a-one' => [PagePayload::deleted => [101]], 'a-two' => [PagePayload::deleted => [101]],
            'b-one' => [PagePayload::deleted => [101]], 'c-one' => [PagePayload::deleted => [101]],
        ]);
    }

    /**
     * @param string $kind DB or RT anchor and joined sources
     * @param string $vocabulary Changed-value dictionary
     */
    #[DataProvider('sourceCases')]
    public function testClearRemainsOneCollectionWideMarker(string $kind, string $vocabulary): void
    {
        $this->subscribe($kind, $vocabulary);
        $this->rows[ListScopeSources::ANCHORS] = [];
        $this->assertDelivery(new SourceChange($kind, ListScopeSources::ANCHORS, '', TableMutationType::Clear), [
            'a-one' => [PagePayload::cleared => true], 'a-two' => [PagePayload::cleared => true],
            'b-one' => [PagePayload::cleared => true], 'c-one' => [PagePayload::cleared => true],
        ]);
    }

    /** Release the fixture's globals and reader interests. */
    protected function tearDown(): void
    {
        foreach ([ListScopeSources::ANCHORS, ListScopeSources::CHILDREN] as $source) {
            SourceInterestRegistry::releaseConsumer(SourceConsumer::feature($source));
        }
        Hilos::$db = null;
        Hilos::$rt = null;
        Hilos::$sr = null;
        Hilos::resetBrowser();
        parent::tearDown();
    }

    /**
     * @param string $kind Source kind to exercise
     * @param string $vocabulary Changed-value dictionary
     * @param bool $withChild Whether the first snapshot contains a joined child
     * @param bool $aliased Whether storage id 201 holds logical item 101, while another anchor holds logical item 201
     * @param bool $unscoped Whether the list has no WHERE
     */
    private function subscribe(
        string $kind,
        string $vocabulary,
        bool $withChild = false,
        bool $aliased = false,
        bool $unscoped = false,
    ): void {
        $this->kind = $kind;
        $this->vocabulary = $vocabulary;
        $this->list = match (true) {
            $kind === SourceChange::KIND_DB && $unscoped => ListScopeDbUnscopedList::LIST,
            $kind === SourceChange::KIND_DB => ListScopeDbList::LIST,
            $unscoped => ListScopeRtUnscopedList::LIST,
            default => ListScopeRtList::LIST,
        };
        $first = $this->anchor($aliased ? 201 : 101, 101, 1);
        $second = $this->anchor($aliased ? 202 : 201, 201, 2);
        $third = $this->anchor(301, 301, 3);
        $hidden = $this->anchor(401, 401, 1, 'anchor', 'hidden');
        $child = ['id' => 901, 'anchorKey' => 101, 'label' => 'child'];
        $this->rows = [
            ListScopeSources::ANCHORS => array_column([$first, $second, $third, $hidden], null, 'id'),
            ListScopeSources::CHILDREN => $withChild ? [901 => $child] : [],
        ];
        $this->mountSources();
        Hilos::$sr = new SignalRouter();
        $this->context = new ListScopeBrowserContext($this->list);
        $this->context->bindHilosFacade(ListScopeHilos::class);
        foreach (self::SUBSCRIBERS as $acceptKey => $ownerId) {
            Hilos::$sr->subscribeToPage(
                ListScopeBrowserContext::PAGE,
                new WebSocketPageSubscribeSignalDTO($acceptKey, ListScopeBrowserContext::PAGE, ['ownerId' => $ownerId]),
            );
            $snapshot = $this->context->buildSubscribeSnapshot(
                ListScopeBrowserContext::PAGE, $acceptKey, new PageRouteParams(['ownerId' => $ownerId]),
            )->toArray();
            $firstItem = $this->item($first, $withChild ? [$child] : []);
            $expected = $unscoped ? [$firstItem, $this->item($second), $this->item($third), $this->item($hidden)] : match ($ownerId) {
                '1' => [$firstItem], '2' => [$this->item($second)], '3' => [$this->item($third)],
            };
            self::assertSame([PagePayload::lists => [$this->list => [PagePayload::items => $expected]]], $snapshot);
        }
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * Replaces only fixture storage before the real browser resolves a source fact.
     * DB collections start cold so Entity reads see the fake table's new contents without simulating DB sync.
     */
    private function mountSources(): void
    {
        if ($this->kind === SourceChange::KIND_DB) {
            ListScopeAnchorEntity::reset($this->dictionaryRows($this->rows[ListScopeSources::ANCHORS]));
            ListScopeChildEntity::reset($this->dictionaryRows($this->rows[ListScopeSources::CHILDREN]));
            Hilos::$db = new ListScopeDbContext();
            Hilos::$db->configure();
            foreach ([ListScopeSources::ANCHORS, ListScopeSources::CHILDREN] as $source) {
                SourceInterestRegistry::register(SourceChange::KIND_DB, $source, SourceConsumer::feature($source));
            }
        } else {
            Hilos::$rt = new ListScopeRtContext($this->rows);
            Hilos::$rt->configure();
        }
    }

    /**
     * @param string $source Collection to mutate
     * @param int $id Storage key
     * @param TableMutationType $mutation Mutation to publish
     * @param array<string, mixed> $row Full create/delete row or partial update
     * @param array<string, mixed> $previous Partial previous update values
     * @return SourceChange The same mutation dictionaries as production DB/RT sync
     */
    private function change(string $source, int $id, TableMutationType $mutation, array $row, array $previous = []): SourceChange
    {
        $columns = $this->kind === SourceChange::KIND_DB && $this->vocabulary === 'columns' && $mutation !== TableMutationType::Delete;
        return new SourceChange(
            $this->kind, $source, (string) $id, $mutation,
            $columns ? $this->columnValues($row) : $row,
            $columns ? $this->columnValues($previous) : $previous,
        );
    }

    /**
     * @param SourceChange $change Source fact after mutating fixture storage
     * @param array<string, array<string, mixed>> $expected Exact list sections by every actual addressee
     */
    private function assertDelivery(SourceChange $change, array $expected): void
    {
        $this->mountSources();
        $this->context->record($change);
        self::assertSame([], $this->context->flushToSignalRouter());
        $sent = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
            self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
            self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);
            self::assertSame(ListScopeBrowserContext::PAGE, $signal->data->data->pageKey);
            $acceptKey = $signal->data->targetAcceptKey;
            self::assertArrayNotHasKey($acceptKey, $sent, 'Each addressee gets exactly one page response per flush');
            $sent[$acceptKey] = $signal->data->data->payload->toArray();
        }
        ksort($sent);
        $payloads = [];
        foreach ($expected as $acceptKey => $section) {
            $payloads[$acceptKey] = [PagePayload::lists => [$this->list => $section]];
        }
        self::assertSame($payloads, $sent);
    }

    /**
     * @param int $id Storage primary key
     * @param int $logicalKey Declared LIST item key
     * @param int $ownerId Owner matched against the subscription
     * @param string $label Mutable field
     * @param string $groupCode Second WHERE field, unchanged in owner moves
     * @return array<string, mixed> Anchor object/property dictionary
     */
    private function anchor(int $id, int $logicalKey, int $ownerId, string $label = 'anchor', string $groupCode = 'visible'): array
    {
        return ['id' => $id, 'logicalKey' => $logicalKey, 'ownerId' => $ownerId, 'groupCode' => $groupCode, 'label' => $label];
    }

    /**
     * @param array<string, mixed> $anchor Expected anchor fields
     * @param list<array<string, mixed>> $children Expected MANY slot
     * @return array<string, mixed> Complete expected wire item
     */
    private function item(array $anchor, array $children = []): array
    {
        return [PagePayload::itemKey => $anchor['logicalKey'], PagePayload::slots => [
            ListScopeSources::ANCHORS => $anchor, ListScopeSources::CHILDREN => $children,
        ]];
    }

    /**
     * @param array<int, array<string, mixed>> $rows Fake DB rows in object vocabulary
     * @return list<array<string, mixed>> Fake table rows in entity vocabulary
     */
    private function dictionaryRows(array $rows): array
    {
        return array_values(array_map($this->columnValues(...), $rows));
    }

    /**
     * @param array<string, mixed> $values Object fields of a row or diff
     * @return array<string, mixed> Corresponding DB columns
     */
    private function columnValues(array $values): array
    {
        $columns = [];
        foreach ($values as $key => $value) {
            $columns[ListScopeObject::COLUMNS[$key] ?? $key] = $value;
        }
        return $columns;
    }
}

abstract class ListScopeHilos extends Hilos
{
    public const array BROWSER_LISTS = [
        ListScopeDbList::LIST => ListScopeDbList::class,
        ListScopeRtList::LIST => ListScopeRtList::class,
        ListScopeDbUnscopedList::LIST => ListScopeDbUnscopedList::class,
        ListScopeRtUnscopedList::LIST => ListScopeRtUnscopedList::class,
    ];
}

/** Shared fixture declarations; each concrete list still goes through the actual BROWSER_LISTS registry. */
final class ListScopeSources
{
    public const string ANCHORS = 'listScopeAnchors';
    public const string CHILDREN = 'listScopeChildren';
    public const array DB_ANCHORS = [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => self::ANCHORS];
    public const array DB_CHILDREN = [BrowserSourceKey::TYPE => BrowserSourceType::DB, BrowserSourceKey::KEY => self::CHILDREN];
    public const array RT_ANCHORS = [BrowserSourceKey::TYPE => BrowserSourceType::RT, BrowserSourceKey::KEY => self::ANCHORS];
    public const array RT_CHILDREN = [BrowserSourceKey::TYPE => BrowserSourceType::RT, BrowserSourceKey::KEY => self::CHILDREN];
    public const array ANCHOR = [
        BrowserListFieldKey::ITEM_KEY => 'logicalKey',
        BrowserListFieldKey::FIELDS => ['id', 'logicalKey', 'ownerId', 'groupCode', 'label'],
        BrowserListFieldKey::WHERE => [
            'ownerId' => [BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM, BrowserRefKey::KEY => 'ownerId'],
            'groupCode' => 'visible',
        ],
    ];
    public const array CHILD = [
        BrowserListFieldKey::ITEM_KEY => 'anchorKey',
        BrowserListFieldKey::MANY => true,
        BrowserListFieldKey::FIELDS => ['id', 'anchorKey', 'label'],
    ];
}

final class ListScopeDbList
{
    public const string LIST = 'scopedDbList';
    public const array BROWSER = [BrowserListConfigKey::ITEMS => [
        [...ListScopeSources::ANCHOR, BrowserListFieldKey::SOURCE => ListScopeSources::DB_ANCHORS],
        [...ListScopeSources::CHILD, BrowserListFieldKey::SOURCE => ListScopeSources::DB_CHILDREN],
    ]];
}

final class ListScopeRtList
{
    public const string LIST = 'scopedRtList';
    public const array BROWSER = [BrowserListConfigKey::ITEMS => [
        [...ListScopeSources::ANCHOR, BrowserListFieldKey::SOURCE => ListScopeSources::RT_ANCHORS],
        [...ListScopeSources::CHILD, BrowserListFieldKey::SOURCE => ListScopeSources::RT_CHILDREN],
    ]];
}

final class ListScopeDbUnscopedList
{
    public const string LIST = 'unscopedDbList';
    public const array BROWSER = [BrowserListConfigKey::ITEMS => [
        [...ListScopeSources::ANCHOR, BrowserListFieldKey::SOURCE => ListScopeSources::DB_ANCHORS, BrowserListFieldKey::WHERE => []],
        [...ListScopeSources::CHILD, BrowserListFieldKey::SOURCE => ListScopeSources::DB_CHILDREN],
    ]];
}

final class ListScopeRtUnscopedList
{
    public const string LIST = 'unscopedRtList';
    public const array BROWSER = [BrowserListConfigKey::ITEMS => [
        [...ListScopeSources::ANCHOR, BrowserListFieldKey::SOURCE => ListScopeSources::RT_ANCHORS, BrowserListFieldKey::WHERE => []],
        [...ListScopeSources::CHILD, BrowserListFieldKey::SOURCE => ListScopeSources::RT_CHILDREN],
    ]];
}

final class ListScopeBrowserContext extends BrowserContext
{
    public const string PAGE = 'list_scope_page';

    /** @param string $list Fixture list mounted through the facade */
    public function __construct(private readonly string $list)
    {
        parent::__construct();
    }

    /**
     * @param string $page Subscribed page
     * @return ?BrowserPageConfig Guard-less fixture page
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return $page === self::PAGE ? BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => 'list_scope_signal']) : null;
    }

    /**
     * @param string $page Subscribed page
     * @return BrowserPageBindings Owner route parameter bound into the LIST's WHERE
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return BrowserPageBindings::fromArray([$this->list => [BrowserParamKey::PARAMS => [
            'ownerId' => [BrowserRefKey::TYPE => BrowserRefType::PAGE_PARAM, BrowserRefKey::KEY => 'ownerId'],
        ]]]);
    }
}

/** In-memory Entity boundary; the production ORM resolves keys, columns, joins and snapshots above it. */
abstract class ListScopeEntity extends Entity
{
    /** @var array<class-string, array<int, array<string, mixed>>> Fake table storage, isolated by concrete Entity class */
    private static array $rows = [];

    /** @param list<array<string, mixed>> $rows Replacement fake table contents */
    public static function reset(array $rows): void
    {
        self::$rows[static::class] = array_column($rows, null, static::_primary);
    }

    /**
     * @param array<string, mixed>|string $filters Column equality or the ORM's column IN clause
     * @param array<int, mixed>|string $filtersParam IN clause values
     * @param array<string, string>|string $orderBy Unused for the fixture's sorted keys
     * @param int $limit Unused for this small fake table
     * @param int $offset Unused for this small fake table
     * @return EntityCollection Matching fake table rows
     */
    public static function get(
        array|string $filters = [],
        array|string $filtersParam = [],
        array|string $orderBy = [],
        int $limit = TableConstants::NO_LIMIT,
        int $offset = 0,
    ): EntityCollection {
        $entities = EntityCollection::empty();
        foreach (self::$rows[static::class] ?? [] as $id => $row) {
            if (is_array($filters)) {
                foreach ($filters as $column => $value) {
                    if ((string) $row[$column] !== (string) $value) {
                        continue 2;
                    }
                }
            } elseif ($filters !== '') {
                $column = trim(substr($filters, 0, (int) strpos($filters, ' ')), '`');
                if (!in_array((string) $row[$column], array_map(strval(...), $filtersParam), true)) {
                    continue;
                }
            }
            $entity = new static();
            foreach (static::_columns as $column) {
                $entity->{$column} = $row[$column];
            }
            $entity->flushRelated();
            $entities->add($entity, (string) $id);
        }
        return $entities;
    }

    /**
     * @param mixed $id Storage primary key
     * @return ?static Fake table row, if present
     */
    public static function getById(mixed $id): ?static
    {
        return static::get([static::_primary => $id])->first();
    }

    /**
     * @param array<string, mixed>|string $filters Column equality or IN clause
     * @param array<int, mixed>|string $filtersParam IN clause values
     * @return int Matching row count
     */
    public static function count(array|string $filters = [], array|string $filtersParam = []): int
    {
        return static::get($filters, $filtersParam)->count();
    }
}

final class ListScopeAnchorEntity extends ListScopeEntity
{
    public const string _table = 'list_scope_anchor';
    public const string _primary = 'id';
    public const array _columns = ['id', 'logical_key', 'owner_id', 'group_code', 'label'];
    public const array _types = [
        'id' => 'integer', 'logical_key' => 'integer', 'owner_id' => 'integer', 'group_code' => 'string', 'label' => 'string',
    ];

    public ?int $id = null;
    public ?int $logical_key = null;
    public ?int $owner_id = null;
    public ?string $group_code = null;
    public ?string $label = null;
}

final class ListScopeChildEntity extends ListScopeEntity
{
    public const string _table = 'list_scope_child';
    public const string _primary = 'id';
    public const array _columns = ['id', 'anchor_key', 'label'];
    public const array _types = ['id' => 'integer', 'anchor_key' => 'integer', 'label' => 'string'];

    public ?int $id = null;
    public ?int $anchor_key = null;
    public ?string $label = null;
}

abstract class ListScopeObject extends Object_
{
    public const array COLUMNS = ['logicalKey' => 'logical_key', 'ownerId' => 'owner_id', 'groupCode' => 'group_code', 'anchorKey' => 'anchor_key'];

    /**
     * @param string $property Object field name
     * @return mixed Corresponding entity value
     */
    public function __get(string $property): mixed
    {
        $column = self::COLUMNS[$property] ?? $property;
        return in_array($column, (static::ENTITY_CLASS)::_columns, true) ? $this->entity->{$column} : parent::__get($property);
    }
}

final class ListScopeAnchorObject extends ListScopeObject
{
    public const string ENTITY_CLASS = ListScopeAnchorEntity::class;
}

final class ListScopeChildObject extends ListScopeObject
{
    public const string ENTITY_CLASS = ListScopeChildEntity::class;
}

final class ListScopeAnchorObjects extends Objects
{
    public const string OBJECT_CLASS = ListScopeAnchorObject::class;
    public const string COLLECTION_KEY = ListScopeSources::ANCHORS;
}

final class ListScopeChildObjects extends Objects
{
    public const string OBJECT_CLASS = ListScopeChildObject::class;
    public const string COLLECTION_KEY = ListScopeSources::CHILDREN;
}

final class ListScopeAnchorCollection extends DbCollection
{
    public const string DB_ITEM_CLASS = ListScopeDbItem::class;
    public const string OBJECT_COLLECTION_CLASS = ListScopeAnchorObjects::class;
}

final class ListScopeChildCollection extends DbCollection
{
    public const string DB_ITEM_CLASS = ListScopeDbItem::class;
    public const string OBJECT_COLLECTION_CLASS = ListScopeChildObjects::class;
}

/** @extends DbItem<ListScopeObject> */
final class ListScopeDbItem extends DbItem
{
    /**
     * @param string $name Projected object field
     * @return mixed Current object value
     */
    public function __get(string $name): mixed
    {
        return $this->_object->{$name};
    }
}

final class ListScopeDbContext extends HilosDbContext
{
    /** Mount both fake tables with real key-lazy object/view collections. */
    public function configure(): void
    {
        $this->_objectCollections[ListScopeSources::ANCHORS] = ListScopeAnchorObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->_objectCollections[ListScopeSources::CHILDREN] = ListScopeChildObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $this->setRepresent(ListScopeSources::ANCHORS, ListScopeAnchorCollection::class);
        $this->setRepresent(ListScopeSources::CHILDREN, ListScopeChildCollection::class);
    }
}

/** Runtime fixture storage private to one test, replaced before each source fact. */
final class ListScopeRtContext extends RtContext
{
    /** @param array<string, array<int, array<string, mixed>>> $rows Source rows by collection key */
    public function __construct(private readonly array $rows)
    {
        parent::__construct();
    }

    /** Mount readable runtime collections over the test's current rows. */
    public function configure(): void
    {
        foreach ($this->rows as $source => $rows) {
            $this->_stateCollections[$source] = $source === ListScopeSources::ANCHORS
                ? ListScopeAnchorStates::init() : ListScopeChildStates::init();
            $this->setRepresent($source, ListScopeRtCollection::class);
            foreach ($rows as $row) {
                $this->_stateCollections[$source]->add($source === ListScopeSources::ANCHORS
                    ? ListScopeAnchorState::fromRow($row) : ListScopeChildState::fromRow($row));
            }
        }
    }
}

final class ListScopeAnchorStates extends RtStates
{
    public const string STATE_CLASS = ListScopeAnchorState::class;
}

final class ListScopeChildStates extends RtStates
{
    public const string STATE_CLASS = ListScopeChildState::class;
}

final class ListScopeAnchorState extends RtState
{
    /**
     * @param int $id Storage primary key
     * @param int $logicalKey Logical LIST key
     * @param int $ownerId Subscription owner
     * @param string $groupCode Second WHERE field
     * @param string $label Mutable source value
     */
    private function __construct(
        public readonly int $id,
        public readonly int $logicalKey,
        public readonly int $ownerId,
        public readonly string $groupCode,
        public readonly string $label,
    ) {
        parent::__construct();
    }

    /** @return string Storage id, independent of logical LIST item key */
    public function getId(): string
    {
        return (string) $this->id;
    }

    /**
     * @param array<string, mixed> $row Current fixture properties
     * @return static Readable runtime anchor
     */
    public static function fromRow(array $row): static
    {
        return new static(
            self::requireInt($row, 'id'), self::requireInt($row, 'logicalKey'), self::requireInt($row, 'ownerId'),
            self::requireString($row, 'groupCode'), self::requireString($row, 'label'),
        );
    }

    /** @return array<string, mixed> Current anchor properties */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'logicalKey' => $this->logicalKey, 'ownerId' => $this->ownerId,
            'groupCode' => $this->groupCode, 'label' => $this->label,
        ];
    }
}

final class ListScopeChildState extends RtState
{
    /**
     * @param int $id Storage primary key
     * @param int $anchorKey Logical key of the parent item
     * @param string $label Mutable source value
     */
    private function __construct(public readonly int $id, public readonly int $anchorKey, public readonly string $label)
    {
        parent::__construct();
    }

    /** @return string Storage id */
    public function getId(): string
    {
        return (string) $this->id;
    }

    /**
     * @param array<string, mixed> $row Current fixture properties
     * @return static Readable runtime child
     */
    public static function fromRow(array $row): static
    {
        return new static(self::requireInt($row, 'id'), self::requireInt($row, 'anchorKey'), self::requireString($row, 'label'));
    }

    /** @return array<string, mixed> Current child properties */
    public function toArray(): array
    {
        return ['id' => $this->id, 'anchorKey' => $this->anchorKey, 'label' => $this->label];
    }
}

final class ListScopeRtCollection extends RtCollection
{
    /**
     * @param RtState $state Current fixture state
     * @return RtItem Readable runtime source
     */
    protected function createRtItem(RtState $state): RtItem
    {
        return new ListScopeRtItem($state);
    }
}

/** @extends RtItem<ListScopeAnchorState|ListScopeChildState> */
final class ListScopeRtItem extends RtItem
{
    /**
     * @param string $name Projected property
     * @return mixed Current source value
     */
    public function __get(string $name): mixed
    {
        $row = $this->_state->toArray();
        return array_key_exists($name, $row) ? $row[$name] : parent::__get($name);
    }

    /** @return array<string, mixed> Current fixture properties */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}
