<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\AdminViewMode\WireField;
use Hilos\Backup\Anonymization\AnonymizationStrategy;
use Hilos\Backup\Anonymization\PiiRegistry;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserPageBindings;
use Hilos\Core\Browser\Config\BrowserPageConfig;
use Hilos\Core\Browser\Config\BrowserSourceConfig;
use Hilos\Core\Browser\Config\BrowserSourceKey;
use Hilos\Core\Browser\Config\BrowserSourceType;
use Hilos\Core\Browser\Config\BrowserTableConfigKey;
use Hilos\Core\Browser\Config\BrowserTableFieldKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Browser\DTO\BrowserPageSignalData;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\DTO\PageResponseSignalData;
use Hilos\Core\Page\Exception\PageInternalErrorException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\TableViewportSubscription;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Table\Definition\SelfSnapshotTable;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableProgressDTO;
use Hilos\Core\Table\DTO\TableProgressSignalData;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\DTO\TableViewportAppendDTO;
use Hilos\Core\Table\DTO\TableViewportDeltaDTO;
use Hilos\Core\Table\DTO\TableViewportOwnCreateDTO;
use Hilos\Core\Table\DTO\TableWindowDescriptorDTO;
use Hilos\Core\Table\DTO\TableWindowSignalData;
use Hilos\Core\Table\Exception\TableRowKeyMissingException;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableConstants;
use Hilos\Core\Table\TableProgressScope;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\Socket\WebSocket\DTO\WebSocketPageSubscribeSignalDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests the bridge on the row of a typed table: what a viewer of the admin view mode is sent (HIL-1250).
 *
 * The gate does not let a viewer in before HIL-1251, so the viewer here is a double: an admin the gate
 * lets through, whom the one question "is this a viewer?" answers yes. The column verdicts are a
 * registry of the double's own - the person's name is personal, the last activity is not.
 *
 * What is pinned: every frame a row rides - the window, the window section of the subscription answer,
 * the delta, the append, the author's own create, the body of a row held in focus - hides the row the
 * same way, by the table's declaration, with the key as it is; a change to a hidden field raises no
 * delta; the window of a viewer is not sorted or searched by a field hidden from them, and says the
 * order it was served in; the detail of a progress bar is hidden and its count is not. And an admin,
 * through the very same code, receives the rows byte for byte as before.
 */
final class BrowserContextAdminViewModeWireTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-1';

    protected function tearDown(): void
    {
        Hilos::$rt = null;
        Hilos::$sr = null;
        Hilos::$table = null;
        Hilos::$db = null;
        Hilos::initBrowser();
        Hilos::resetBrowser();

        parent::tearDown();
    }

    public function testATableWindowHidesEveryFieldNothingOpened(): void
    {
        $browser = $this->boot(viewer: true);

        $this->sendWindow($browser, TableSortOrderDTO::of(new TableSortDTO('key')));

        $window = $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);
        $this->assertSame([self::hiddenRow('alpha'), self::hiddenRow('beta')], $window->rows);
    }

    public function testAnAdminReceivesTheRowsExactlyAsBefore(): void
    {
        $browser = $this->boot(viewer: false);

        $viewport = $this->sendWindow($browser, TableSortOrderDTO::of(new TableSortDTO('name')));

        $window = $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);
        $this->assertSame([self::plainRow('beta'), self::plainRow('alpha')], $window->rows);
        $this->assertSame(['name' => 'Anna', 'key' => 'beta'], $window->firstAnchor?->values);
        $this->assertNull($viewport->shownFields);
        $this->assertSame('name', $viewport->sort?->last()->field);
    }

    public function testTheSubscriptionAnswerHidesItsWindowAndNamesTheOrderItServed(): void
    {
        $browser = $this->boot(viewer: true);

        $browser->subscribeSnapshot(WireTestBrowser::PAGE, self::ACCEPT_KEY, new PageRouteParams([]));

        $window = $this->windowSection();
        $this->assertSame([self::hiddenRow('alpha'), self::hiddenRow('beta')], $window[TableWindowSignalData::rows]);
        // The table orders its first window by the person's name; a viewer is not ordered by it.
        $this->assertSame([], $window[TableWindowDescriptorDTO::SORT]);
        $this->assertNull(Hilos::$sr?->getTableViewport(self::ACCEPT_KEY, WireTestTable::TABLE)?->sort);
    }

    public function testAViewerSortedByAHiddenFieldIsServedWithoutThatOrderAndAnchorsCarryNoHiddenValue(): void
    {
        $browser = $this->boot(viewer: true);

        $viewport = $this->sendWindow(
            $browser,
            TableSortOrderDTO::of(new TableSortDTO('seen', TableConstants::ORDER_DESC), new TableSortDTO('name')),
        );

        // The component over the hidden name goes; the one left is served, and the places carry it and the key.
        $this->assertSame(
            [[TableSortDTO::FIELD => 'seen', TableSortDTO::DIRECTION => TableConstants::ORDER_DESC]],
            $viewport->sort?->toArray(),
        );
        $window = $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);
        $this->assertSame([self::hiddenRow('gamma'), self::hiddenRow('beta')], $window->rows);
        $this->assertSame(['seen' => '2026-09-03', 'key' => 'gamma'], $window->firstAnchor?->values);
        $this->assertSame(['seen' => '2026-09-02', 'key' => 'beta'], $window->lastAnchor?->values);

        $viewport = $this->sendWindow($browser, TableSortOrderDTO::of(new TableSortDTO('name', TableConstants::ORDER_DESC)));

        $this->assertNull($viewport->sort);
        $window = $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);
        $this->assertSame([self::hiddenRow('alpha'), self::hiddenRow('beta')], $window->rows);
        $this->assertArrayNotHasKey('name', $window->firstAnchor?->values ?? []);
        $this->assertArrayNotHasKey('name', $window->lastAnchor?->values ?? []);
    }

    public function testAViewerPagesOnThroughAWindowServedWithoutTheOrderItAskedFor(): void
    {
        $browser = $this->boot(viewer: true);
        // The tab asks for the name order it cannot have; the first window comes in the table's own order.
        $this->sendWindow($browser, TableSortOrderDTO::of(new TableSortDTO('name')), null, 1);
        $first = $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);
        $this->assertSame([self::hiddenRow('alpha')], $first->rows);

        // It asks for the next page the same way, from the place that window reported.
        $viewport = $browser->viewportForViewer(
            WireTestBrowser::PAGE,
            self::ACCEPT_KEY,
            new TableViewportSubscription(
                tableKey: WireTestTable::TABLE,
                sort: TableSortOrderDTO::of(new TableSortDTO('name')),
                limit: 1,
                anchor: $first->lastAnchor,
            ),
        );
        Hilos::$sr?->setTableViewport(self::ACCEPT_KEY, $viewport);
        $browser->sendTableWindow(WireTestBrowser::PAGE, self::ACCEPT_KEY, $viewport);

        $this->assertSame(
            [self::hiddenRow('beta')],
            $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class)->rows,
        );
    }

    public function testWhatIsLeftOfALongerOrderIsNotAssembledIntoANewOne(): void
    {
        $browser = $this->boot(viewer: true);

        $viewport = $this->sendWindow(
            $browser,
            TableSortOrderDTO::of(new TableSortDTO('seen'), new TableSortDTO('name'), new TableSortDTO('key')),
        );

        // Two components left of three make a combination nobody offered: no order at all.
        $this->assertNull($viewport->sort);
    }

    public function testATableHoldsAViewersQueryToTheShownFieldsEvenWhenItComesPastTheWindow(): void
    {
        $this->boot(viewer: true);
        $table = Hilos::$table?->get(WireTestTable::TABLE);
        $this->assertInstanceOf(WireTestTable::class, $table);

        $snapshot = $table->getPage(new TableQueryDTO(
            sort: TableSortOrderDTO::of(new TableSortDTO('name')),
            limit: 2,
            shownFields: ['seen', 'key'],
        ));

        // Ordered by the name, beta (Anna) would come first; the second lock serves it unordered.
        $this->assertSame(['alpha', 'beta'], array_map(static fn(AbstractTableRow $row): string => (string)$row->getRowKey(), $snapshot->rows));
        $this->assertSame(['key' => 'alpha'], $snapshot->firstAnchor?->values);
    }

    public function testTheWindowOfAViewerKnowsTheFieldsItMayBeSearchedBy(): void
    {
        $browser = $this->boot(viewer: true);

        $viewport = $browser->viewportForViewer(
            WireTestBrowser::PAGE,
            self::ACCEPT_KEY,
            new TableViewportSubscription(tableKey: WireTestTable::TABLE),
        );

        // The declared fields the verdicts open, and the key.
        $this->assertSame(['seen', 'online', 'key'], $viewport->shownFields);
    }

    public function testASearchOverOnlyHiddenFieldsIsServedWithoutTheSearch(): void
    {
        $browser = $this->boot(viewer: true, searchable: ['name' => 'name']);

        $this->sendWindow($browser, null, 'Anna');

        $window = $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);
        $this->assertSame(3, $window->totalCount);
    }

    public function testASearchReadsOnlyTheFieldsTheViewerIsShown(): void
    {
        $browser = $this->boot(viewer: true, searchable: ['name' => 'name', 'seen' => 'seen']);

        // "Anna" is beta's hidden name: a viewer's search does not find it.
        $this->sendWindow($browser, null, 'Anna');
        $this->assertSame(0, $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class)->totalCount);

        $this->sendWindow($browser, null, '09-02');
        $window = $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);
        $this->assertSame(1, $window->totalCount);
        $this->assertSame([self::hiddenRow('beta')], $window->rows);
    }

    public function testAnAdminStillSearchesEveryDeclaredField(): void
    {
        $browser = $this->boot(viewer: false, searchable: ['name' => 'name']);

        $this->sendWindow($browser, null, 'Anna');

        $window = $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);
        $this->assertSame([self::plainRow('beta')], $window->rows);
    }

    public function testAnEditOfAShownFieldReachesTheViewerHidden(): void
    {
        $browser = $this->boot(viewer: true);
        $this->sendWindow($browser, null);
        $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);

        WireTestTable::$rows['alpha'] = WireTestRow::sample('alpha', seen: '2026-09-09');
        $browser->record(SourceChange::dbUpdated(WireTestTable::SOURCE_KEY, 'alpha', ['seen' => '2026-09-09']));
        $browser->flushToSignalRouter();

        $delta = $this->nextSignal(SignalTypeConstants::TABLE_VIEWPORT_DELTA, TableViewportDeltaDTO::class);
        $this->assertSame(self::hiddenRow('alpha', seen: '2026-09-09'), $delta->row);
    }

    public function testAnEditOfAHiddenFieldRaisesNoDeltaForAViewer(): void
    {
        $browser = $this->boot(viewer: true);
        $this->sendWindow($browser, null);
        $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);

        WireTestTable::$rows['alpha'] = WireTestRow::sample('alpha', name: 'Oksana');
        $browser->record(SourceChange::dbUpdated(WireTestTable::SOURCE_KEY, 'alpha', ['name' => 'Oksana']));
        $browser->flushToSignalRouter();

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAnEditOfAHiddenFieldStillReachesAnAdmin(): void
    {
        $browser = $this->boot(viewer: false);
        $this->sendWindow($browser, null);
        $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);

        WireTestTable::$rows['alpha'] = WireTestRow::sample('alpha', name: 'Oksana');
        $browser->record(SourceChange::dbUpdated(WireTestTable::SOURCE_KEY, 'alpha', ['name' => 'Oksana']));
        $browser->flushToSignalRouter();

        $delta = $this->nextSignal(SignalTypeConstants::TABLE_VIEWPORT_DELTA, TableViewportDeltaDTO::class);
        $this->assertSame(self::plainRow('alpha', name: 'Oksana'), $delta->row);
    }

    public function testTheBodyOfARowHeldInFocusIsHidden(): void
    {
        $browser = $this->boot(viewer: true);
        $this->sendWindow($browser, null, null, 1);
        $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);
        Hilos::$sr?->setTableFocus(self::ACCEPT_KEY, WireTestTable::TABLE, 'beta');

        WireTestTable::$rows['beta'] = WireTestRow::sample('beta', seen: '2026-09-10');
        $browser->record(SourceChange::dbUpdated(WireTestTable::SOURCE_KEY, 'beta', ['seen' => '2026-09-10']));
        $browser->flushToSignalRouter();

        $delta = $this->nextSignal(SignalTypeConstants::TABLE_VIEWPORT_DELTA, TableViewportDeltaDTO::class);
        $this->assertSame(TableViewportDeltaDTO::KIND_ROW_REMOVED, $delta->kind);
        $this->assertSame(self::hiddenRow('beta', seen: '2026-09-10'), $delta->row);
    }

    public function testAnAppendedRowIsHidden(): void
    {
        $browser = $this->boot(viewer: true, rows: ['alpha', 'beta']);
        $this->sendWindow($browser, TableSortOrderDTO::of(new TableSortDTO('key')), null, 10);
        $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);

        WireTestTable::$rows['gamma'] = WireTestRow::sample('gamma');
        $browser->record(SourceChange::dbCreated(WireTestTable::SOURCE_KEY, 'gamma', ['key' => 'gamma']));
        $browser->flushToSignalRouter();

        $append = $this->nextSignal(SignalTypeConstants::TABLE_VIEWPORT_APPEND, TableViewportAppendDTO::class);
        $this->assertSame(self::hiddenRow('gamma'), $append->row);
    }

    public function testTheAuthorsOwnCreateIsHidden(): void
    {
        $browser = $this->boot(viewer: true, rows: ['alpha', 'gamma']);
        $this->sendWindow($browser, TableSortOrderDTO::of(new TableSortDTO('key')), null, 10);
        $this->nextSignal(SignalTypeConstants::TABLE_WINDOW, TableWindowSignalData::class);

        WireTestTable::$rows['beta'] = WireTestRow::sample('beta');
        $browser->record(SourceChange::dbCreated(WireTestTable::SOURCE_KEY, 'beta', ['key' => 'beta'], self::ACCEPT_KEY, 'req-1'));
        $browser->flushToSignalRouter();

        $ownCreate = $this->nextSignal(SignalTypeConstants::TABLE_VIEWPORT_OWN_CREATE, TableViewportOwnCreateDTO::class);
        $this->assertSame(1, $ownCreate->position);
        $this->assertSame(self::hiddenRow('beta'), $ownCreate->row);
    }

    public function testTheDetailOfAProgressBarIsHiddenAndItsCountIsNot(): void
    {
        $browser = $this->boot(viewer: true);

        $browser->subscribeSnapshot(WireTestBrowser::PAGE, self::ACCEPT_KEY, new PageRouteParams([]));
        $snapshotBar = $this->windowSection()[TableProgressSignalData::progress][0] ?? null;

        $browser->record(SourceChange::dbUpdated(WireTestTable::PROGRESS_SOURCE_KEY, 'run', []));
        $browser->flushToSignalRouter();
        $frame = $this->nextSignal(SignalTypeConstants::TABLE_PROGRESS, TableProgressSignalData::class);

        $expected = [
            TableProgressDTO::scope => TableProgressScope::Table->value,
            TableProgressDTO::progressKey => 'run',
            TableProgressDTO::current => 3,
            TableProgressDTO::total => 10,
            TableProgressDTO::detail => ['file' => HiddenValue::mark(), 'phase' => HiddenValue::mark()],
        ];
        $this->assertSame($expected, $snapshotBar);
        $this->assertSame($expected, $frame->progress->toArray());
    }

    public function testADeclarativeRowIsHiddenOnceWholeAndItsJoinStillFindsItsRow(): void
    {
        $this->bootDeclarative();

        new DeclarativeWireTestBrowser(viewer: true)->subscribeSnapshot(DeclarativeWireTestBrowser::PAGE, self::ACCEPT_KEY, new PageRouteParams([]));

        // The team is joined by teamRef, which is hidden from the viewer: the join ran on the real value.
        $this->assertSame(
            [[
                PagePayload::rowKey => 'p1',
                PagePayload::slots => [
                    DeclarativeWireTestRtContext::PEOPLE => [
                        'id' => HiddenValue::mark(),
                        'teamRef' => HiddenValue::mark(),
                        'name' => HiddenValue::mark(),
                        'online' => true,
                        'badge' => 'badge-p1',
                        'score' => HiddenValue::mark(),
                    ],
                    DeclarativeWireTestRtContext::TEAMS => ['teamId' => HiddenValue::mark(), 'title' => 'Kyiv'],
                ],
            ]],
            $this->declarativeRows(),
        );
    }

    public function testAnAdminReceivesTheDeclarativeRowAsBefore(): void
    {
        $this->bootDeclarative();

        new DeclarativeWireTestBrowser(viewer: false)->subscribeSnapshot(DeclarativeWireTestBrowser::PAGE, self::ACCEPT_KEY, new PageRouteParams([]));

        $this->assertSame(
            [[
                PagePayload::rowKey => 'p1',
                PagePayload::slots => [
                    DeclarativeWireTestRtContext::PEOPLE => [
                        'id' => 'p1',
                        'teamRef' => 't1',
                        'name' => 'Olena',
                        'online' => true,
                        'badge' => 'badge-p1',
                        'score' => 'score-p1',
                    ],
                    DeclarativeWireTestRtContext::TEAMS => ['teamId' => 't1', 'title' => 'Kyiv'],
                ],
            ]],
            $this->declarativeRows(),
        );
    }

    /**
     * Mounts the two runtime sources of the declarative table: one person and the team it points at.
     */
    private function bootDeclarative(): void
    {
        Hilos::$sr = new SignalRouter();
        $rt = new DeclarativeWireTestRtContext();
        $rt->configure();
        $rt->add(DeclarativeWireTestRtContext::PEOPLE, new DeclarativeWireTestState('p1', ['teamRef' => 't1', 'name' => 'Olena', 'online' => true]));
        $rt->add(DeclarativeWireTestRtContext::TEAMS, new DeclarativeWireTestState('t1', ['title' => 'Kyiv']));
        Hilos::$rt = $rt;
    }

    /**
     * @return mixed The rows of the declarative table in the page answer the subscription queued
     */
    private function declarativeRows(): mixed
    {
        $answer = $this->nextSignal(SignalTypeConstants::PAGE_RESPONSE, PageResponseSignalData::class)->toArray();

        return $answer[PageResponseSignalData::payload][PagePayload::tables][DeclarativeWireTestBrowser::TABLE][PagePayload::rows] ?? null;
    }

    /**
     * Boots the table, the page subscription and the browser context whose one question answers as told.
     *
     * @param bool $viewer Whether the connection is a viewer of the admin view mode
     * @param list<string> $rows Keys of the sample rows the table starts with
     * @param array<string, string> $searchable Fields the table declares the search over
     * @return WireTestBrowser Booted browser context, also mounted on the facade
     */
    private function boot(
        bool $viewer,
        array $rows = ['alpha', 'beta', 'gamma'],
        array $searchable = ['seen' => 'seen'],
    ): WireTestBrowser {
        $db = new WireTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new SignalRouter();
        WireTestTable::$rows = [];
        foreach ($rows as $key) {
            WireTestTable::$rows[$key] = WireTestRow::sample($key);
        }
        WireTestTable::$searchable = $searchable;
        Hilos::$table = new WireTestTableContext();
        Hilos::$table->configure();
        Hilos::$sr->subscribeToPage(
            WireTestBrowser::PAGE,
            new WebSocketPageSubscribeSignalDTO(self::ACCEPT_KEY, WireTestBrowser::PAGE),
        );

        $browser = new WireTestBrowser($viewer);
        WireTestHilos::initBrowser($browser);

        return $browser;
    }

    /**
     * Asks for a window the way a tab's window frame does: narrowed for a viewer, held, then served.
     *
     * @param WireTestBrowser $browser Booted browser context
     * @param ?TableSortOrderDTO $sort Order the tab asks for
     * @param ?string $search Search term the tab types, or null for none
     * @param int $limit Window size
     * @return TableViewportSubscription The window the connection holds
     */
    private function sendWindow(
        WireTestBrowser $browser,
        ?TableSortOrderDTO $sort,
        ?string $search = null,
        int $limit = 2,
    ): TableViewportSubscription {
        $viewport = $browser->viewportForViewer(
            WireTestBrowser::PAGE,
            self::ACCEPT_KEY,
            new TableViewportSubscription(
                tableKey: WireTestTable::TABLE,
                filter: $search === null ? [] : [TableConstants::FILTER_KEY_SEARCH => $search],
                sort: $sort,
                limit: $limit,
            ),
        );
        Hilos::$sr?->setTableViewport(self::ACCEPT_KEY, $viewport);
        $this->assertTrue($browser->sendTableWindow(WireTestBrowser::PAGE, self::ACCEPT_KEY, $viewport));

        return $viewport;
    }

    /**
     * Asserts the next queued signal is the named frame to the connection and returns its payload.
     *
     * @template T of SignalDataInterface
     * @param string $name Signal name expected
     * @param class-string<T> $class Payload class expected
     * @return T The payload
     */
    private function nextSignal(string $name, string $class): SignalDataInterface
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame($name, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
        $this->assertInstanceOf($class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * @return array<string, mixed> The `windows` entry of the table in the page answer the subscription queued
     */
    private function windowSection(): array
    {
        $answer = $this->nextSignal(SignalTypeConstants::PAGE_RESPONSE, PageResponseSignalData::class)->toArray();
        $window = $answer[PageResponseSignalData::payload][PagePayload::windows][WireTestTable::TABLE] ?? null;
        $this->assertIsArray($window);

        return $window;
    }

    /**
     * @param string $key Sample row key
     * @param ?string $seen Last activity, or null for the sample's own
     * @param ?string $name Name, or null for the sample's own
     * @return array<string, mixed> The wire row an admin receives
     */
    private static function plainRow(string $key, ?string $seen = null, ?string $name = null): array
    {
        $row = WireTestRow::sample($key, $seen, $name);

        return [
            PagePayload::rowKey => $key,
            PagePayload::slots => WireTestTable::slotsOf($row),
        ];
    }

    /**
     * @param string $key Sample row key
     * @param ?string $seen Last activity, or null for the sample's own
     * @return array<string, mixed> The wire row a viewer receives
     */
    private static function hiddenRow(string $key, ?string $seen = null): array
    {
        $row = WireTestRow::sample($key, $seen);

        return [
            PagePayload::rowKey => $key,
            PagePayload::slots => [
                WireTestTable::SLOT_PERSON => [
                    'key' => $key,
                    'seen' => $row->seen,
                    'name' => HiddenValue::mark(),
                    'members' => [['name' => HiddenValue::mark(), 'online' => false]],
                ],
                WireTestTable::SLOT_PRESENCE => ['online' => true],
                'note' => HiddenValue::mark(),
                WireTestTable::SLOT_HISTORY => [
                    ['key' => HiddenValue::mark(), 'seen' => '2026-08-01', 'name' => HiddenValue::mark()],
                    HiddenValue::mark(),
                ],
            ],
        ];
    }
}

final class WireTestAdminPage extends AbstractPage
{
    public const string PAGE = 'admin_view_mode_wire_page';

    public const PageAccessLevel ACCESS_LEVEL = PageAccessLevel::ADMIN;
}

final class WireTestHilos extends Hilos
{
    public const array PAGES = [WireTestAdminPage::PAGE => WireTestAdminPage::class];

    /**
     * @return HilosDbContext Test DB context
     */
    protected static function createDb(): HilosDbContext
    {
        return new WireTestDbContext();
    }
}

final class WireTestDbContext extends HilosDbContext
{
}

/**
 * A browser context whose connection is an admin the gate lets through, and whose one question is told.
 */
final class WireTestBrowser extends BrowserContext
{
    public const string PAGE = WireTestAdminPage::PAGE;

    public const string SIGNAL = 'admin_view_mode_wire_signal';

    /**
     * @param bool $viewer What the one question answers
     */
    public function __construct(private readonly bool $viewer)
    {
        parent::__construct();
    }

    /**
     * Answers as the test was told, standing in for the gate HIL-1251 opens.
     *
     * @param string $pageClass Class of the page the frame belongs to
     * @param string $acceptKey Connection the frame goes to
     * @return bool The answer the test was built with
     */
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }

    /**
     * Returns an admin behind every connection, so the page gate lets the window through.
     *
     * @param string $acceptKey Acting connection accept key (unused in the fixture)
     * @return ConnectionIdentity Settled identity of user 7
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(7);
    }

    /**
     * @param int $userId Authenticated durable user id (unused in the fixture)
     * @return bool Always an admin
     */
    public function isAdmin(int $userId): bool
    {
        return true;
    }

    /**
     * @return ?PiiRegistry The person's name is personal; the id and the last activity are not
     */
    protected function viewerPiiRegistry(): ?PiiRegistry
    {
        return new PiiRegistry(
            [0 => ['hilos_user' => ['name' => AnonymizationStrategy::FAKE_NAME]]],
            [0 => ['hilos_user' => ['id', 'last_activity']]],
        );
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return $page === self::PAGE ? BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => self::SIGNAL]) : null;
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return $page === self::PAGE ? BrowserPageBindings::fromArray([WireTestTable::TABLE => []]) : BrowserPageBindings::empty();
    }
}

