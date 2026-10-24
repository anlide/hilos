<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Core\Analytics\AnalyticsSectionAction;
use Hilos\Core\Analytics\AnalyticsSectionBrowserSession;
use Hilos\Core\Analytics\AnalyticsSectionReader;
use Hilos\Core\Analytics\AnalyticsStore;
use Hilos\Core\Analytics\AnalyticsWindowAnchor;
use Hilos\Core\Analytics\AnalyticsWindowOrder;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Schema\Schema;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Pages\AbstractHilosAnalyticsPage;
use Hilos\Utils\Helpers\TimeHelper;

/** Integration coverage for bounded, private section reads over the shipped analytics schema. */
final class AnalyticsSectionReaderIntegrationTest extends AnalyticsSchemaIntegrationTestCase
{
    private ?DbContext $previousDb = null;

    /** @throws HilosException When the user stub or context cannot be prepared */
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
        $db = new AnalyticsSectionReaderTestDbContext();
        $db->configure();
        Hilos::$db = $db;
    }

    /** @throws HilosException When the user or analytics stub cannot be dropped */
    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
        Database::sqlRun('DROP TABLE IF EXISTS `hilos_user`');
        Schema::reset();
        parent::tearDown();
    }

    public function testOverviewCountsClosedIntervalIntersectionsIncludingGuestsExactlyOnce(): void
    {
        $shared = $this->session('all-periods', null, 50, 450);
        $first = $this->connection('one', $shared);
        $second = $this->connection('two', $shared);
        $this->action($first, 350, 'first-action', null);
        $this->action($second, 350, 'second-action', null);
        $this->session('ends-at-week', 7, 50, 100);
        $this->session('ends-at-day', null, 150, 200);
        $this->session('ends-at-hour', 8, 250, 300);
        $this->session('starts-at-asof', null, 400, 500);
        $this->session('ends-at-asof', null, 350, 400);
        $this->session('before-week', 7, 50, 99);
        $this->session('after-asof', null, 401, 500);
        $this->session('starts-at-week', null, 100, 199);
        $this->session('starts-at-day', 8, 200, 299);
        $this->session('starts-at-hour', null, 300, 399);

        $snapshot = new AnalyticsSectionReader()->overviewSnapshot('US/Eastern', 100, 200, 300, 400);
        self::assertSame('US/Eastern', $snapshot->timeZone);
        self::assertSame(100, $snapshot->weekStartTs);
        self::assertSame(200, $snapshot->dayStartTs);
        self::assertSame(300, $snapshot->hourStartTs);
        self::assertSame(400, $snapshot->asOfTs);
        self::assertSame(9, $snapshot->visitorsThisWeek);
        self::assertSame(7, $snapshot->visitorsToday);
        self::assertSame(5, $snapshot->visitorsThisHour);
        self::assertSame([
            'timeZone' => 'US/Eastern', 'weekStartTs' => 100, 'dayStartTs' => 200, 'hourStartTs' => 300, 'asOfTs' => 400,
            'visitorsThisWeek' => 9, 'visitorsToday' => 7, 'visitorsThisHour' => 5,
        ], $snapshot->toArray());
    }

    public function testOverviewPageBuildsTheCompleteSnapshotWithTheServerUpperBound(): void
    {
        $this->session('page-session', null, 100, 300);
        $page = new class ($this->createStub(PageAgentInterface::class)) extends AbstractHilosAnalyticsPage {
            /**
             * @param PageRouteParams $params Captured reader context
             * @return PagePayload Actual production payload built against the integration database
             */
            public function payload(PageRouteParams $params): PagePayload
            {
                return parent::buildPagePayload('test', $params);
            }
        };
        $before = TimeHelper::nowMs();
        $snapshot = $page->payload(new PageRouteParams([
            'timeZone' => 'UTC', 'weekStartTs' => '100', 'dayStartTs' => '200', 'hourStartTs' => '300',
        ]))->data['analyticsOverview'];
        $after = TimeHelper::nowMs();
        self::assertGreaterThanOrEqual($before, $snapshot['asOfTs']);
        self::assertLessThanOrEqual($after, $snapshot['asOfTs']);
        self::assertSame([
            'timeZone' => 'UTC', 'weekStartTs' => 100, 'dayStartTs' => 200, 'hourStartTs' => 300,
            'asOfTs' => $snapshot['asOfTs'], 'visitorsThisWeek' => 1, 'visitorsToday' => 1, 'visitorsThisHour' => 1,
        ], $snapshot);
    }

    public function testOverviewReturnsZeroForEmptyAndIndividuallyEmptyPeriods(): void
    {
        $reader = new AnalyticsSectionReader();
        self::assertSame([
            'timeZone' => 'UTC', 'weekStartTs' => 100, 'dayStartTs' => 200, 'hourStartTs' => 300, 'asOfTs' => 400,
            'visitorsThisWeek' => 0, 'visitorsToday' => 0, 'visitorsThisHour' => 0,
        ], $reader->overviewSnapshot('UTC', 100, 200, 300, 400)->toArray());
        $this->session('week-only', null, 100, 199);
        self::assertSame(1, $reader->overviewSnapshot('UTC', 100, 200, 300, 400)->visitorsThisWeek);
        self::assertSame(0, $reader->overviewSnapshot('UTC', 100, 200, 300, 400)->visitorsToday);
        $this->session('day-only', null, 200, 299);
        $snapshot = $reader->overviewSnapshot('UTC', 100, 200, 300, 400);
        self::assertSame(2, $snapshot->visitorsThisWeek);
        self::assertSame(1, $snapshot->visitorsToday);
        self::assertSame(0, $snapshot->visitorsThisHour);
    }

    public function testOverviewSqlFailureDoesNotBecomeZeroAttendance(): void
    {
        Database::sql('SET FOREIGN_KEY_CHECKS = 0');
        Database::sql('DROP TABLE `hilos_analytics_browser_session`');
        Database::sql('SET FOREIGN_KEY_CHECKS = 1');
        $this->expectException(DatabaseException::class);
        new AnalyticsSectionReader()->overviewSnapshot('UTC', 100, 200, 300, 400);
    }

    /** @throws HilosException When a fixture or section query fails */
    public function testActionsAreBoundedOrderedAndContainOnlyPublicFields(): void
    {
        $firstSession = $this->session('first', 7, 100, 200);
        $secondSession = $this->session('second', 8, 100, 200);
        $firstConnection = $this->connection('first', $firstSession);
        $secondConnection = $this->connection('second', $secondSession);
        $unattachedConnection = $this->connection('unattached', null);
        $pageSession = $this->pageSession($firstConnection, 'settings');
        $later = $this->action($firstConnection, 210, 'later', null);
        $earlier = $this->action($firstConnection, 190, 'earlier', $pageSession, true);
        $tied = $this->action($firstConnection, 190, 'tied', null);
        $this->action($secondConnection, 180, 'other_session', null);
        $this->action($unattachedConnection, 170, 'unattached', null);

        $reader = new AnalyticsSectionReader();
        $first = $reader->actionWindow($firstSession, AnalyticsWindowOrder::TIME_ASC, null, TableAnchorDirection::After, 1);
        self::assertSame([$earlier], array_map(static fn(AnalyticsSectionAction $action): int => $action->id, $first));
        self::assertSame('settings', $first[0]->pageName);
        self::assertSame('earlier', $first[0]->actionName);
        self::assertSame(['id', 'createdTs', 'pageName', 'actionName'], array_keys(get_object_vars($first[0])));
        self::assertStringNotContainsString('private payload', json_encode($first[0], JSON_THROW_ON_ERROR));

        foreach ([
            [AnalyticsWindowOrder::TIME_ASC, [$earlier, $tied, $later]],
            [AnalyticsWindowOrder::TIME_DESC, [$later, $tied, $earlier]],
            [AnalyticsWindowOrder::NUMBER, [$later, $earlier, $tied]],
        ] as [$order, $ids]) {
            $this->assertWindows(
                fn(?AnalyticsWindowAnchor $anchor, TableAnchorDirection $direction, int $limit, int $skip): array =>
                    $reader->actionWindow($firstSession, $order, $anchor, $direction, $limit, $skip),
                fn(AnalyticsWindowAnchor $anchor, int $ceiling): int =>
                    $reader->actionCountBefore($firstSession, $order, $anchor, $ceiling),
                $order,
                $ids,
                'createdTs',
            );
        }
        self::assertSame(3, $reader->actionCount($firstSession));
        self::assertSame(2, $reader->actionCount($firstSession, 1));
        self::assertSame(1, $reader->actionCount($firstSession, 0));
        self::assertSame(0, $reader->actionCount(999999));
    }

    /** @throws HilosException When a fixture or section query fails */
    public function testSessionSummariesUseLastIdentityAndOnlyAttachedActionCounts(): void
    {
        Database::sql('INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, ?)', ['Current name']);
        $new = $this->session('new', 7, 120, 400);
        $old = $this->session('old', 7, 100, 300, 'Windows NT 10.0 Chrome/128.0 Safari/537.36 Edg/129.0');
        $tied = $this->session('tied', 7, 110, 300);
        $other = $this->session('other', 8, 130, 500);
        $guest = $this->session('guest', null, 140, 600);
        $masked = $this->session('masked', null, 150, 700);
        Database::sql(
            'UPDATE `hilos_analytics_browser_session` SET `user_identity_type` = ?, `user_identity_value` = ? WHERE `id` = ?',
            ['email', 'private@example.test', $guest],
        );
        Database::sql(
            'UPDATE `hilos_analytics_browser_session` SET `user_identity_type` = ?, `user_identity_value` = ? WHERE `id` = ?',
            ['user_id', 'hashed-identity-value', $masked],
        );
        $this->action($this->connection('old', $old), 101, 'old_action', null);
        $this->action($this->connection('old2', $old), 102, 'old_action2', null);
        $this->action($this->connection('other', $other), 103, 'other_action', null);
        $this->action($this->connection('unattached', null), 104, 'not_attached', null);

        $reader = new AnalyticsSectionReader();
        $summary = $reader->browserSession($old);
        self::assertNotNull($summary);
        self::assertSame(7, $summary->lastSignedInUserId);
        self::assertSame('Current name', $summary->lastSignedInUserLabel);
        self::assertSame('Edge 129 · Windows', $summary->browser);
        self::assertSame(2, $summary->actionCount);
        self::assertSame(['id', 'firstSeenTs', 'lastSeenTs', 'browser',
            'lastSignedInUserId', 'lastSignedInUserLabel', 'actionCount'], array_keys(get_object_vars($summary)));
        self::assertNull($reader->browserSession(999999));
        self::assertNull($reader->browserSession($guest)?->lastSignedInUserLabel);
        self::assertNull($reader->browserSession($guest)?->lastSignedInUserId);
        self::assertNull($reader->browserSession($masked)?->lastSignedInUserId);
        self::assertNull($reader->browserSession($masked)?->lastSignedInUserLabel);

        self::assertNull($reader->browserSession($tied)?->browser);
        foreach ([
            [AnalyticsWindowOrder::TIME_ASC, [$old, $tied, $new]],
            [AnalyticsWindowOrder::TIME_DESC, [$new, $tied, $old]],
            [AnalyticsWindowOrder::NUMBER, [$new, $old, $tied]],
        ] as [$order, $ids]) {
            $this->assertWindows(
                fn(?AnalyticsWindowAnchor $anchor, TableAnchorDirection $direction, int $limit, int $skip): array =>
                    $reader->browserSessionWindow(7, $order, $anchor, $direction, $limit, $skip),
                fn(AnalyticsWindowAnchor $anchor, int $ceiling): int =>
                    $reader->browserSessionCountBefore(7, $order, $anchor, $ceiling),
                $order,
                $ids,
                'lastSeenTs',
            );
        }
        $window = $reader->browserSessionWindow(7, AnalyticsWindowOrder::TIME_DESC, null, TableAnchorDirection::After, 50);
        self::assertSame([0, 0, 2], array_map(static fn($session): int => $session->actionCount, $window));
        self::assertSame(['Current name', 'Current name', 'Current name'],
            array_map(static fn($session): ?string => $session->lastSignedInUserLabel, $window));
        self::assertSame([], $reader->browserSessionWindow(999, AnalyticsWindowOrder::TIME_DESC, null, TableAnchorDirection::After, 50));
        self::assertSame(3, $reader->browserSessionCount(7));
        self::assertSame(2, $reader->browserSessionCount(7, 1));
        self::assertSame(1, $reader->browserSessionCount(7, 0));
        self::assertSame(0, $reader->browserSessionCount(999));
    }

    /** @throws HilosException When a fixture or section query fails */
    public function testRequestedWindowsCannotExceedTheServerCap(): void
    {
        $sessionId = $this->session('capped', 7, 100, 200);
        $connectionId = $this->connection('capped', $sessionId);
        for ($index = 0; $index <= AnalyticsSectionReader::MAX_PAGE_ROWS; $index++) {
            $this->action($connectionId, 100 + $index, 'action_' . $index, null);
            $this->session('session_' . $index, 8, 100, 100 + $index);
        }

        $reader = new AnalyticsSectionReader();
        $first = $reader->actionWindow($sessionId, AnalyticsWindowOrder::TIME_ASC, null, TableAnchorDirection::After, 500);
        self::assertCount(AnalyticsSectionReader::MAX_PAGE_ROWS, $first);
        self::assertCount(1, $reader->actionWindow($sessionId, AnalyticsWindowOrder::TIME_ASC,
            new AnalyticsWindowAnchor($first[49]->createdTs, $first[49]->id), TableAnchorDirection::After, 500));
        $sessions = $reader->browserSessionWindow(8, AnalyticsWindowOrder::TIME_DESC, null, TableAnchorDirection::After, 500);
        self::assertCount(AnalyticsSectionReader::MAX_PAGE_ROWS, $sessions);
        self::assertCount(1, $reader->browserSessionWindow(8, AnalyticsWindowOrder::TIME_DESC,
            new AnalyticsWindowAnchor($sessions[49]->lastSeenTs, $sessions[49]->id), TableAnchorDirection::After, 500));
    }

    /** @throws HilosException When a fixture or section query fails */
    public function testDeletedAndMergedSessionsNeverRecoverAnOldNameOrRow(): void
    {
        Database::sql('INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, ?)', ['Name before erasure']);
        $vanished = $this->session('vanished', 7, 100, 200);
        $survivor = $this->session('survivor', 7, 150, 250);
        $reader = new AnalyticsSectionReader();
        self::assertSame('Name before erasure', $reader->browserSession($survivor)?->lastSignedInUserLabel);

        Database::sql('UPDATE `hilos_user` SET `name` = ? WHERE `id` = 7', ['Renamed user']);
        self::assertSame('Renamed user', $reader->browserSession($survivor)?->lastSignedInUserLabel);

        Database::sql('DELETE FROM `hilos_user` WHERE `id` = 7');
        self::assertSame('Deleted user #7', $reader->browserSession($survivor)?->lastSignedInUserLabel);
        self::assertSame('Deleted user #7',
            $reader->browserSessionWindow(7, AnalyticsWindowOrder::TIME_DESC, null, TableAnchorDirection::After, 50)[0]->lastSignedInUserLabel);

        (new AnalyticsStore())->renameBrowserSession('vanished', 'survivor', 260);
        self::assertNull($reader->browserSession($vanished));
        self::assertSame([], $reader->actionWindow($survivor, AnalyticsWindowOrder::TIME_ASC, null, TableAnchorDirection::After, 50));
        self::assertSame(0, $reader->browserSession($survivor)?->actionCount);
    }

    /** @throws HilosException When a fixture or query fails */
    public function testSqlFailureIsNotAnEmptyWindow(): void
    {
        Database::sql('SET FOREIGN_KEY_CHECKS = 0');
        Database::sql('DROP TABLE `hilos_analytics_user_action`');
        Database::sql('SET FOREIGN_KEY_CHECKS = 1');

        $this->expectException(DatabaseException::class);
        (new AnalyticsSectionReader())->actionWindow(1, AnalyticsWindowOrder::TIME_ASC, null, TableAnchorDirection::After, 50);
    }

    /** @throws HilosException When a fixture or bounded count query fails */
    public function testCountsStopAtTheGlobalCeilingEvenWhenALargerOneIsRequested(): void
    {
        $session = $this->session('counted', 7, 100, 200);
        $connection = $this->connection('counted', $session);
        for ($index = 0; $index < TableConstants::COUNT_CEILING + 2; $index++) {
            $this->action($connection, 100, 'count_' . $index, null);
            $this->session('count_' . $index, 8, 100, 100);
        }
        $reader = new AnalyticsSectionReader();
        self::assertSame(TableConstants::COUNT_CEILING + 1, $reader->actionCount($session));
        self::assertSame(TableConstants::COUNT_CEILING + 1, $reader->actionCount($session, TableConstants::COUNT_CEILING + 100));
        self::assertSame(TableConstants::COUNT_CEILING + 1, $reader->browserSessionCount(8));
        self::assertSame(TableConstants::COUNT_CEILING + 1, $reader->browserSessionCount(8, TableConstants::COUNT_CEILING + 100));
        $pastEnd = new AnalyticsWindowAnchor(1000, PHP_INT_MAX);
        self::assertSame(TableConstants::COUNT_CEILING + 1,
            $reader->actionCountBefore($session, AnalyticsWindowOrder::TIME_ASC, $pastEnd));
        self::assertSame(TableConstants::COUNT_CEILING + 1,
            $reader->browserSessionCountBefore(8, AnalyticsWindowOrder::NUMBER, $pastEnd));
        $overview = $reader->overviewSnapshot('UTC', 0, 0, 0, 1000);
        self::assertSame(TableConstants::COUNT_CEILING + 3, $overview->visitorsThisWeek);
        self::assertSame(TableConstants::COUNT_CEILING + 3, $overview->visitorsToday);
        self::assertSame(TableConstants::COUNT_CEILING + 3, $overview->visitorsThisHour);
    }

    /** @throws HilosException When a valid empty window query fails */
    public function testInvalidAnchorsAndSkipsAreRefused(): void
    {
        $reader = new AnalyticsSectionReader();
        foreach (['actionWindow', 'browserSessionWindow'] as $method) {
            foreach (AnalyticsWindowOrder::cases() as $order) {
                foreach ([-1, 1] as $skip) {
                    try {
                        $reader->$method(1, $order, new AnalyticsWindowAnchor(100, 1), TableAnchorDirection::After, 50, $skip);
                        self::fail('An anchor cannot accompany a skip');
                    } catch (InvalidArgumentException $exception) {
                        self::assertStringContainsString('skip', $exception->getMessage());
                    }
                }
            }
            foreach ([AnalyticsWindowOrder::TIME_ASC, AnalyticsWindowOrder::TIME_DESC] as $order) {
                try {
                    $reader->$method(1, $order, new AnalyticsWindowAnchor(null, 1), TableAnchorDirection::After, 50);
                    self::fail('A time anchor requires its moment');
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString('moment', $exception->getMessage());
                }
            }
        }
        foreach (['actionCountBefore', 'browserSessionCountBefore'] as $method) {
            foreach ([AnalyticsWindowOrder::TIME_ASC, AnalyticsWindowOrder::TIME_DESC] as $order) {
                try {
                    $reader->$method(1, $order, new AnalyticsWindowAnchor(null, 1));
                    self::fail('A time count anchor requires its moment');
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString('moment', $exception->getMessage());
                }
            }
        }
    }

    /** @throws HilosException When the fixture schema cannot be removed */
    public function testZeroLimitsAndSkipsPastTheCeilingNeedNoSql(): void
    {
        Database::sql('SET FOREIGN_KEY_CHECKS = 0');
        Database::sql('DROP TABLE `hilos_analytics_user_action`');
        Database::sql('DROP TABLE `hilos_analytics_browser_session`');
        Database::sql('SET FOREIGN_KEY_CHECKS = 1');
        $reader = new AnalyticsSectionReader();
        foreach (['actionWindow', 'browserSessionWindow'] as $method) {
            self::assertSame([], $reader->$method(1, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 0));
            self::assertSame([], $reader->$method(1, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::Before, -1));
            self::assertSame([], $reader->$method(1, AnalyticsWindowOrder::NUMBER, null,
                TableAnchorDirection::After, 50, TableConstants::COUNT_CEILING + 1));
        }
    }

    /**
     * @param Closure(?AnalyticsWindowAnchor, TableAnchorDirection, int, int): array $window SQL window for a fixed subject and order
     * @param Closure(AnalyticsWindowAnchor, int): int $countBefore Bounded count before a key in that same set
     * @param AnalyticsWindowOrder $order Canonical window order
     * @param list<int> $expectedIds Fixture row numbers in the expected canonical order
     * @param string $momentField Source-moment property of the summary rows
     * @throws HilosException When a window or count query fails
     */
    private function assertWindows(
        Closure $window,
        Closure $countBefore,
        AnalyticsWindowOrder $order,
        array $expectedIds,
        string $momentField,
    ): void {
        $rows = $window(null, TableAnchorDirection::After, 50, 0);
        self::assertSame($expectedIds, $this->rowIds($rows));
        self::assertSame($expectedIds, $this->rowIds($window(null, TableAnchorDirection::Before, 50, 0)));
        self::assertSame(array_slice($expectedIds, 0, 2), $this->rowIds($window(null, TableAnchorDirection::After, 2, 0)));
        self::assertSame(array_slice($expectedIds, -2), $this->rowIds($window(null, TableAnchorDirection::Before, 2, 0)));
        self::assertSame(array_slice($expectedIds, 1, 2), $this->rowIds($window(null, TableAnchorDirection::After, 2, 1)));
        self::assertSame(array_slice($expectedIds, 0, 2), $this->rowIds($window(null, TableAnchorDirection::Before, 2, 1)));
        self::assertSame($expectedIds, $this->rowIds($window(null, TableAnchorDirection::After, 50, -1)));
        self::assertSame([], $window(null, TableAnchorDirection::After, 50, count($expectedIds)));
        self::assertSame([], $window(null, TableAnchorDirection::Before, 50, count($expectedIds)));
        foreach ($rows as $index => $row) {
            $anchor = new AnalyticsWindowAnchor($order === AnalyticsWindowOrder::NUMBER ? null : $row->$momentField, $row->id);
            self::assertSame(array_slice($expectedIds, $index + 1, 2),
                $this->rowIds($window($anchor, TableAnchorDirection::After, 2, 0)));
            self::assertSame(array_slice($expectedIds, max(0, $index - 2), min(2, $index)),
                $this->rowIds($window($anchor, TableAnchorDirection::Before, 2, 0)));
            self::assertSame($index, $countBefore($anchor, TableConstants::COUNT_CEILING));
            self::assertSame(min($index, 1), $countBefore($anchor, 0));
        }
    }

    /**
     * @param list<AnalyticsSectionAction|AnalyticsSectionBrowserSession> $rows Typed analytics summary rows
     * @return list<int> Row numbers in the order returned
     */
    private function rowIds(array $rows): array
    {
        return array_map(static fn(AnalyticsSectionAction|AnalyticsSectionBrowserSession $row): int => $row->id, $rows);
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

/** Framework collections used only for a fresh account-name lookup. */
final class AnalyticsSectionReaderTestDbContext extends HilosDbContext
{
}
