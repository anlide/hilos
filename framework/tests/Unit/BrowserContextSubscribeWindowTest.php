<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableFacetCountDTO;
use Hilos\Core\Table\DTO\TableFacetCountsSignalData;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\DTO\TableProgressSignalData;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowRefusedSignalData;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableFacetTally;
use Hilos\Core\Table\TableProgressScope;
use Hilos\Core\Table\TableWindowRefusalCode;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\TableRefusalRuntime as StateTableRefusalRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the first window a page subscription answers with (HIL-642).
 *
 * A viewport table used to be skipped by the subscription snapshot entirely and to arrive one
 * round trip later, in reply to the client's own viewport frame. Now the subscription answers
 * with it, in a section of its own, and the window is opened on the server before the answer
 * leaves — which is what gives a live change born between the two something to be addressed to.
 */
final class BrowserContextSubscribeWindowTest extends TestCase
{
    private ?RtContext $previousRt = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->previousRt = Hilos::$rt;
    }

    public function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateTableRefusalRuntime::RT_ITEM);
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = null;
        Hilos::$table = null;

        parent::tearDown();
    }

    public function testSubscribingAnswersWithTheFirstWindowInASectionOfItsOwn(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new SubscribeWindowUnitTableContext(self::threeRows());
        Hilos::$table->configure();

        new SubscribeWindowUnitBrowserContext()->subscribeSnapshot(
            SubscribeWindowUnitBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        $this->assertSame(
            [
                TableWindowSignalData::rows => [
                    [
                        PagePayload::rowKey => 'a',
                        PagePayload::slots => [SubscribeWindowUnitTable::SLOT => ['key' => 'a', 'label' => 'Alpha']],
                    ],
                    [
                        PagePayload::rowKey => 'b',
                        PagePayload::slots => [SubscribeWindowUnitTable::SLOT => ['key' => 'b', 'label' => 'Beta']],
                    ],
                ],
                TableWindowDescriptorDTO::SORT => [
                    [TableSortDTO::FIELD => 'key', TableSortDTO::DIRECTION => TableConstants::ORDER_ASC],
                ],
                TableWindowSignalData::limit => 2,
                TableWindowSignalData::totalCount => 3,
                TableWindowSignalData::totalExact => true,
                TableWindowSignalData::firstAnchor => ['key' => 'a'],
                TableWindowSignalData::lastAnchor => ['key' => 'b'],
                TableWindowSignalData::rowsBefore => 0,
            ],
            self::windowOf(self::answer(), SubscribeWindowUnitTable::TABLE),
        );
    }

    public function testASubscriptionThatReportsNoWindowTakesTheSizeAndOrderTheTableDeclares(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new SubscribeWindowUnitTableContext(self::threeRows());
        Hilos::$table->configure();

        new SubscribeWindowUnitBrowserContext()->subscribeSnapshot(
            SubscribeWindowUnitBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        // The cold entry: nothing was reported and nothing is held, so what the table says
        // about its own first window is the whole answer.
        $viewport = Hilos::$sr->getTableViewport('ak-1', SubscribeWindowUnitTable::TABLE);
        $this->assertNotNull($viewport);
        $this->assertSame(2, $viewport->limit);
        $this->assertSame('key', $viewport->sort?->last()->field);
        $this->assertSame(['a', 'b'], $viewport->rowIds());
    }

    public function testAReportedDescriptorBeatsWhatTheTableDeclares(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new SubscribeWindowUnitTableContext(self::threeRows());
        Hilos::$table->configure();
        Hilos::$sr->reportTableWindows('ak-1', [
            SubscribeWindowUnitTable::TABLE => new TableWindowDescriptorDTO(
                sort: TableSortOrderDTO::of(new TableSortDTO('key', TableConstants::ORDER_DESC)),
                limit: 1,
            ),
        ]);

        new SubscribeWindowUnitBrowserContext()->subscribeSnapshot(
            SubscribeWindowUnitBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        // A tab coming back after a broken socket is the only side that still remembers what
        // was on the screen, so what it reports outranks the table's own declaration.
        $window = self::windowOf(self::answer(), SubscribeWindowUnitTable::TABLE);
        $this->assertSame(1, $window[TableWindowSignalData::limit]);
        $this->assertSame(['c'], array_column($window[TableWindowSignalData::rows], PagePayload::rowKey));
    }

    public function testTheWindowIsOnTheRegistryBeforeTheAnswerLeaves(): void
    {
        $router = new SubscribeWindowRecordingSignalRouter();
        Hilos::$sr = $router;
        Hilos::$table = new SubscribeWindowUnitTableContext(self::threeRows());
        Hilos::$table->configure();

        new SubscribeWindowUnitBrowserContext()->subscribeSnapshot(
            SubscribeWindowUnitBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        // The whole point of opening the window here rather than on the client's first frame:
        // a change born between the subscription and the first render has an address only if
        // the window already exists when the answer goes out.
        $this->assertSame(['a', 'b'], $router->rowIdsWhenAnswerWasQueued);
    }

    public function testAPageWithNoViewportTableCarriesNoWindowsSection(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new SubscribeWindowUnitTableContext(self::threeRows());
        Hilos::$table->configure();

        new SubscribeWindowUnitBrowserContext()->subscribeSnapshot(
            SubscribeWindowUnitBrowserContext::OTHER_PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testTheWindowCarriesTheWorkRunningOnItsTable(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new SubscribeWindowUnitTableContext(self::threeRows(), [
            new TableProgressDTO(TableProgressScope::Row, 'backup-17', 'a', 3, 11),
            new TableProgressDTO(TableProgressScope::Table, 'nightly', null, 34, 120, false, ['title' => 'Nightly']),
        ]);
        Hilos::$table->configure();

        new SubscribeWindowUnitBrowserContext()->subscribeSnapshot(
            SubscribeWindowUnitBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        // A tab opening in the middle of a run sees the bars with its first window, rather than
        // at the next stir of a source - which for work reporting a phase at a time is minutes.
        $window = self::windowOf(self::answer(), SubscribeWindowUnitTable::TABLE);
        $this->assertSame(
            [
                [
                    TableProgressDTO::scope => TableProgressScope::Row->value,
                    TableProgressDTO::progressKey => 'backup-17',
                    TableProgressDTO::current => 3,
                    TableProgressDTO::rowKey => 'a',
                    TableProgressDTO::total => 11,
                ],
                [
                    TableProgressDTO::scope => TableProgressScope::Table->value,
                    TableProgressDTO::progressKey => 'nightly',
                    TableProgressDTO::current => 34,
                    TableProgressDTO::total => 120,
                    TableProgressDTO::detail => ['title' => 'Nightly'],
                ],
            ],
            $window[TableProgressSignalData::progress],
        );
    }

    public function testATableWithNothingRunningCarriesNoProgressKeyAtAll(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new SubscribeWindowUnitTableContext(self::threeRows());
        Hilos::$table->configure();

        new SubscribeWindowUnitBrowserContext()->subscribeSnapshot(
            SubscribeWindowUnitBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        // Absent rather than empty: an empty list would reach the wire as a JSON array where
        // every other bar-carrying answer has a list of objects.
        $this->assertArrayNotHasKey(
            TableProgressSignalData::progress,
            self::windowOf(self::answer(), SubscribeWindowUnitTable::TABLE),
        );
    }

    public function testATableThatCannotNameItsWorkKeepsItsRowsAndLosesItsBars(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new SubscribeWindowUnitTableContext(self::threeRows(), [], true);
        Hilos::$table->configure();

        ob_start();
        new SubscribeWindowUnitBrowserContext()->subscribeSnapshot(
            SubscribeWindowUnitBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );
        $logged = (string) ob_get_clean();

        // Narrower than the window's containment, and deliberately so: by then there is a
        // window worth showing, and a refusal over the bars must not cost the subscriber the
        // whole page.
        $this->assertStringContainsString('Browser window skipped the work', $logged);
        $window = self::windowOf(self::answer(), SubscribeWindowUnitTable::TABLE);
        $this->assertArrayNotHasKey(TableProgressSignalData::progress, $window);
        $this->assertCount(2, $window[TableWindowSignalData::rows]);
    }

    public function testATableThatCannotBuildItsWindowGoesIntoRefusedWindows(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new SubscribeWindowUnitTableContext(self::threeRows());
        Hilos::$table->configure();

        ob_start();
        new SubscribeWindowUnitBrowserContext()->subscribeSnapshot(
            SubscribeWindowUnitBrowserContext::REFUSING_PAGE,
            'ak-1',
            new PageRouteParams([]),
        );
        $logged = (string) ob_get_clean();

        $this->assertStringContainsString('Browser window skipped a table', $logged);
        $answer = self::answer();
        $payload = $answer[PageResponseSignalData::payload] ?? [];
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey(
            SubscribeWindowRefusingTable::TABLE,
            $payload[PagePayload::windows] ?? [],
        );
        $this->assertSame(
            [TableWindowRefusedSignalData::errorCode => TableWindowRefusalCode::INTERNAL_ERROR],
            self::refusalOf($answer, SubscribeWindowRefusingTable::TABLE),
        );
    }

    public function testAPageWithTwoTablesKeepsTheLiveWindowAndRefusesTheBrokenOne(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new SubscribeWindowUnitTableContext(self::threeRows());
        Hilos::$table->configure();

        ob_start();
        new SubscribeWindowUnitBrowserContext()->subscribeSnapshot(
            SubscribeWindowUnitBrowserContext::MIXED_PAGE,
            'ak-1',
            new PageRouteParams([]),
        );
        ob_end_clean();

        $answer = self::answer();
        $payload = $answer[PageResponseSignalData::payload] ?? [];
        $this->assertIsArray($payload);
        $windows = $payload[PagePayload::windows] ?? [];
        $this->assertIsArray($windows);
        $this->assertArrayHasKey(SubscribeWindowUnitTable::TABLE, $windows);
        $this->assertArrayNotHasKey(SubscribeWindowRefusingTable::TABLE, $windows);
        $this->assertSame(
            [TableWindowRefusedSignalData::errorCode => TableWindowRefusalCode::INTERNAL_ERROR],
            self::refusalOf($answer, SubscribeWindowRefusingTable::TABLE),
        );
        $this->assertArrayNotHasKey(
            SubscribeWindowUnitTable::TABLE,
            $payload[PagePayload::refusedWindows] ?? [],
        );
    }

    public function testATableTheTestLeverRefusesGoesIntoRefusedWindowsBesideItsLiveSibling(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new SubscribeWindowUnitTableContext(self::threeRows());
        Hilos::$table->configure();
        // Written outside any agent, as the master writes it in answer to test:table:refuse; the
        // RT sync frame the write queues is drained so the answer is the next frame out.
        Hilos::$rt = new SubscribeWindowUnitRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::registerDaemon(StateTableRefusalRuntime::RT_ITEM);
        Hilos::$rt->hilosTableRefusalRuntime?->actions->set(SubscribeWindowUnitTable::SIBLING_TABLE);
        while (Hilos::$sr->getNextQueuedSignal() !== null) {
            continue;
        }

        ob_start();
        new SubscribeWindowUnitBrowserContext()->subscribeSnapshot(
            SubscribeWindowUnitBrowserContext::SIBLINGS_PAGE,
            'ak-1',
            new PageRouteParams([]),
        );
        $logged = (string) ob_get_clean();

        // Both tables can build their window; the one the lever names is refused the way a broken
        // one is, and its sibling on the same page answers with its rows as if nothing happened.
        $this->assertStringContainsString('test:table:refuse', $logged);
        $answer = self::answer();
        $payload = $answer[PageResponseSignalData::payload] ?? [];
        $this->assertIsArray($payload);
        $windows = $payload[PagePayload::windows] ?? [];
        $this->assertIsArray($windows);
        $this->assertArrayNotHasKey(SubscribeWindowUnitTable::SIBLING_TABLE, $windows);
        $this->assertSame(
            [TableWindowRefusedSignalData::errorCode => TableWindowRefusalCode::INTERNAL_ERROR],
            self::refusalOf($answer, SubscribeWindowUnitTable::SIBLING_TABLE),
        );
        $this->assertCount(2, self::windowOf($answer, SubscribeWindowUnitTable::TABLE)[TableWindowSignalData::rows]);
        $this->assertArrayNotHasKey(SubscribeWindowUnitTable::TABLE, $payload[PagePayload::refusedWindows] ?? []);
    }

    /**
     * @return list<SubscribeWindowUnitRow> Three rows in the order the table hands them over
     */
    private static function threeRows(): array
    {
        return [
            new SubscribeWindowUnitRow('a', 'Alpha'),
            new SubscribeWindowUnitRow('b', 'Beta'),
            new SubscribeWindowUnitRow('c', 'Gamma'),
        ];
    }

    /**
     * @return array<string, mixed> Wire payload of the page_response the subscription queued
     */
    private static function answer(): array
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(SignalTypeConstants::PAGE_RESPONSE, $signal->signalName->getName());
        self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
        self::assertInstanceOf(PageResponseSignalData::class, $signal->data->data);

        return $signal->data->data->toArray();
    }

    /**
     * @param array<string, mixed> $answer Wire payload of a page_response
     * @param string $tableKey Table whose window is read out of it
     * @return array<string, mixed> The `windows` entry for that table
     */
    private static function windowOf(array $answer, string $tableKey): array
    {
        $payload = $answer[PageResponseSignalData::payload] ?? [];
        self::assertIsArray($payload);
        $windows = $payload[PagePayload::windows] ?? [];
        self::assertIsArray($windows);
        self::assertArrayHasKey($tableKey, $windows);
        self::assertIsArray($windows[$tableKey]);

        return $windows[$tableKey];
    }

    /**
     * @param array<string, mixed> $answer Wire payload of a page_response
     * @param string $tableKey Table whose refusal is read out of it
     * @return array<string, mixed> The `refusedWindows` entry for that table
     */
    private static function refusalOf(array $answer, string $tableKey): array
    {
        $payload = $answer[PageResponseSignalData::payload] ?? [];
        self::assertIsArray($payload);
        $refused = $payload[PagePayload::refusedWindows] ?? [];
        self::assertIsArray($refused);
        self::assertArrayHasKey($tableKey, $refused);
        self::assertIsArray($refused[$tableKey]);

        return $refused[$tableKey];
    }

    public function testTheCountsATabReportedAskingForFollowTheAnswerInAFrameOfTheirOwn(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new SubscribeWindowUnitTableContext(self::threeRows());
        Hilos::$table->configure();
        Hilos::$sr->reportTableWindows('ak-1', [
            SubscribeWindowUnitTable::TABLE => new TableWindowDescriptorDTO(
                limit: 2,
                facets: [SubscribeWindowUnitTable::FILTER_LABEL => ['Alpha', 'Delta']],
            ),
        ]);

        new SubscribeWindowUnitBrowserContext()->subscribeSnapshot(
            SubscribeWindowUnitBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        // A tab back from a broken socket is the only side that still knows which options it asked
        // counts beside, and the counts go out after the answer: before it the table has no window.
        $this->assertSame(
            [SubscribeWindowUnitTable::FILTER_LABEL => ['Alpha', 'Delta']],
            Hilos::$sr->getTableFacets('ak-1', SubscribeWindowUnitTable::TABLE),
        );
        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr->getNextQueuedSignal()?->signalName->getName());
        $counts = Hilos::$sr->getNextQueuedSignal();
        $this->assertSame(SignalTypeConstants::TABLE_FACET_COUNTS, $counts?->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $counts->data);
        $this->assertInstanceOf(TableFacetCountsSignalData::class, $counts->data->data);
        $options = $counts->data->data->facets->filters[SubscribeWindowUnitTable::FILTER_LABEL][TableConstants::FACET_KEY_OPTIONS];
        $this->assertSame(1, $options['Alpha']->count);
        $this->assertSame(0, $options['Delta']->count);
    }

    public function testASubscriptionThatAskedForNoCountsIsAnsweredWithTheAnswerAlone(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$table = new SubscribeWindowUnitTableContext(self::threeRows());
        Hilos::$table->configure();

        new SubscribeWindowUnitBrowserContext()->subscribeSnapshot(
            SubscribeWindowUnitBrowserContext::PAGE,
            'ak-1',
            new PageRouteParams([]),
        );

        $this->assertSame(SignalTypeConstants::PAGE_RESPONSE, Hilos::$sr->getNextQueuedSignal()?->signalName->getName());
        $this->assertNull(Hilos::$sr->getNextQueuedSignal());
    }
}

final class SubscribeWindowRecordingSignalRouter extends SignalRouter
{
    /** @var ?list<string> Rows the window held when the page answer was queued, or null while none was */
    public ?array $rowIdsWhenAnswerWasQueued = null;

    /**
     * Records what the registry already held at the moment the page answer was queued.
     *
     * @param SignalSourceInterface $signalSource Signal source
     * @param SignalTypeInterface $signalType Signal type
     * @param SignalNameInterface $signalName Signal name
     * @param SignalDataInterface $signalData Signal payload
     */
    public function queueSignal(
        SignalSourceInterface $signalSource,
        SignalTypeInterface $signalType,
        SignalNameInterface $signalName,
        SignalDataInterface $signalData,
    ): void {
        if ($signalName->getName() === SignalTypeConstants::PAGE_RESPONSE) {
            $this->rowIdsWhenAnswerWasQueued
                = $this->getTableViewport('ak-1', SubscribeWindowUnitTable::TABLE)?->rowIds();
        }

        parent::queueSignal($signalSource, $signalType, $signalName, $signalData);
    }
}

final class SubscribeWindowUnitBrowserContext extends BrowserContext
{
    public const string PAGE = 'subscribe_window_unit_page';
    public const string OTHER_PAGE = 'subscribe_window_unit_other_page';
    public const string REFUSING_PAGE = 'subscribe_window_unit_refusing_page';
    public const string MIXED_PAGE = 'subscribe_window_unit_mixed_page';
    public const string SIBLINGS_PAGE = 'subscribe_window_unit_siblings_page';
    public const string SIGNAL = 'subscribe_window_unit_signal';

    /**
     * Resolves a page config for each of the test pages.
     *
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        if (!in_array($page, [self::PAGE, self::OTHER_PAGE, self::REFUSING_PAGE, self::MIXED_PAGE, self::SIBLINGS_PAGE], true)) {
            return null;
        }

        return BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => self::SIGNAL]);
    }

    /**
     * Binds the viewport table to the page under test and the refusing one to its own page.
     *
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return match ($page) {
            self::PAGE => BrowserPageBindings::fromArray([SubscribeWindowUnitTable::TABLE => []]),
            self::REFUSING_PAGE => BrowserPageBindings::fromArray([SubscribeWindowRefusingTable::TABLE => []]),
            self::MIXED_PAGE => BrowserPageBindings::fromArray([
                SubscribeWindowUnitTable::TABLE => [],
                SubscribeWindowRefusingTable::TABLE => [],
            ]),
            self::SIBLINGS_PAGE => BrowserPageBindings::fromArray([
                SubscribeWindowUnitTable::TABLE => [],
                SubscribeWindowUnitTable::SIBLING_TABLE => [],
            ]),
            default => BrowserPageBindings::empty(),
        };
    }
}

final class SubscribeWindowUnitTableContext extends TableContext
{
    /**
     * @param list<SubscribeWindowUnitRow> $rows Snapshot rows the table returns
     * @param list<TableProgressDTO> $progress Bars the table says are running on it
     * @param bool $progressRefuses Whether naming the work refuses instead of answering
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly array $progress = [],
        private readonly bool $progressRefuses = false,
    ) {
    }

    public function configure(): void
    {
        $this->register(
            SubscribeWindowUnitTable::TABLE,
            new SubscribeWindowUnitTable($this->rows, $this->progress, $this->progressRefuses),
        );
        $this->register(SubscribeWindowRefusingTable::TABLE, new SubscribeWindowRefusingTable());
        $this->register(SubscribeWindowUnitTable::SIBLING_TABLE, new SubscribeWindowUnitTable($this->rows));
    }
}

/**
 * Runtime context of a project that mounts nothing of its own; the table refusal row comes with the framework.
 */
final class SubscribeWindowUnitRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

final class SubscribeWindowUnitTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'subscribeWindowUnitTable';

    /** Key the same healthy table is registered under a second time, as a sibling on one page. */
    public const string SIBLING_TABLE = 'subscribeWindowSiblingTable';

    public const string SLOT = 'subscribeWindowUnitRows';

    /** Filter key the fixture counts its rows by. */
    public const string FILTER_LABEL = 'label';

    /**
     * @param list<SubscribeWindowUnitRow> $rows Snapshot rows the table owns
     * @param list<TableProgressDTO> $progress Bars the table says are running on it
     * @param bool $progressRefuses Whether naming the work refuses instead of answering
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly array $progress = [],
        private readonly bool $progressRefuses = false,
    ) {
        parent::__construct();
    }

    /**
     * @return list<TableProgressDTO> Bars the fixture was built with
     * @throws InvalidFormatException When the fixture was told to refuse the question
     */
    public function progressSnapshot(): array
    {
        if ($this->progressRefuses) {
            throw new InvalidFormatException('This table cannot name its work');
        }

        return $this->progress;
    }

    /**
     * @return int Two rows, so that a window smaller than the set is visible in the answer
     */
    public function windowSize(): int
    {
        return 2;
    }

    /**
     * @return ?TableSortOrderDTO First window ordered by key ascending
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO('key'));
    }

    /**
     * Counts the injected rows by label.
     *
     * @param TableQueryDTO $query Window query whose filters describe the set
     * @param array<string, list<int|float|string|bool>> $wanted Options to count, by filter key
     * @return array<string, array{any: TableFacetCountDTO, options: array<array-key, TableFacetCountDTO>}> Counts by filter key
     */
    public function facetCounts(TableQueryDTO $query, array $wanted): ?array
    {
        return TableFacetTally::forFilters(
            $query,
            array_intersect_key($wanted, [self::FILTER_LABEL => true]),
            fn(TableQueryDTO $set): TableFacetCountDTO => new TableFacetCountDTO(
                count(array_filter(
                    $this->rows,
                    static fn(SubscribeWindowUnitRow $row): bool => !array_key_exists(self::FILTER_LABEL, $set->filter)
                        || $set->filter[self::FILTER_LABEL] === $row->toArray()[self::FILTER_LABEL],
                )),
                true,
            ),
        );
    }

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
            BrowserPageSignalData::sources => [self::SLOT => $row->toArray()],
        ];
    }

    /**
     * Configures the row class so makeRows rebuilds typed rows from the filter output.
     */
    protected function init(): void
    {
        $this->setRowClass(SubscribeWindowUnitRow::class);
    }

    /**
     * Applies the in-memory filter to the injected rows.
     *
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        $rows = array_map(static fn(SubscribeWindowUnitRow $row): array => $row->toArray(), $this->rows);

        return $this->filterInMemory($rows, $query);
    }
}

/**
 * Table whose window build refuses, standing for a source that cannot be read at subscribe time.
 */
final class SubscribeWindowRefusingTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'subscribeWindowRefusingTable';

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
     * Never reached: the window build refuses first.
     *
     * @param AbstractTableRow $row Self-snapshot row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => [],
        ];
    }

    /**
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Never returned
     * @throws InvalidFormatException Always, standing for a source that refuses its read
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        throw new InvalidFormatException('This table cannot read its rows');
    }
}

final class SubscribeWindowUnitRow extends AbstractTableRow
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
    ) {
    }

    public function getRowKey(): string
    {
        return $this->key;
    }

    /**
     * @return string Payload key the row key travels under
     */
    public static function keyField(): string
    {
        return 'key';
    }

    /**
     * @return array<string, mixed> Row fields
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
        ];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static Row instance
     * @throws InvalidFormatException When the payload is missing a field the row is built from
     */
    public static function fromArray(array $data): static
    {
        return new static(
            self::requireString($data, 'key'),
            self::requireString($data, 'label'),
        );
    }
}