final class WireTestTableContext extends TableContext
{
    public function configure(): void
    {
        $this->register(WireTestTable::TABLE, new WireTestTable());
    }
}

final class WireTestTable extends TableDefinition implements SelfSnapshotTable
{
    public const string TABLE = 'adminViewModeWireTable';
    public const string SOURCE_KEY = 'adminViewModeWireSource';
    public const string PROGRESS_SOURCE_KEY = 'adminViewModeWireProgress';
    public const string SLOT_PERSON = 'person';
    public const string SLOT_PRESENCE = 'presence';
    public const string SLOT_HISTORY = 'history';

    /** @var array<string, WireTestRow> Rows the table holds, by key, in arrival order */
    public static array $rows = [];

    /** @var array<string, string> Fields the table declares the search over */
    public static array $searchable = [];

    /**
     * @return array<string, WireField> The last activity from its column, the name from its, presence computed
     */
    public function wireFields(): array
    {
        return [
            'seen' => WireField::column(HilosDbContext::users, 'lastActivity'),
            'name' => WireField::column(HilosDbContext::users, 'name'),
            'online' => WireField::notPersonal(),
            'members' => WireField::each(['online' => WireField::notPersonal()]),
        ];
    }

    /**
     * @return ?TableSortOrderDTO First window ordered by the person's name
     */
    public function defaultSort(): ?TableSortOrderDTO
    {
        return TableSortOrderDTO::of(new TableSortDTO('name'));
    }

