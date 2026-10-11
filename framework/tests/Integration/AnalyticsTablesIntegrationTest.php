<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Analytics\AnalyticsSectionReader;
use Hilos\Core\Exception\InvalidArgumentException;
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
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionTableRow;

/** Frozen analytics tables over the shipped schema, including viewer-safe window addresses. */
final class AnalyticsTablesIntegrationTest extends AnalyticsSchemaIntegrationTestCase
{
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
        foreach ([new HilosAnalyticsActionsTable(), new HilosAnalyticsBrowserSessionsTable()] as $table) {
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
        ] as [$table, $filter]) {
            try {
                $table->getPage(new TableQueryDTO(filter: $filter));
                self::fail('A failed SQL query must refuse the window');
            } catch (DatabaseException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    /**
     * @param TableDefinition $table Frozen table under test
     * @param array<string, int> $filter Valid subject preset
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
