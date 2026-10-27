<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\AdminViewMode\HiddenValue;
use Hilos\Core\Analytics\AnalyticsSectionReader;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Core\Table\DTO\TableAnchorDTO;
use Hilos\Core\Table\DTO\TableQueryDTO;
use Hilos\Core\Table\DTO\TableSnapshotDTO;
use Hilos\Core\Table\DTO\TableSortDTO;
use Hilos\Core\Table\DTO\TableSortOrderDTO;
use Hilos\Core\Table\Row\AbstractTableRow;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Schema\Schema;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsActionTableRow;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTableRow;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionTableRow;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\Analytics\HilosAnalyticsPersonTableRow;
use Hilos\Utils\Helpers\TimeHelper;
use ReflectionMethod;

/** Frozen analytics tables over the shipped schema, including viewer-safe window addresses. */
final class AnalyticsTablesIntegrationTest extends AnalyticsSchemaIntegrationTestCase
{
    private const array PEOPLE_CONTEXT = ['timeZone' => 'UTC', 'weekStartTs' => 100, 'dayStartTs' => 200, 'hourStartTs' => 300];
    private const array PEOPLE_FACETS = ['period' => ['this-hour', 'today', 'this-week']];

    private ?DbContext $previousDb = null;

    /** @throws HilosException When the account schema or context cannot be prepared */
    protected function setUp(): void
    {
        parent::setUp();
        Database::sqlRun('DROP TABLE IF EXISTS `hilos_user`');
        Database::sqlRun((string)file_get_contents(
            dirname(__DIR__, 2) . '/backend/Database/Migration/Stub/create_hilos_user.sql',
        ));
        Schema::reset();
        Schema::initialize();
        $this->previousDb = Hilos::$db;
        $db = new AnalyticsTablesTestDbContext();
        $db->configure();
        Hilos::$db = $db;
    }