    /**
     * @return int Two rows per first window
     */
    public function windowSize(): int
    {
        return 2;
    }

    /**
     * @return list<TableProgressDTO> One bar running on the whole table
     */
    public function progressSnapshot(): array
    {
        return [self::bar()];
    }

    /**
     * @param SourceChange $change Source change that may report work
     * @return ?TableProgressDTO The bar, for the progress source
     */
    public function buildProgressForSourceEvent(SourceChange $change): ?TableProgressDTO
    {
        return $change->sourceKey === self::PROGRESS_SOURCE_KEY ? self::bar() : null;
    }

    /**
     * @param string|int $rowKey Row key to place against the set
     * @param TableQueryDTO $query Window query
     * @return ?bool Whether the table holds the row
     */
    public function containsRow(string|int $rowKey, TableQueryDTO $query): ?bool
    {
        return isset(self::$rows[(string)$rowKey]);
    }

    /**
     * @param SourceChange $change Source change that may affect this table
     * @return ?TableRowMutationDTO Row mutation, or null for another source
     */
    public function buildMutationForSourceEvent(SourceChange $change): ?TableRowMutationDTO
    {
        if ($change->sourceKey !== self::SOURCE_KEY) {
            return null;
        }

        return $this->mutation($change->mutationType, (string)$change->sourceId, self::$rows[(string)$change->sourceId] ?? null);
    }

    /**
     * @param AbstractTableRow $row Table row
     * @return array{rowKey: int|string, sources: array<string, mixed>} Internal browser-row envelope
     * @throws TableRowKeyMissingException When the row is a placeholder and carries no key
     */
    public function browserRow(AbstractTableRow $row): array
    {
        return [
            BrowserPageSignalData::rowKey => $row->requireRowKey(),
            BrowserPageSignalData::sources => self::slotsOf($row),
        ];
    }

    /**
     * Splits a row into its slots: an object, an object of its own, a lone value and a list of objects.
     *
     * @param AbstractTableRow $row Table row
     * @return array<string, mixed> Slots as an admin receives them
     */
    public static function slotsOf(AbstractTableRow $row): array
    {
        $fields = $row->toArray();

        return [
            self::SLOT_PERSON => [
                'key' => $fields['key'],
                'seen' => $fields['seen'],
                'name' => $fields['name'],
                'members' => [['name' => 'Taras', 'online' => false]],
            ],
            self::SLOT_PRESENCE => ['online' => true],
            'note' => 'called on Monday',
            // The key field of a related entity, not of this row.
            self::SLOT_HISTORY => [['key' => 'visit-1', 'seen' => '2026-08-01', 'name' => 'Olena'], 'stray'],
        ];
    }