    /** @throws HilosException When the account or analytics schema cannot be dropped */
    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
        Database::sqlRun('DROP TABLE IF EXISTS `hilos_user`');
        Schema::reset();
        parent::tearDown();
    }

    /** @throws HilosException When the schema cannot be removed */
    public function testColdWindowsWithoutAPresetNeedNoSql(): void
    {
        $this->dropWindowTables();
        foreach ([new HilosAnalyticsActionsTable(), new HilosAnalyticsBrowserSessionsTable(),
            new HilosAnalyticsPeopleTable(), new HilosAnalyticsBrowserSessionListTable()] as $table) {
            $snapshot = $table->getPage(new TableQueryDTO(limit: $table->windowSize(), sort: $table->defaultSort()));
            self::assertSame([], $snapshot->rows);
            self::assertSame(0, $snapshot->totalCount);
            self::assertTrue($snapshot->totalExact);
            self::assertSame(0, $snapshot->rowsBefore);
            self::assertSame(25, $snapshot->limit);
            self::assertNull($snapshot->firstAnchor);
            self::assertNull($snapshot->lastAnchor);
            self::assertNull($snapshot->frame);
            self::assertSame([], $table->wireFields());
        }
    }

    public function testColdPeopleFacetsNeedNoSql(): void
    {
        $this->dropWindowTables();
        $facets = new HilosAnalyticsPeopleTable()->facetCounts(new TableQueryDTO(), self::PEOPLE_FACETS);
        self::assertSame(['period'], array_keys($facets));
        self::assertSame(['this-hour', 'today', 'this-week'], array_keys($facets['period']['options']));
        foreach ([$facets['period']['any'], ...array_values($facets['period']['options'])] as $count) {
            self::assertSame(['count' => 0, 'exact' => true], $count->toArray());
        }
    }

    public function testPeopleContextAndUnsafeAnchorsRefuseBeforeSql(): void
    {
        $this->dropWindowTables();
        $table = new HilosAnalyticsPeopleTable();
        foreach ([['period' => 'today'], ['timeZone' => 'UTC'],
            ['period' => null] + self::PEOPLE_CONTEXT, ['period' => 'yesterday'] + self::PEOPLE_CONTEXT,
            ['hourStartTs' => '300'] + self::PEOPLE_CONTEXT,
            ['timeZone' => 'invalid'] + self::PEOPLE_CONTEXT,
            ['dayStartTs' => 301] + self::PEOPLE_CONTEXT,
            ['hourStartTs' => TimeHelper::nowMs() + 60000] + self::PEOPLE_CONTEXT] as $filter) {
            foreach (['window', 'facets'] as $operation) {
                try {
                    $query = new TableQueryDTO(filter: $filter);
                    if ($operation === 'window') {
                        $table->getPage($query);
                    } else {
                        $table->facetCounts($query, self::PEOPLE_FACETS);
                    }
                    self::fail('An invalid calendar context must refuse before SQL');
                } catch (ValidationException $exception) {
                    self::assertNotSame('', $exception->getMessage());
                }
            }
        }
        foreach ([null, $table->defaultSort()] as $sort) {
            $invalid = [[], ['rowKey' => '1'], ['rowKey' => 1.0], ['rowKey' => true],
                ['rowKey' => null], ['rowKey' => 0], ['rowKey' => -1], ['rowKey' => 9007199254740992]];
            if ($sort !== null) {
                array_push($invalid, ['rowKey' => 1], ['rowKey' => 1, 'lastSeenTs' => '300'],
                    ['rowKey' => 1, 'lastSeenTs' => -1], ['rowKey' => 1, 'lastSeenTs' => 9007199254740992]);
            }
            foreach ($invalid as $anchor) {
                try {
                    $table->getPage(new TableQueryDTO(filter: self::PEOPLE_CONTEXT, sort: $sort, anchor: new TableAnchorDTO($anchor)));
                    self::fail('An unsafe anchor must refuse before SQL');
                } catch (ValidationException $exception) {
                    self::assertStringContainsString('anchor', $exception->getMessage());
                }
            }
        }
    }

    public function testPeoplePeriodsNamesAndExactBrowserShape(): void
    {
        Database::sql('INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, ?), (8, ?), (9, ?)',
            ['Current name', 'Second person', 'Deleted private name']);
        Database::sql('DELETE FROM `hilos_user` WHERE `id` = 9');
        $this->session('old_7', 7, 0, 199);
        $this->session('day_7', 7, 0, 200, 'private raw UA');
        $this->session('hour_7', 7, 250, 350);
        $this->session('day_8', 8, 200, 250);
        $this->session('hour_deleted', 9, 300, 300);
        $this->session('week_10', 10, 100, 199);
        $this->session('any_11', 11, 0, 99);
        $this->session('guest', null, 0, 400);
        $this->session('future', 12, TimeHelper::nowMs() + 60000, TimeHelper::nowMs() + 60000);
        $table = new HilosAnalyticsPeopleTable();
        foreach ([[null, [7, 8, 9, 10, 11], 3], ['this-week', [7, 8, 9, 10], 3],
            ['today', [7, 8, 9], 2], ['this-hour', [7, 9], 1]] as [$period, $expected, $sessions]) {
            $filter = $period === null ? self::PEOPLE_CONTEXT : ['period' => $period] + self::PEOPLE_CONTEXT;
            $window = $table->getPage(new TableQueryDTO(filter: $filter));
            self::assertSame($expected, self::rowKeys($window));
            self::assertSame(count($expected), $window->totalCount);
            self::assertSame($sessions, $window->rows[0]->sessionCount);
            self::assertSame('Current name', $window->rows[0]->name);
            self::assertSame(350, $window->rows[0]->lastSeenTs);
            self::assertNull($window->rows[array_search(9, $expected, true)]->name);
        }
        $query = new TableQueryDTO(filter: ['period' => 'today'] + self::PEOPLE_CONTEXT, sort: $table->defaultSort());
        $row = $table->getPage($query)->rows[0];
        self::assertSame(['rowKey' => 7, 'name' => 'Current name', 'sessionCount' => 2, 'lastSeenTs' => 350], $row->toArray());
        self::assertSame($row->toArray(), HilosAnalyticsPersonTableRow::fromArray($row->toArray())->toArray());
        self::assertSame([
            'rowKey' => 7, 'sources' => ['person' => ['name' => 'Current name', 'sessionCount' => 2, 'lastSeenTs' => 350]],
        ], json_decode(json_encode($table->browserRow($row), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        Hilos::$db->users[7]->actions->rename('Renamed person');
        self::assertSame('Renamed person', $table->getPage($query)->rows[0]->name);
        self::assertSame('Current name', $row->name);

        $expectedCounts = ['any' => 5, 'this-hour' => 2, 'today' => 3, 'this-week' => 4];
        foreach ([self::PEOPLE_CONTEXT, ['period' => 'this-hour'] + self::PEOPLE_CONTEXT] as $filter) {
            $facets = $table->facetCounts(new TableQueryDTO(filter: $filter, limit: 1), self::PEOPLE_FACETS);
            foreach ($expectedCounts as $key => $count) {
                $actual = $key === 'any' ? $facets['period']['any'] : $facets['period']['options'][$key];
                self::assertSame(['count' => $count, 'exact' => true], $actual->toArray());
            }
        }
    }

    public function testPeopleFacetsOnlyCountAllowedKeysAndValues(): void
    {
        $this->dropWindowTables();
        $table = new HilosAnalyticsPeopleTable();
        $query = new TableQueryDTO(filter: self::PEOPLE_CONTEXT);
        self::assertSame([], $table->facetCounts($query, ['arbitrary' => ['today'], 'timeZone' => ['UTC']]));
        $cold = $table->facetCounts(new TableQueryDTO(), ['period' => ['today', 'yesterday', 1, true, 'today'], 'name' => ['secret']]);
        self::assertSame(['period'], array_keys($cold));
        self::assertSame(['today'], array_keys($cold['period']['options']));
        self::assertSame(['count' => 0, 'exact' => true], $cold['period']['any']->toArray());
    }

    public function testPeoplePagingUsesGroupedCountsAndNumericTiesInBothDirections(): void
    {
        foreach ([2 => 400, 10 => 100, 100 => 100, 7 => 300, 8 => 200, 50 => 500, 70 => 600] as $userId => $moment) {
            $this->session('early_' . $userId, $userId, 0, 50);
            $this->session('late_' . $userId, $userId, 0, $moment);
        }
        $table = new HilosAnalyticsPeopleTable();
        self::assertSame(25, $table->windowSize());
        self::assertSame('lastSeenTs', $table->defaultSort()->last()->field);
        self::assertSame(TableConstants::ORDER_DESC, $table->defaultSort()->last()->direction);
        $ascending = [10, 100, 8, 7, 2, 50, 70];
        foreach ([[$table->defaultSort(), array_reverse($ascending)],
            [TableSortOrderDTO::of(new TableSortDTO('lastSeenTs', TableConstants::ORDER_ASC)), $ascending],
            [null, [2, 7, 8, 10, 50, 70, 100]]] as [$sort, $expected]) {
            $this->assertWindowSequence($table, self::PEOPLE_CONTEXT, $sort, $expected);
            $first = $table->getPage(new TableQueryDTO(filter: self::PEOPLE_CONTEXT, sort: $sort, limit: 2));
            $next = $table->getPage(new TableQueryDTO(filter: self::PEOPLE_CONTEXT, sort: $sort, limit: 2, anchor: $first->lastAnchor));
            foreach ([...$first->rows, ...$next->rows] as $row) {
                self::assertSame(2, $row->sessionCount);
            }
        }
        $first = $table->getPage(new TableQueryDTO(filter: self::PEOPLE_CONTEXT, sort: $table->defaultSort(), limit: 2));
        self::assertSame(['lastSeenTs' => 600, 'rowKey' => 70], $first->firstAnchor->values);
    }

    public function testPeopleCountCeilingFacetsAndMaximumWindow(): void
    {
        for ($userId = 1; $userId <= 500; $userId++) {
            $this->session('person_' . $userId, $userId, 100, 300);
        }
        $table = new HilosAnalyticsPeopleTable();
        $query = new TableQueryDTO(filter: self::PEOPLE_CONTEXT, limit: $table->windowSize());
        $initial = $table->getPage($query);
        self::assertCount(25, $initial->rows);
        self::assertSame(500, $initial->totalCount);
        self::assertTrue($initial->totalExact);
        foreach ([$table->facetCounts($query, self::PEOPLE_FACETS)['period']['any'],
            ...array_values($table->facetCounts($query, self::PEOPLE_FACETS)['period']['options'])] as $count) {
            self::assertSame(['count' => 500, 'exact' => true], $count->toArray());
        }
        $this->session('overflow_person', 501, 100, 300);
        foreach ([TableConstants::NO_LIMIT, 1000] as $limit) {
            $overflow = $table->getPage(new TableQueryDTO(filter: self::PEOPLE_CONTEXT, limit: $limit));
            self::assertCount(50, $overflow->rows);
            self::assertSame(50, $overflow->limit);
            self::assertSame(500, $overflow->totalCount);
            self::assertFalse($overflow->totalExact);
            self::assertNull($overflow->rowsBefore);
        }
        $facet = $table->facetCounts($query, self::PEOPLE_FACETS)['period'];
        foreach ([$facet['any'], ...array_values($facet['options'])] as $count) {
            self::assertSame(['count' => 500, 'exact' => false], $count->toArray());
        }
        $last = $table->getPage(new TableQueryDTO(filter: self::PEOPLE_CONTEXT, limit: 25, pageIndex: 20));
        self::assertSame([501], self::rowKeys($last));
        self::assertNull($last->rowsBefore);
        $past = $table->getPage(new TableQueryDTO(filter: self::PEOPLE_CONTEXT, limit: 25, pageIndex: 21));
        self::assertSame([], $past->rows);
        self::assertFalse($past->totalExact);
    }

    public function testPeopleViewerHidesSlotFieldsAndNeverReturnsOldMoments(): void
    {
        Database::sql('INSERT INTO `hilos_user` (`id`, `name`) VALUES (2, ?), (10, ?)', ['Private first', 'Private second']);
        $this->session('private_token_2', 2, 100, 600, 'Private UA');
        $this->session('private_token_10', 10, 100, 300);
        $table = new HilosAnalyticsPeopleTable();
        self::assertSame([], $table->wireFields());
        $first = $table->getPage(new TableQueryDTO(
            filter: self::PEOPLE_CONTEXT, sort: $table->defaultSort(), limit: 1, shownFields: ['rowKey'],
        ));
        self::assertSame([2], self::rowKeys($first));
        self::assertSame(2, $first->totalCount);
        self::assertSame(['rowKey' => 2], $first->firstAnchor->values);
        $next = $table->getPage(new TableQueryDTO(
            filter: self::PEOPLE_CONTEXT, sort: $table->defaultSort(), limit: 1, shownFields: ['rowKey'],
            anchor: new TableAnchorDTO(['rowKey' => 2, 'lastSeenTs' => 'old private moment']),
        ));
        self::assertSame([10], self::rowKeys($next));
        self::assertSame(['rowKey' => 10], $next->lastAnchor->values);
        self::assertSame(1, $next->rowsBefore);
        $previous = $table->getPage(new TableQueryDTO(
            filter: self::PEOPLE_CONTEXT, sort: $table->defaultSort(), limit: 1, shownFields: ['rowKey'],
            anchor: $next->firstAnchor, anchorDirection: TableAnchorDirection::Before,
        ));
        self::assertSame([2], self::rowKeys($previous));
        $wireMethod = new ReflectionMethod(BrowserContext::class, 'browserRowToWire');
        $browser = new class extends BrowserContext {
        };
        foreach ([$first, $next] as $window) {
            $wire = $wireMethod->invoke($browser, $table->browserRow($window->rows[0]), $table);
            self::assertSame(['rowKey' => $window->rows[0]->rowKey, 'slots' => ['person' => [
                'name' => HiddenValue::mark(), 'sessionCount' => HiddenValue::mark(), 'lastSeenTs' => HiddenValue::mark(),
            ]]], json_decode(json_encode($wire, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        }
        $facet = $table->facetCounts(new TableQueryDTO(filter: self::PEOPLE_CONTEXT, shownFields: ['rowKey']), self::PEOPLE_FACETS)['period'];
        self::assertSame(['count' => 2, 'exact' => true], $facet['any']->toArray());
        self::assertSame(['count' => 2, 'exact' => true], $facet['options']['this-hour']->toArray());
    }

    /** @throws HilosException When the schema cannot be removed */
    public function testMalformedFiltersAndAnchorsAreRefusedBeforeSql(): void
    {
        $this->dropWindowTables();
        foreach ([
            [new HilosAnalyticsActionsTable(), HilosAnalyticsActionsTable::FILTER_BROWSER_SESSION, HilosAnalyticsActionTableRow::createdTs],
            [new HilosAnalyticsBrowserSessionsTable(), HilosAnalyticsBrowserSessionsTable::FILTER_USER,
                HilosAnalyticsBrowserSessionTableRow::lastSeenTs],
        ] as [$table, $filterKey, $timeKey]) {
            foreach (['1', 1.0, 0, -1, null, true, []] as $filter) {
                $this->assertInvalidQuery($table, new TableQueryDTO(filter: [$filterKey => $filter]), 'filter');
            }
            foreach ([[], ['rowKey' => '1'], ['rowKey' => null], ['rowKey' => 1],
                ['rowKey' => 1, $timeKey => '100'], ['rowKey' => 1, $timeKey => null], [$timeKey => 100]] as $anchor) {
                $this->assertInvalidQuery($table, new TableQueryDTO(
                    filter: [$filterKey => 1], sort: $table->defaultSort(), anchor: new TableAnchorDTO($anchor),
                ), 'anchor');
            }
            $this->assertInvalidQuery($table, new TableQueryDTO(
                filter: [$filterKey => 1], anchor: new TableAnchorDTO(['rowKey' => 1.0]),
            ), 'anchor');
        }
    }

    /** @throws HilosException When a fixture or table window query fails */
    public function testActionsServeTimeOrdersAndBidirectionalWindows(): void
    {
        $session = $this->session('actions', 7, 100, 200);
        $connection = $this->connection('actions', $session);
        $pageSession = $this->pageSession($connection, 'settings');
        $ids = [];
        foreach ([400, 100, 100, 300, 200, 500, 600] as $index => $moment) {
            $ids[] = $this->action($connection, $moment, 'action_' . $index, $index === 1 ? $pageSession : null, $index === 1);
        }
        $this->action($this->connection('other', $this->session('other', 8, 100, 200)), 50, 'other', null);
        $this->action($this->connection('unattached', null), 50, 'unattached', null);
        $table = new HilosAnalyticsActionsTable();
        $filter = [HilosAnalyticsActionsTable::FILTER_BROWSER_SESSION => $session];
        $ascending = [$ids[1], $ids[2], $ids[4], $ids[3], $ids[0], $ids[5], $ids[6]];
        self::assertSame(TableConstants::ORDER_ASC, $table->defaultSort()->last()->direction);
        foreach ([
            [$table->defaultSort(), $ascending],
            [TableSortOrderDTO::of(new TableSortDTO(HilosAnalyticsActionTableRow::createdTs, TableConstants::ORDER_DESC)),
                array_reverse($ascending)],
            [null, $ids],
        ] as [$sort, $expected]) {
            $this->assertWindowSequence($table, $filter, $sort, $expected);
        }
        $snapshot = $table->getPage(new TableQueryDTO(filter: $filter, sort: $table->defaultSort(), limit: 2));
        self::assertSame(['createdTs' => 100, 'rowKey' => $ids[1]], $snapshot->firstAnchor->values);
        $row = $snapshot->rows[0];
        self::assertSame([
            'rowKey' => $ids[1], 'createdTs' => 100, 'pageName' => 'settings', 'actionName' => 'action_1',
        ], $row->toArray());
        self::assertSame($row->toArray(), HilosAnalyticsActionTableRow::fromArray($row->toArray())->toArray());
        self::assertSame([
            'rowKey' => $ids[1], 'sources' => ['action' => $row->toArray()],
        ], $table->browserRow($row));
        self::assertNull($snapshot->rows[1]->pageName);
        self::assertStringNotContainsString('private payload', json_encode($table->browserRow($row), JSON_THROW_ON_ERROR));
    }

    /** @throws HilosException When a fixture or table window query fails */
    public function testSessionsServeLastSeenOrdersWithoutAccountFields(): void
    {
        $ids = [];
        foreach ([400, 100, 100, 300, 200, 500, 600] as $index => $moment) {
            $ids[] = $this->session('session_' . $index, 7, 50, $moment,
                $index === 0 ? 'Windows NT 10.0 Chrome/128.0 Safari/537.36 Edg/129.0' : null);
        }
        $this->session('foreign', 8, 50, 700);
        $this->session('guest', null, 50, 700);
        $this->action($this->connection('first', $ids[0]), 90, 'first', null);
        $this->action($this->connection('second', $ids[0]), 95, 'second', null);
        $table = new HilosAnalyticsBrowserSessionsTable();
        $filter = [HilosAnalyticsBrowserSessionsTable::FILTER_USER => 7];
        $ascending = [$ids[1], $ids[2], $ids[4], $ids[3], $ids[0], $ids[5], $ids[6]];
        self::assertSame(TableConstants::ORDER_DESC, $table->defaultSort()->last()->direction);
        foreach ([
            [$table->defaultSort(), array_reverse($ascending)],
            [TableSortOrderDTO::of(new TableSortDTO(HilosAnalyticsBrowserSessionTableRow::lastSeenTs, TableConstants::ORDER_ASC)),
                $ascending],
            [null, $ids],
        ] as [$sort, $expected]) {
            $this->assertWindowSequence($table, $filter, $sort, $expected);
        }
        $snapshot = $table->getPage(new TableQueryDTO(filter: $filter, sort: $table->defaultSort(), limit: 2));
        self::assertSame(['lastSeenTs' => 600, 'rowKey' => $ids[6]], $snapshot->firstAnchor->values);
        $row = $table->getPage(new TableQueryDTO(filter: $filter, limit: 1))->rows[0];
        self::assertSame([
            'rowKey' => $ids[0], 'firstSeenTs' => 50, 'lastSeenTs' => 400, 'browser' => 'Edge 129 · Windows', 'actionCount' => 2,
        ], $row->toArray());
        self::assertSame($row->toArray(), HilosAnalyticsBrowserSessionTableRow::fromArray($row->toArray())->toArray());
        self::assertSame([
            'rowKey' => $ids[0], 'sources' => ['browserSession' => $row->toArray()],
        ], $table->browserRow($row));
        self::assertNull($snapshot->rows[0]->browser);
        self::assertSame(0, $snapshot->rows[0]->actionCount);
    }

    /** @throws HilosException When a fixture or empty window query fails */
    public function testEmptyAndMissingSubjectsGiveExactEmptyWindows(): void
    {
        $empty = $this->session('empty', 7, 100, 200);
        Database::sql('INSERT INTO `hilos_user` (`id`, `name`) VALUES (8, ?)', ['Account without sessions']);
        foreach ([
            [new HilosAnalyticsActionsTable(), [HilosAnalyticsActionsTable::FILTER_BROWSER_SESSION => $empty]],
            [new HilosAnalyticsActionsTable(), [HilosAnalyticsActionsTable::FILTER_BROWSER_SESSION => 999999]],
            [new HilosAnalyticsBrowserSessionsTable(), [HilosAnalyticsBrowserSessionsTable::FILTER_USER => 8]],
            [new HilosAnalyticsBrowserSessionsTable(), [HilosAnalyticsBrowserSessionsTable::FILTER_USER => 999999]],
        ] as [$table, $filter]) {
            $window = $table->getPage(new TableQueryDTO(filter: $filter, limit: 25, sort: $table->defaultSort()));
            self::assertSame([], $window->rows);
            self::assertSame(0, $window->totalCount);
            self::assertTrue($window->totalExact);
            self::assertSame(0, $window->rowsBefore);
            self::assertNull($window->firstAnchor);
            self::assertNull($window->lastAnchor);
        }
    }

    /** @throws HilosException When a fixture or count query fails */
    public function testCountsCapsAndNumberedPagesBeyondTheCeiling(): void
    {
        $session = $this->session('counted', 7, 100, 200);
        $connection = $this->connection('counted', $session);
        for ($index = 0; $index < TableConstants::COUNT_CEILING; $index++) {
            $this->action($connection, 100 + $index, 'count_' . $index, null);
            $this->session('count_' . $index, 8, 100, 100 + $index);
        }
        $cases = [
            [new HilosAnalyticsActionsTable(), [HilosAnalyticsActionsTable::FILTER_BROWSER_SESSION => $session]],
            [new HilosAnalyticsBrowserSessionsTable(), [HilosAnalyticsBrowserSessionsTable::FILTER_USER => 8]],
        ];
        foreach ($cases as [$table, $filter]) {
            $exact = $table->getPage(new TableQueryDTO(filter: $filter));
            self::assertSame(TableConstants::COUNT_CEILING, $exact->totalCount);
            self::assertTrue($exact->totalExact);
            self::assertSame(AnalyticsSectionReader::MAX_PAGE_ROWS, $exact->limit);
            self::assertCount(AnalyticsSectionReader::MAX_PAGE_ROWS, $exact->rows);
            self::assertSame(0, $exact->rowsBefore);
        }
        $this->action($connection, 900, 'overflow', null);
        $this->session('overflow', 8, 100, 900);
        foreach ($cases as [$table, $filter]) {
            foreach ([TableConstants::NO_LIMIT, 1000] as $limit) {
                $overflow = $table->getPage(new TableQueryDTO(filter: $filter, limit: $limit));
                self::assertSame(TableConstants::COUNT_CEILING, $overflow->totalCount);
                self::assertFalse($overflow->totalExact);
                self::assertNull($overflow->rowsBefore);
                self::assertCount(AnalyticsSectionReader::MAX_PAGE_ROWS, $overflow->rows);
                self::assertSame(AnalyticsSectionReader::MAX_PAGE_ROWS, $overflow->limit);
            }
            $atCeiling = $table->getPage(new TableQueryDTO(filter: $filter, limit: 25, pageIndex: 20));
            self::assertCount(1, $atCeiling->rows);
            self::assertNull($atCeiling->rowsBefore);
            self::assertFalse($atCeiling->totalExact);
            $pastCeiling = $table->getPage(new TableQueryDTO(filter: $filter, limit: 25, pageIndex: 21));
            self::assertSame([], $pastCeiling->rows);
            self::assertFalse($pastCeiling->totalExact);
            self::assertNull($pastCeiling->rowsBefore);
        }
    }

    /** @throws HilosException When a fixture or viewer window query fails */
    public function testViewerWindowsUseOnlyRowNumbersEvenWithOldTimeAnchors(): void
    {
        $firstSession = $this->session('first', 7, 100, 500);
        $secondSession = $this->session('second', 7, 100, 100);
        $connection = $this->connection('first', $firstSession);
        $firstAction = $this->action($connection, 500, 'later', null);
        $secondAction = $this->action($connection, 100, 'earlier', null);
        foreach ([
            [new HilosAnalyticsActionsTable(), [HilosAnalyticsActionsTable::FILTER_BROWSER_SESSION => $firstSession],
                [$firstAction, $secondAction]],
            [new HilosAnalyticsBrowserSessionsTable(), [HilosAnalyticsBrowserSessionsTable::FILTER_USER => 7],
                [$firstSession, $secondSession]],
        ] as [$table, $filter, $ids]) {
            self::assertSame([], $table->wireFields());
            foreach ([null, $table->defaultSort()] as $sort) {
                $first = $table->getPage(new TableQueryDTO(
                    filter: $filter, sort: $sort, limit: 1, shownFields: ['rowKey'],
                ));
                self::assertSame([$ids[0]], self::rowKeys($first));
                self::assertSame(['rowKey' => $ids[0]], $first->firstAnchor->values);
                self::assertSame(['rowKey' => $ids[0]], $first->lastAnchor->values);
                $next = $table->getPage(new TableQueryDTO(
                    filter: $filter, sort: $sort, limit: 1, anchor: $first->lastAnchor, shownFields: ['rowKey'],
                ));
                self::assertSame([$ids[1]], self::rowKeys($next));
                self::assertSame(['rowKey' => $ids[1]], $next->firstAnchor->values);
                self::assertSame(1, $next->rowsBefore);
                $previous = $table->getPage(new TableQueryDTO(
                    filter: $filter, sort: $sort, limit: 1, anchor: $next->firstAnchor,
                    anchorDirection: TableAnchorDirection::Before, shownFields: ['rowKey'],
                ));
                self::assertSame([$ids[0]], self::rowKeys($previous));
            }
            $oldAnchor = new TableAnchorDTO(['rowKey' => $ids[0], 'createdTs' => 'hidden', 'lastSeenTs' => 'hidden']);
            self::assertSame([$ids[1]], self::rowKeys($table->getPage(new TableQueryDTO(
                filter: $filter, anchor: $oldAnchor, limit: 1, shownFields: ['rowKey'],
            ))));
        }
    }

    /** @throws HilosException When a fixture or snapshot query fails */
    public function testARequestedWindowReadsNewlyLoadedRows(): void
    {
        $session = $this->session('snapshot', 7, 100, 200);
        $connection = $this->connection('snapshot', $session);
        $first = $this->action($connection, 100, 'first', null);
        $table = new HilosAnalyticsActionsTable();
        $query = new TableQueryDTO(filter: [HilosAnalyticsActionsTable::FILTER_BROWSER_SESSION => $session]);
        $snapshot = $table->getPage($query);
        $second = $this->action($connection, 200, 'second', null);
        self::assertSame([$first], self::rowKeys($snapshot));
        self::assertSame([$first, $second], self::rowKeys($table->getPage($query)));
        self::assertNull($snapshot->frame);
    }

    /** @throws HilosException When the schema cannot be removed or the table read fails */
    public function testSqlFailurePropagatesInsteadOfBecomingAnEmptyWindow(): void
    {
        $this->dropWindowTables();
        foreach ([
            [new HilosAnalyticsActionsTable(), [HilosAnalyticsActionsTable::FILTER_BROWSER_SESSION => 1]],
            [new HilosAnalyticsBrowserSessionsTable(), [HilosAnalyticsBrowserSessionsTable::FILTER_USER => 1]],
            [new HilosAnalyticsPeopleTable(), self::PEOPLE_CONTEXT],
            [new HilosAnalyticsBrowserSessionListTable(), self::PEOPLE_CONTEXT],
        ] as [$table, $filter]) {
            try {
                $table->getPage(new TableQueryDTO(filter: $filter));
                self::fail('A failed SQL query must refuse the window');
            } catch (DatabaseException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function testPeopleSqlFailureAlsoRefusesFacets(): void
    {
        $this->dropWindowTables();
        $this->expectException(DatabaseException::class);
        new HilosAnalyticsPeopleTable()->facetCounts(new TableQueryDTO(filter: self::PEOPLE_CONTEXT), self::PEOPLE_FACETS);
    }

    public function testSessionListPeriodsNamesAndExactInlineShape(): void
    {
        Database::sql('INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, ?), (8, ?)', ['Deleted user #9', 'Deleted private name']);
        Database::sql('DELETE FROM `hilos_user` WHERE `id` = 8');
        $live = $this->session('live', 7, 0, 200, 'Windows NT 10.0 Chrome/128.0 Safari/537.36 Edg/129.0');
        $deleted = $this->session('deleted', 8, 250, 350);
        $guest = $this->session('guest', null, 0, 400);
        $week = $this->session('week', 7, 100, 199);
        $old = $this->session('old', null, 0, 99);
        $future = $this->session('future', 7, TimeHelper::nowMs() + 60000, TimeHelper::nowMs() + 60000);
        $this->action($this->connection('first', $live), 180, 'first', null);
        $this->action($this->connection('second', $live), 190, 'second', null);
        $table = new HilosAnalyticsBrowserSessionListTable();
        self::assertSame('hilosAnalyticsBrowserSessionList', $table::TABLE);
        foreach ([[null, [$live, $deleted, $guest, $week, $old]], ['this-week', [$live, $deleted, $guest, $week]],
            ['today', [$live, $deleted, $guest]], ['this-hour', [$deleted, $guest]]] as [$period, $expected]) {
            $filter = $period === null ? self::PEOPLE_CONTEXT : ['period' => $period] + self::PEOPLE_CONTEXT;
            $window = $table->getPage(new TableQueryDTO(filter: $filter));
            self::assertSame($expected, self::rowKeys($window));
            self::assertSame(count($expected), $window->totalCount);
        }
        $query = new TableQueryDTO(filter: ['period' => 'today'] + self::PEOPLE_CONTEXT);
        $window = $table->getPage($query);
        $row = $window->rows[0];
        self::assertSame([
            'rowKey' => $live, 'lastSignedInUserId' => 7, 'lastSignedInUserName' => 'Deleted user #9',
            'browser' => 'Edge 129 · Windows', 'firstSeenTs' => 0, 'lastSeenTs' => 200, 'actionCount' => 2,
        ], $row->toArray());
        self::assertSame($row->toArray(), HilosAnalyticsBrowserSessionListTableRow::fromArray($row->toArray())->toArray());
        self::assertSame(['rowKey' => $live, 'sources' => ['browserSession' => [
            'lastSignedInUserId' => 7, 'lastSignedInUserName' => 'Deleted user #9', 'browser' => 'Edge 129 · Windows',
            'firstSeenTs' => 0, 'lastSeenTs' => 200, 'actionCount' => 2,
        ]]], $table->browserRow($row));
        self::assertSame(8, $window->rows[1]->lastSignedInUserId);
        self::assertNull($window->rows[1]->lastSignedInUserName);
        self::assertNull($window->rows[2]->lastSignedInUserId);
        self::assertNull($window->rows[2]->lastSignedInUserName);
        self::assertNull($window->rows[2]->browser);
        Hilos::$db->users[7]->actions->rename('Renamed person');
        self::assertSame('Renamed person', $table->getPage($query)->rows[0]->lastSignedInUserName);
        self::assertSame('Deleted user #9', $row->lastSignedInUserName);
        foreach ([self::PEOPLE_CONTEXT, ['period' => 'today'] + self::PEOPLE_CONTEXT] as $filter) {
            $facets = $table->facetCounts(new TableQueryDTO(filter: $filter, limit: 1), self::PEOPLE_FACETS)['period'];
            foreach (['any' => 5, 'this-hour' => 2, 'today' => 3, 'this-week' => 4] as $key => $count) {
                $actual = $key === 'any' ? $facets['any'] : $facets['options'][$key];
                self::assertSame(['count' => $count, 'exact' => true], $actual->toArray());
            }
        }
        $person = new HilosAnalyticsBrowserSessionsTable()->getPage(new TableQueryDTO(filter: ['user' => 7]));
        self::assertSame([$live, $week, $future], self::rowKeys($person));
        self::assertSame(['rowKey', 'firstSeenTs', 'lastSeenTs', 'browser', 'actionCount'], array_keys($person->rows[0]->toArray()));
    }

    public function testSessionListFacetsCountWithoutReadingActionsAndPropagateSqlFailures(): void
    {
        $this->session('counted-without-actions', null, 100, 300);
        Database::sql('SET FOREIGN_KEY_CHECKS = 0');
        Database::sql('DROP TABLE `hilos_analytics_user_action`');
        Database::sql('SET FOREIGN_KEY_CHECKS = 1');
        $table = new HilosAnalyticsBrowserSessionListTable();
        $query = new TableQueryDTO(filter: self::PEOPLE_CONTEXT);
        $facets = $table->facetCounts($query, self::PEOPLE_FACETS)['period'];
        foreach ([$facets['any'], ...array_values($facets['options'])] as $count) {
            self::assertSame(['count' => 1, 'exact' => true], $count->toArray());
        }
        Database::sql('SET FOREIGN_KEY_CHECKS = 0');
        Database::sql('DROP TABLE `hilos_analytics_browser_session`');
        Database::sql('SET FOREIGN_KEY_CHECKS = 1');
        $this->expectException(DatabaseException::class);
        $table->facetCounts($query, self::PEOPLE_FACETS);
    }

    public function testSessionListPagingUsesLastSeenAndNumericTiesInBothDirections(): void
    {
        $ids = [];
        foreach ([400, 100, 100, 300, 200, 500, 600] as $index => $moment) {
            $ids[] = $this->session('list_' . $index, $index % 2 === 0 ? 7 : null, 0, $moment);
        }
        $table = new HilosAnalyticsBrowserSessionListTable();
        self::assertSame(25, $table->windowSize());
        self::assertSame('lastSeenTs', $table->defaultSort()->last()->field);
        self::assertSame(TableConstants::ORDER_DESC, $table->defaultSort()->last()->direction);
        $ascending = [$ids[1], $ids[2], $ids[4], $ids[3], $ids[0], $ids[5], $ids[6]];
        foreach ([[$table->defaultSort(), array_reverse($ascending)],
            [TableSortOrderDTO::of(new TableSortDTO('lastSeenTs', TableConstants::ORDER_ASC)), $ascending],
            [null, $ids]] as [$sort, $expected]) {
            $this->assertWindowSequence($table, self::PEOPLE_CONTEXT, $sort, $expected);
        }
        $first = $table->getPage(new TableQueryDTO(filter: self::PEOPLE_CONTEXT, sort: $table->defaultSort(), limit: 2));
        self::assertSame(['lastSeenTs' => 600, 'rowKey' => $ids[6]], $first->firstAnchor->values);
        $composite = $table->getPage(new TableQueryDTO(filter: self::PEOPLE_CONTEXT, limit: 2,
            sort: TableSortOrderDTO::of(
                new TableSortDTO('lastSeenTs', TableConstants::ORDER_DESC),
                new TableSortDTO('browser', TableConstants::ORDER_ASC),
            )));
        self::assertSame(array_slice($ids, 0, 2), self::rowKeys($composite));
        foreach (['browser', 'firstSeenTs', 'actionCount', 'lastSignedInUserId', 'lastSignedInUserName'] as $field) {
            $unsupported = $table->getPage(new TableQueryDTO(filter: self::PEOPLE_CONTEXT, limit: 2,
                sort: TableSortOrderDTO::of(new TableSortDTO($field, TableConstants::ORDER_DESC))));
            self::assertSame(array_slice($ids, 0, 2), self::rowKeys($unsupported));
        }
    }

    public function testSessionListViewerHidesAllSixSlotFieldsAndUsesOnlyRowNumberAnchors(): void
    {
        Database::sql('INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, ?)', ['Private name']);
        $firstId = $this->session('private_first', 7, 100, 600, 'Private UA');
        $secondId = $this->session('private_second', null, 100, 300);
        $table = new HilosAnalyticsBrowserSessionListTable();
        self::assertSame([], $table->wireFields());
        $first = $table->getPage(new TableQueryDTO(
            filter: self::PEOPLE_CONTEXT, sort: $table->defaultSort(), limit: 1, shownFields: ['rowKey'],
        ));
        self::assertSame([$firstId], self::rowKeys($first));
        self::assertSame(['rowKey' => $firstId], $first->firstAnchor->values);
        $next = $table->getPage(new TableQueryDTO(
            filter: self::PEOPLE_CONTEXT, sort: $table->defaultSort(), limit: 1, shownFields: ['rowKey'],
            anchor: new TableAnchorDTO(['rowKey' => $firstId, 'lastSeenTs' => 'old private moment']),
        ));
        self::assertSame([$secondId], self::rowKeys($next));
        self::assertSame(['rowKey' => $secondId], $next->lastAnchor->values);
        self::assertSame(1, $next->rowsBefore);
        $previous = $table->getPage(new TableQueryDTO(
            filter: self::PEOPLE_CONTEXT, sort: $table->defaultSort(), limit: 1, shownFields: ['rowKey'],
            anchor: $next->firstAnchor, anchorDirection: TableAnchorDirection::Before,
        ));
        self::assertSame([$firstId], self::rowKeys($previous));
        $wireMethod = new ReflectionMethod(BrowserContext::class, 'browserRowToWire');
        $browser = new class extends BrowserContext {
        };
        foreach ([$first, $next] as $window) {
            $wire = $wireMethod->invoke($browser, $table->browserRow($window->rows[0]), $table);
            self::assertSame(['rowKey' => $window->rows[0]->rowKey, 'slots' => ['browserSession' => [
                'lastSignedInUserId' => HiddenValue::mark(), 'lastSignedInUserName' => HiddenValue::mark(),
                'browser' => HiddenValue::mark(), 'firstSeenTs' => HiddenValue::mark(),
                'lastSeenTs' => HiddenValue::mark(), 'actionCount' => HiddenValue::mark(),
            ]]], json_decode(json_encode($wire, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));
        }
        $facet = $table->facetCounts(new TableQueryDTO(filter: self::PEOPLE_CONTEXT, shownFields: ['rowKey']), self::PEOPLE_FACETS)['period'];
        foreach ([$facet['any'], ...array_values($facet['options'])] as $count) {
            self::assertSame(['count' => 2, 'exact' => true], $count->toArray());
        }
    }

    public function testColdSessionListFacetsNeedNoSql(): void
    {
        $this->dropWindowTables();
        $facets = new HilosAnalyticsBrowserSessionListTable()->facetCounts(new TableQueryDTO(), self::PEOPLE_FACETS);
        self::assertSame(['period'], array_keys($facets));
        self::assertSame(['this-hour', 'today', 'this-week'], array_keys($facets['period']['options']));
        foreach ([$facets['period']['any'], ...array_values($facets['period']['options'])] as $count) {
            self::assertSame(['count' => 0, 'exact' => true], $count->toArray());
        }
    }

    public function testSessionListContextAndUnsafeAnchorsRefuseBeforeSql(): void
    {
        $this->dropWindowTables();
        $table = new HilosAnalyticsBrowserSessionListTable();
        foreach ([['period' => 'today'], ['timeZone' => 'UTC'],
            ['period' => null] + self::PEOPLE_CONTEXT, ['period' => 'yesterday'] + self::PEOPLE_CONTEXT,
            ['hourStartTs' => '300'] + self::PEOPLE_CONTEXT,
            ['timeZone' => 'invalid'] + self::PEOPLE_CONTEXT,
            ['dayStartTs' => 301] + self::PEOPLE_CONTEXT,
            ['hourStartTs' => TimeHelper::nowMs() + 60000] + self::PEOPLE_CONTEXT] as $filter) {
            foreach (['window', 'facets'] as $operation) {
                try {
                    $query = new TableQueryDTO(filter: $filter);
                    if ($operation === 'window') {
                        $table->getPage($query);
                    } else {
                        $table->facetCounts($query, self::PEOPLE_FACETS);
                    }
                    self::fail('An invalid calendar context must refuse before SQL');
                } catch (ValidationException $exception) {
                    self::assertNotSame('', $exception->getMessage());
                }
            }
        }
        foreach ([null, $table->defaultSort()] as $sort) {
            $invalid = [[], ['rowKey' => '1'], ['rowKey' => 1.0], ['rowKey' => true],
                ['rowKey' => null], ['rowKey' => 0], ['rowKey' => -1], ['rowKey' => 9007199254740992]];
            if ($sort !== null) {
                array_push($invalid, ['rowKey' => 1], ['rowKey' => 1, 'lastSeenTs' => '300'],
                    ['rowKey' => 1, 'lastSeenTs' => -1], ['rowKey' => 1, 'lastSeenTs' => 9007199254740992]);
            }
            foreach ($invalid as $anchor) {
                try {
                    $table->getPage(new TableQueryDTO(filter: self::PEOPLE_CONTEXT, sort: $sort, anchor: new TableAnchorDTO($anchor)));
                    self::fail('An unsafe anchor must refuse before SQL');
                } catch (ValidationException $exception) {
                    self::assertStringContainsString('anchor', $exception->getMessage());
                }
            }
        }
    }

    public function testSessionListFacetsOnlyCountAllowedKeysAndValues(): void
    {
        $this->dropWindowTables();
        $table = new HilosAnalyticsBrowserSessionListTable();
        $query = new TableQueryDTO(filter: self::PEOPLE_CONTEXT);
        self::assertSame([], $table->facetCounts($query, ['arbitrary' => ['today'], 'timeZone' => ['UTC']]));
        $cold = $table->facetCounts(new TableQueryDTO(), ['period' => ['today', 'yesterday', 1, true, 'today'], 'name' => ['secret']]);
        self::assertSame(['period'], array_keys($cold));
        self::assertSame(['today'], array_keys($cold['period']['options']));
        self::assertSame(['count' => 0, 'exact' => true], $cold['period']['any']->toArray());
    }

    public function testSessionListCountCeilingFacetsAndMaximumWindow(): void
    {
        for ($userId = 1; $userId <= 500; $userId++) {
            $this->session('person_' . $userId, $userId, 100, 300);
        }
        $table = new HilosAnalyticsBrowserSessionListTable();
        $query = new TableQueryDTO(filter: self::PEOPLE_CONTEXT, limit: $table->windowSize());
        $initial = $table->getPage($query);
        self::assertCount(25, $initial->rows);
        self::assertSame(500, $initial->totalCount);
        self::assertTrue($initial->totalExact);
        foreach ([$table->facetCounts($query, self::PEOPLE_FACETS)['period']['any'],
            ...array_values($table->facetCounts($query, self::PEOPLE_FACETS)['period']['options'])] as $count) {
            self::assertSame(['count' => 500, 'exact' => true], $count->toArray());
        }
        $this->session('overflow_person', 501, 100, 300);
        foreach ([TableConstants::NO_LIMIT, 1000] as $limit) {
            $overflow = $table->getPage(new TableQueryDTO(filter: self::PEOPLE_CONTEXT, limit: $limit));
            self::assertCount(50, $overflow->rows);
            self::assertSame(50, $overflow->limit);
            self::assertSame(500, $overflow->totalCount);
            self::assertFalse($overflow->totalExact);
            self::assertNull($overflow->rowsBefore);
        }
        $facet = $table->facetCounts($query, self::PEOPLE_FACETS)['period'];
        foreach ([$facet['any'], ...array_values($facet['options'])] as $count) {
            self::assertSame(['count' => 500, 'exact' => false], $count->toArray());
        }
        $last = $table->getPage(new TableQueryDTO(filter: self::PEOPLE_CONTEXT, limit: 25, pageIndex: 20));
        self::assertSame([501], self::rowKeys($last));
        self::assertNull($last->rowsBefore);
        $past = $table->getPage(new TableQueryDTO(filter: self::PEOPLE_CONTEXT, limit: 25, pageIndex: 21));
        self::assertSame([], $past->rows);
        self::assertFalse($past->totalExact);
    }

    /**
     * @param TableDefinition $table Frozen table under test
     * @param array<string, mixed> $filter Valid subject or calendar preset
     * @param ?TableSortOrderDTO $sort Served order
     * @param list<int> $expected Row numbers in the expected order
     * @throws HilosException When a window or count query fails
     */
    private function assertWindowSequence(TableDefinition $table, array $filter, ?TableSortOrderDTO $sort, array $expected): void
    {
        $first = $table->getPage(new TableQueryDTO(filter: $filter, sort: $sort, limit: 2));
        self::assertSame(array_slice($expected, 0, 2), self::rowKeys($first));
        self::assertSame(count($expected), $first->totalCount);
        self::assertTrue($first->totalExact);
        self::assertSame(0, $first->rowsBefore);
        self::assertNull($first->frame);
        $next = $table->getPage(new TableQueryDTO(filter: $filter, sort: $sort, limit: 2, anchor: $first->lastAnchor));
        self::assertSame(array_slice($expected, 2, 2), self::rowKeys($next));
        self::assertSame(2, $next->rowsBefore);
        $previous = $table->getPage(new TableQueryDTO(
            filter: $filter, sort: $sort, limit: 2, anchor: $next->firstAnchor, anchorDirection: TableAnchorDirection::Before,
        ));
        self::assertSame(self::rowKeys($first), self::rowKeys($previous));
        self::assertSame(0, $previous->rowsBefore);
        $tail = $table->getPage(new TableQueryDTO(
            filter: $filter, sort: $sort, limit: 2, anchorDirection: TableAnchorDirection::Before,
        ));
        self::assertSame(array_slice($expected, -2), self::rowKeys($tail));
        self::assertSame(count($expected) - 2, $tail->rowsBefore);
        for ($pageIndex = 0; $pageIndex < 5; $pageIndex++) {
            $numbered = $table->getPage(new TableQueryDTO(filter: $filter, sort: $sort, limit: 2, pageIndex: $pageIndex));
            self::assertSame(array_slice($expected, $pageIndex * 2, 2), self::rowKeys($numbered));
            self::assertSame($pageIndex * 2, $numbered->rowsBefore);
        }
        $beforeStart = $table->getPage(new TableQueryDTO(
            filter: $filter, sort: $sort, limit: 2, anchor: $first->firstAnchor, anchorDirection: TableAnchorDirection::Before,
        ));
        self::assertSame([], $beforeStart->rows);
        self::assertSame(0, $beforeStart->rowsBefore);
        $afterEnd = $table->getPage(new TableQueryDTO(filter: $filter, sort: $sort, limit: 2, anchor: $tail->lastAnchor));
        self::assertSame([], $afterEnd->rows);
        self::assertSame(count($expected), $afterEnd->rowsBefore);
    }

    /**
     * @param TableDefinition $table Frozen table under test
     * @param TableQueryDTO $query Deliberately invalid request
     * @param string $reason Expected invalid input in the exception text
     * @throws HilosException When the request unexpectedly reaches SQL
     */
    private function assertInvalidQuery(TableDefinition $table, TableQueryDTO $query, string $reason): void
    {
        try {
            $table->getPage($query);
            self::fail('Malformed analytics window input must be refused');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString($reason, $exception->getMessage());
        }
    }

    /**
     * @param TableSnapshotDTO $snapshot Returned window
     * @return list<int|string|null> Row keys in window order
     */
    private static function rowKeys(TableSnapshotDTO $snapshot): array
    {
        return array_map(static fn(AbstractTableRow $row): int|string|null => $row->getRowKey(), $snapshot->rows);
    }

    /** @throws HilosException When the schema cannot be removed */
    private function dropWindowTables(): void
    {
        Database::sql('SET FOREIGN_KEY_CHECKS = 0');
        Database::sql('DROP TABLE `hilos_analytics_user_action`');
        Database::sql('DROP TABLE `hilos_analytics_browser_session`');
        Database::sql('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @param string $token Unique browser-session token in the fixture
     * @param ?int $userId Last signed-in user, or null
     * @param int $firstSeenTs First source moment
     * @param int $lastSeenTs Last source moment
     * @param ?string $userAgent Current browser description
     * @return int Inserted session number
     * @throws HilosException When a fixture insert fails
     */
    private function session(string $token, ?int $userId, int $firstSeenTs, int $lastSeenTs, ?string $userAgent = null): int
    {
        $userAgentId = null;
        if ($userAgent !== null) {
            Database::sql(
                'INSERT INTO `hilos_analytics_user_agent` (`sha1_hash`, `value`, `created_ts`)
                 VALUES (UNHEX(SHA1(?)), ?, ?)',
                [$userAgent, $userAgent, $firstSeenTs],
            );
            $userAgentId = Database::lastInsertId();
        }
        Database::sql(
            'INSERT INTO `hilos_analytics_browser_session`
                (`session_token`, `user_identity_type`, `user_identity_value`, `current_user_agent_id`,
                 `first_seen_ts`, `last_seen_ts`) VALUES (?, ?, ?, ?, ?, ?)',
            [$token, $userId === null ? null : 'user_id', $userId === null ? null : (string)$userId,
                $userAgentId, $firstSeenTs, $lastSeenTs],
        );

        return Database::lastInsertId();
    }

    /**
     * @param string $key Unique internal connection key
     * @param ?int $sessionId Attached browser session, or null when not yet attached
     * @return int Inserted connection number
     * @throws HilosException When a fixture insert fails
     */
    private function connection(string $key, ?int $sessionId): int
    {
        Database::sql(
            'INSERT INTO `hilos_analytics_ws_connection` (`browser_session_id`, `accept_key`, `opened_ts`)
             VALUES (?, ?, ?)',
            [$sessionId, $key, 100],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $connectionId Parent connection
     * @param string $pageName Recorded page name
     * @return int Inserted page-session number
     * @throws HilosException When a fixture insert fails
     */
    private function pageSession(int $connectionId, string $pageName): int
    {
        Database::sql(
            'INSERT INTO `hilos_analytics_page` (`page_name`, `created_ts`) VALUES (?, ?)',
            [$pageName, 100],
        );
        $pageId = Database::lastInsertId();
        Database::sql(
            'INSERT INTO `hilos_analytics_page_session` (`ws_connection_id`, `page_id`, `opened_ts`)
             VALUES (?, ?, ?)',
            [$connectionId, $pageId, 100],
        );

        return Database::lastInsertId();
    }

    /**
     * @param int $connectionId Parent connection
     * @param int $createdTs Source moment
     * @param string $name Recorded action name
     * @param ?int $pageSessionId Recorded page session, or null
     * @param bool $withPayload Whether to attach an old private payload
     * @return int Inserted action number
     * @throws HilosException When a fixture insert fails
     */
    private function action(int $connectionId, int $createdTs, string $name, ?int $pageSessionId, bool $withPayload = false): int
    {
        Database::sql(
            'INSERT INTO `hilos_analytics_action_name` (`name`, `created_ts`) VALUES (?, ?)',
            [$name, $createdTs],
        );
        $nameId = Database::lastInsertId();
        $payloadId = null;
        if ($withPayload) {
            $payload = '{"private":"private payload"}';
            Database::sql(
                'INSERT INTO `hilos_analytics_payload_json` (`sha1_hash`, `payload_json`, `created_ts`)
                 VALUES (UNHEX(SHA1(?)), ?, ?)',
                [$payload, $payload, $createdTs],
            );
            $payloadId = Database::lastInsertId();
        }
        Database::sql(
            'INSERT INTO `hilos_analytics_user_action`
                (`ws_connection_id`, `page_session_id`, `action_name_id`, `payload_json_id`, `created_ts`)
             VALUES (?, ?, ?, ?, ?)',
            [$connectionId, $pageSessionId, $nameId, $payloadId, $createdTs],
        );

        return Database::lastInsertId();
    }
}

/** Framework collections used by the session reader for account labels. */
final class AnalyticsTablesTestDbContext extends HilosDbContext
{
}