    /**
     * @return array<string, string> Sortable fields, the payload keys themselves
     */
    protected function sortableFields(): array
    {
        return ['key' => 'key', 'seen' => 'seen', 'name' => 'name'];
    }

    /**
     * @return array<string, string> Searched fields, as the test set them
     */
    protected function searchableFields(): array
    {
        return self::$searchable;
    }

    protected function init(): void
    {
        $this->setRowClass(WireTestRow::class);
    }

    /**
     * @param TableQueryDTO $query Window query parameters
     * @return TableSnapshotDTO Windowed snapshot
     */
    protected function query(TableQueryDTO $query): TableSnapshotDTO
    {
        return $this->filterInMemory(
            array_values(array_map(static fn(WireTestRow $row): array => $row->toArray(), self::$rows)),
            $query,
        );
    }

    /**
     * @return TableProgressDTO A bar whose detail is the project's payload
     */
    private static function bar(): TableProgressDTO
    {
        return new TableProgressDTO(TableProgressScope::Table, 'run', null, 3, 10, false, ['file' => 'olena.csv', 'phase' => 'read']);
    }
}

final class WireTestRow extends AbstractTableRow
{
    private const array SEEN = ['alpha' => '2026-09-01', 'beta' => '2026-09-02', 'gamma' => '2026-09-03'];

    private const array NAMES = ['alpha' => 'Olena', 'beta' => 'Anna', 'gamma' => 'Petro'];

    public function __construct(
        public readonly string $key,
        public readonly string $seen,
        public readonly string $name,
    ) {
    }

    /**
     * @param string $key Sample row key
     * @param ?string $seen Last activity, or null for the sample's own
     * @param ?string $name Name, or null for the sample's own
     * @return self The sample row
     */
    public static function sample(string $key, ?string $seen = null, ?string $name = null): self
    {
        return new self($key, $seen ?? self::SEEN[$key], $name ?? self::NAMES[$key]);
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
        return ['key' => $this->key, 'seen' => $this->seen, 'name' => $this->name];
    }

    /**
     * @param array<string, mixed> $data Source data
     * @return static Row instance
     */
    public static function fromArray(array $data): static
    {
        return new static((string)$data['key'], (string)$data['seen'], (string)$data['name']);
    }
}

/**
 * A declarative table over two runtime sources, the second joined through a field of the first.
 */
final class DeclarativeWireTestBrowser extends BrowserContext
{
    public const string PAGE = WireTestAdminPage::PAGE;

    public const string TABLE = 'adminViewModeDeclarativeRows';

    /**
     * @param bool $viewer What the one question answers
     */
    public function __construct(private readonly bool $viewer)
    {
        parent::__construct();
        $this->bindHilosFacade(WireTestHilos::class);
    }

    /**
     * @param string $pageClass Class of the page the frame belongs to
     * @param string $acceptKey Connection the frame goes to
     * @return bool The answer the test was built with
     */
    public function isAdminViewModeViewer(string $pageClass, string $acceptKey): bool
    {
        return $this->viewer;
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return ?BrowserPageConfig Page metadata, or null when absent
     * @throws PageInternalErrorException When a page or source declaration is malformed
     */
    protected function resolveBrowserPageConfig(string $page): ?BrowserPageConfig
    {
        return $page === self::PAGE ? BrowserPageConfig::fromArray([BrowserConfigKey::SIGNAL => WireTestBrowser::SIGNAL]) : null;
    }

    /**
     * @param string $page Page name from the subscription mirror
     * @return BrowserPageBindings Page table bindings
     */
    protected function resolveBrowserPageBindings(string $page): BrowserPageBindings
    {
        return $page === self::PAGE ? BrowserPageBindings::fromArray([self::TABLE => []]) : BrowserPageBindings::empty();
    }

    /**
     * @param string $browserKey Browser table key
     * @return ?BrowserSourceConfig The declarative table
     */
    protected function resolveBrowserOnlyConfig(string $browserKey): ?BrowserSourceConfig
    {
        if ($browserKey !== self::TABLE) {
            return null;
        }

        return BrowserSourceConfig::fromArray([
            BrowserTableConfigKey::ROWS => [
                [
                    BrowserTableFieldKey::SOURCE => [
                        BrowserSourceKey::TYPE => BrowserSourceType::RT,
                        BrowserSourceKey::KEY => DeclarativeWireTestRtContext::PEOPLE,
                    ],
                    BrowserTableFieldKey::ROW_KEY => 'id',
                    BrowserTableFieldKey::FIELDS => ['id', 'teamRef', 'name', 'online'],
                    BrowserTableFieldKey::COMPUTED => ['badge', 'score'],
                    BrowserTableFieldKey::NOT_PERSONAL => ['online', 'badge'],
                ],
                [
                    BrowserTableFieldKey::SOURCE => [
                        BrowserSourceKey::TYPE => BrowserSourceType::RT,
                        BrowserSourceKey::KEY => DeclarativeWireTestRtContext::TEAMS,
                    ],
                    BrowserTableFieldKey::VIA => ['id' => 'teamRef'],
                    BrowserTableFieldKey::FIELDS => ['id' => 'teamId', 'title'],
                    BrowserTableFieldKey::NOT_PERSONAL => ['title'],
                ],
            ],
        ]);
    }

    /**
     * @param string $browserKey Browser table key
     * @param string $field Computed field name
     * @param int|string $rowKey Logical browser table row key
     * @param string $acceptKey Subscriber accept key
     * @param array<string, string> $pageParams Current page subscription params
     * @param array<string, mixed> $browserParams Resolved table params
     * @param array<string, mixed> $sources Source fragments already built for the row
     * @return mixed Computed browser field value
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
        return "{$field}-{$rowKey}";
    }
}

final class DeclarativeWireTestRtContext extends RtContext
{
    public const string PEOPLE = 'adminViewModePeople';

    public const string TEAMS = 'adminViewModeTeams';

    public function configure(): void
    {
        $this->_stateCollections[self::PEOPLE] = DeclarativeWireTestPeopleStates::init();
        $this->setRepresent(self::PEOPLE, DeclarativeWireTestCollection::class);
        $this->_stateCollections[self::TEAMS] = DeclarativeWireTestTeamStates::init();
        $this->setRepresent(self::TEAMS, DeclarativeWireTestCollection::class);
    }

    /**
     * @param string $collection Collection the row goes into
     * @param DeclarativeWireTestState $row Runtime row
     */
    public function add(string $collection, DeclarativeWireTestState $row): void
    {
        $this->_stateCollections[$collection]->add($row);
    }
}

final class DeclarativeWireTestPeopleStates extends RtStates
{
    public const string STATE_CLASS = DeclarativeWireTestState::class;
}

final class DeclarativeWireTestTeamStates extends RtStates
{
    public const string STATE_CLASS = DeclarativeWireTestState::class;
}

final class DeclarativeWireTestState extends RtState
{
    /**
     * @param string $id Row id
     * @param array<string, mixed> $fields The rest of the row
     */
    public function __construct(
        public readonly string $id,
        public readonly array $fields,
    ) {
        parent::__construct();
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Runtime row
     */
    public static function fromRow(array $row): static
    {
        $id = (string)$row['id'];
        unset($row['id']);

        return new static($id, $row);
    }

    /**
     * @return array<string, mixed> Runtime row
     */
    public function toArray(): array
    {
        return ['id' => $this->id] + $this->fields;
    }
}

final class DeclarativeWireTestCollection extends RtCollection
{
    protected function createRtItem(RtState $state): RtItem
    {
        return new DeclarativeWireTestItem($state);
    }
}

final class DeclarativeWireTestItem extends RtItem
{
    public function __get(string $name): mixed
    {
        return $this->toArray()[$name] ?? parent::__get($name);
    }

    /**
     * @return array<string, mixed> Runtime row
     */
    public function toArray(): array
    {
        return $this->getState()->toArray();
    }
}
