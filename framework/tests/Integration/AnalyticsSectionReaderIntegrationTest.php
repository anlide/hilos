<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Core\Analytics\AnalyticsPeriodFilter;
use Hilos\Core\Analytics\AnalyticsSectionAction;
use Hilos\Core\Analytics\AnalyticsSectionBrowserSession;
use Hilos\Core\Analytics\AnalyticsSectionPerson;
use Hilos\Core\Analytics\AnalyticsSectionReader;
use Hilos\Core\Analytics\AnalyticsStore;
use Hilos\Core\Analytics\AnalyticsWindowAnchor;
use Hilos\Core\Analytics\AnalyticsWindowOrder;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\DTO\PagePayload;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageRouteParams;
use Hilos\Core\Table\TableAnchorDirection;
use Hilos\Core\Table\TableConstants;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DatabaseRuntimeException;
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

    public function testPeopleGroupOnlySessionsOfTheClosedPeriodWithoutClampingLastSeen(): void
    {
        $this->session('before-week', 2, 50, 99);
        $this->session('ends-at-week', 2, 50, 100);
        $this->session('ends-at-day', 2, 150, 200);
        $this->session('ends-at-hour', 2, 250, 300);
        $this->session('starts-at-asof', 2, 400, 700);
        $this->session('starts-after-asof', 2, 401, 800);
        $this->session('any-only', 3, 50, 99);
        $this->session('week-only', 4, 100, 199);
        $this->session('day-only', 5, 200, 299);
        $this->session('hour-only', 6, 300, 399);
        $shared = $this->session('all-periods', 10, 50, 450);
        $this->session('hour-also', 10, 350, 380);
        $this->session('guest', null, 50, 450);
        $this->action($this->connection('shared-one', $shared), 350, 'one', null);
        $this->action($this->connection('shared-two', $shared), 350, 'two', null);

        $reader = new AnalyticsSectionReader();
        foreach ([
            [null, [[2, 5, 700], [3, 1, 99], [4, 1, 199], [5, 1, 299], [6, 1, 399], [10, 2, 450]]],
            ['this-week', [[2, 4, 700], [4, 1, 199], [5, 1, 299], [6, 1, 399], [10, 2, 450]]],
            ['today', [[2, 3, 700], [5, 1, 299], [6, 1, 399], [10, 2, 450]]],
            ['this-hour', [[2, 2, 700], [6, 1, 399], [10, 2, 450]]],
        ] as [$period, $expected]) {
            $filter = $this->personFilter($period);
            $people = $reader->personWindow($filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 50, 0, 400);
            self::assertSame($expected, array_map(
                static fn(AnalyticsSectionPerson $person): array => [$person->userId, $person->sessionCount, $person->lastSeenTs], $people,
            ));
            self::assertSame(count($expected), $reader->personCount($filter, 400));
            self::assertSame(['userId', 'sessionCount', 'lastSeenTs'], array_keys(get_object_vars($people[0])));
        }
    }

    public function testPeopleAcceptOnlySafePositiveNumericUserIdentitiesAndGroupNumerically(): void
    {
        foreach ([
            ['user_id', '2'], ['user_id', '0002'], ['user_id', '10'], ['user_id', '010'],
            ['user_id', '9007199254740991'],
            [null, null], ['email', '7'], ['user_id', null], ['user_id', ''], ['user_id', '0'], ['user_id', '000'],
            ['user_id', '-7'], ['user_id', '+7'], ['user_id', '7.0'], ['user_id', '7e0'], ['user_id', ' 7'], ['user_id', '7 '],
            ['user_id', "7\n"], ['user_id', '7x'], ['user_id', 'hashed-identity'], ['user_id', '٧'],
            ['user_id', '9007199254740992'], ['user_id', '9223372036854775807'],
            ['user_id', '18446744073709551616'], ['user_id', str_repeat('9', 255)],
        ] as $index => [$type, $value]) {
            $id = $this->session('identity-' . $index, null, 100, 350);
            Database::sql(
                'UPDATE `hilos_analytics_browser_session` SET `user_identity_type` = ?, `user_identity_value` = ? WHERE `id` = ?',
                [$type, $value, $id],
            );
        }
        $reader = new AnalyticsSectionReader();
        $filter = $this->personFilter('today');
        $people = $reader->personWindow($filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 50, 0, 400);
        self::assertSame([2, 10, 9007199254740991], array_column($people, 'userId'));
        self::assertSame([2, 2, 1], array_column($people, 'sessionCount'));
        self::assertSame(3, $reader->personCount($filter, 400));
        self::assertSame(2, $reader->personCountBefore($filter, AnalyticsWindowOrder::NUMBER,
            new AnalyticsWindowAnchor(null, 9007199254740991), 400));
    }

    public function testPeopleReadTheCurrentLastIdentityAndSourceMomentWithoutHistoricalEvents(): void
    {
        $session = $this->session('reassigned', 7, 100, 300);
        Database::sql(
            'INSERT INTO `hilos_analytics_person_event` (`user_id`, `browser_session_id`, `event_kind`, `created_ts`)
             VALUES (7, ?, ?, 200), (99, NULL, ?, 300)',
            [$session, 'sign_in', 'sign_in'],
        );
        $reader = new AnalyticsSectionReader();
        $filter = $this->personFilter('today');
        $first = $reader->personWindow($filter, AnalyticsWindowOrder::TIME_DESC, null, TableAnchorDirection::After, 50, 0, 400);
        self::assertSame([7], array_column($first, 'userId'));
        self::assertSame([300], array_column($first, 'lastSeenTs'));
        Database::sql('UPDATE `hilos_analytics_browser_session` SET `user_identity_value` = ?, `last_seen_ts` = 500 WHERE `id` = ?',
            ['10', $session]);
        $next = $reader->personWindow($filter, AnalyticsWindowOrder::TIME_DESC, null, TableAnchorDirection::After, 50, 0, 400);
        self::assertSame([10], array_column($next, 'userId'));
        self::assertSame([500], array_column($next, 'lastSeenTs'));
        self::assertSame(1, $reader->personCount($filter, 400));
        Database::sql('UPDATE `hilos_analytics_browser_session` SET `user_identity_type` = NULL WHERE `id` = ?', [$session]);
        self::assertSame(0, $reader->personCount($filter, 400));
    }

    public function testPeopleAnchorsAndSkipsApplyAfterGroupingInEveryOrderAndDirection(): void
    {
        $this->session('two-early', 2, 100, 200);
        $this->session('two-late', 2, 100, 360);
        $this->session('ten-early', 10, 100, 100);
        $this->session('ten-late', 10, 100, 360);
        $this->session('hundred', 100, 100, 350);
        $reader = new AnalyticsSectionReader();
        $filter = $this->personFilter();
        foreach ([
            [AnalyticsWindowOrder::TIME_ASC, [100, 2, 10]],
            [AnalyticsWindowOrder::TIME_DESC, [10, 2, 100]],
            [AnalyticsWindowOrder::NUMBER, [2, 10, 100]],
        ] as [$order, $expectedIds]) {
            $all = $reader->personWindow($filter, $order, null, TableAnchorDirection::After, 50, 0, 400);
            self::assertSame($expectedIds, array_column($all, 'userId'));
            self::assertSame($order === AnalyticsWindowOrder::TIME_ASC ? [1, 2, 2] : [2, 2, 1], array_column($all, 'sessionCount'));
            $facts = array_map(get_object_vars(...), $all);
            foreach ([0, 1, 2, 3, -1] as $skip) {
                foreach (TableAnchorDirection::cases() as $direction) {
                    $offset = $direction === TableAnchorDirection::After ? max(0, $skip) : max(0, count($all) - max(0, $skip) - 2);
                    $take = $direction === TableAnchorDirection::After ? 2 : min(2, count($all) - max(0, $skip));
                    self::assertSame(array_slice($facts, $offset, $take), array_map(get_object_vars(...),
                        $reader->personWindow($filter, $order, null, $direction, 2, $skip, 400)));
                }
            }
            foreach ($all as $index => $person) {
                $anchor = new AnalyticsWindowAnchor($order === AnalyticsWindowOrder::NUMBER ? null : $person->lastSeenTs, $person->userId);
                self::assertSame(array_slice($facts, $index + 1, 2), array_map(get_object_vars(...),
                    $reader->personWindow($filter, $order, $anchor, TableAnchorDirection::After, 2, 0, 400)));
                self::assertSame(array_slice($facts, max(0, $index - 2), min(2, $index)), array_map(get_object_vars(...),
                    $reader->personWindow($filter, $order, $anchor, TableAnchorDirection::Before, 2, 0, 400)));
                self::assertSame($index, $reader->personCountBefore($filter, $order, $anchor, 400));
                self::assertSame(min($index, 1), $reader->personCountBefore($filter, $order, $anchor, 400, 0));
            }
        }
    }

    public function testPersonCountsProbeGroupsWhileSessionCountsRemainExactAboveTheCeiling(): void
    {
        $reader = new AnalyticsSectionReader();
        $filter = $this->personFilter('today');
        self::assertSame(0, $reader->personCount($filter, 400));
        self::assertSame([], $reader->personWindow($filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 50, 0, 400));
        for ($userId = 1; $userId <= TableConstants::COUNT_CEILING; $userId++) {
            $this->session('person-' . $userId, $userId, 100, 350);
        }
        for ($index = 0; $index <= TableConstants::COUNT_CEILING; $index++) {
            $this->session('extra-' . $index, 1, 100, 600);
        }
        self::assertSame(500, $reader->personCount($filter, 400));
        self::assertSame(500, $reader->personCount($filter, 400, 1000));
        self::assertSame(500, $reader->personCountBefore($filter, AnalyticsWindowOrder::NUMBER, new AnalyticsWindowAnchor(null, 501), 400));
        $people = $reader->personWindow($filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 1000, 0, 400);
        self::assertCount(50, $people);
        self::assertSame(502, $people[0]->sessionCount);
        self::assertSame(600, $people[0]->lastSeenTs);
        self::assertSame([500], array_column(
            $reader->personWindow($filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 50, 499, 400), 'userId',
        ));
        $this->session('probe-person', 501, 100, 350);
        self::assertSame(501, $reader->personCount($filter, 400));
        $this->session('past-probe-person', 502, 100, 350);
        self::assertSame(501, $reader->personCount($filter, 400, 1000));
        self::assertSame(1, $reader->personCount($filter, 400, 0));
        self::assertSame(2, $reader->personCount($filter, 400, 1));
        self::assertSame(1, $reader->personCount($filter, 400, -1));
        self::assertSame([501, 502], array_column(
            $reader->personWindow($filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 50, 500, 400), 'userId',
        ));
        self::assertSame([1, 2], array_column(
            $reader->personWindow($filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::Before, 50, 500, 400), 'userId',
        ));
        foreach ([
            [AnalyticsWindowOrder::NUMBER, new AnalyticsWindowAnchor(null, 1000)],
            [AnalyticsWindowOrder::TIME_ASC, new AnalyticsWindowAnchor(700, 1000)],
            [AnalyticsWindowOrder::TIME_DESC, new AnalyticsWindowAnchor(0, 1)],
        ] as [$order, $anchor]) {
            self::assertSame(501, $reader->personCountBefore($filter, $order, $anchor, 400));
            self::assertSame(501, $reader->personCountBefore($filter, $order, $anchor, 400, 1000));
        }
    }

    public function testColdPersonContextAndOutOfBoundsWindowsDoNotReadSql(): void
    {
        Database::sql('SET FOREIGN_KEY_CHECKS = 0');
        Database::sql('DROP TABLE `hilos_analytics_browser_session`');
        Database::sql('SET FOREIGN_KEY_CHECKS = 1');
        $reader = new AnalyticsSectionReader();
        $cold = AnalyticsPeriodFilter::fromArray([], 400);
        self::assertSame(0, $reader->personCount($cold, 400));
        self::assertSame(0, $reader->personCountBefore($cold, AnalyticsWindowOrder::NUMBER, new AnalyticsWindowAnchor(null, 1), 400));
        self::assertSame([], $reader->personWindow($cold, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 50, 0, 400));
        $filter = $this->personFilter();
        foreach ([
            [0, 0, TableAnchorDirection::After], [-1, 0, TableAnchorDirection::Before],
            [50, 501, TableAnchorDirection::After], [50, 501, TableAnchorDirection::Before],
        ] as [$limit, $skip, $direction]) {
            self::assertSame([], $reader->personWindow($filter, AnalyticsWindowOrder::NUMBER, null, $direction, $limit, $skip, 400));
        }
    }

    public function testPersonSqlFailuresAreNotEmptyWindowsOrCounts(): void
    {
        Database::sql('SET FOREIGN_KEY_CHECKS = 0');
        Database::sql('DROP TABLE `hilos_analytics_browser_session`');
        Database::sql('SET FOREIGN_KEY_CHECKS = 1');
        $reader = new AnalyticsSectionReader();
        $filter = $this->personFilter();
        $failures = [];
        foreach (['window', 'count', 'countBefore'] as $read) {
            try {
                match ($read) {
                    'window' => $reader->personWindow($filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 50, 0, 400),
                    'count' => $reader->personCount($filter, 400),
                    'countBefore' => $reader->personCountBefore($filter, AnalyticsWindowOrder::NUMBER, new AnalyticsWindowAnchor(null, 1), 400),
                };
                self::fail('A failed person read must throw');
            } catch (DatabaseException) {
                $failures[] = $read;
            }
        }
        self::assertSame(['window', 'count', 'countBefore'], $failures);
    }

    public function testInvalidPersonAnchorsSkipsAndChangedServerBoundsAreRefusedBeforeSql(): void
    {
        Database::sql('SET FOREIGN_KEY_CHECKS = 0');
        Database::sql('DROP TABLE `hilos_analytics_browser_session`');
        Database::sql('SET FOREIGN_KEY_CHECKS = 1');
        $reader = new AnalyticsSectionReader();
        $filter = $this->personFilter();
        foreach (AnalyticsWindowOrder::cases() as $order) {
            foreach ([-1, 1] as $skip) {
                try {
                    $reader->personWindow($filter, $order, new AnalyticsWindowAnchor(350, 2), TableAnchorDirection::After, 50, $skip, 400);
                    self::fail('An anchor cannot accompany a skip');
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString('skip', $exception->getMessage());
                }
            }
        }
        foreach ([AnalyticsWindowOrder::TIME_ASC, AnalyticsWindowOrder::TIME_DESC] as $order) {
            foreach (['window', 'countBefore'] as $read) {
                try {
                    match ($read) {
                        'window' => $reader->personWindow(
                            $filter, $order, new AnalyticsWindowAnchor(null, 2), TableAnchorDirection::After, 50, 0, 400,
                        ),
                        'countBefore' => $reader->personCountBefore($filter, $order, new AnalyticsWindowAnchor(null, 2), 400),
                    };
                    self::fail('A time anchor requires its moment');
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString('moment', $exception->getMessage());
                }
            }
        }
        foreach (['window', 'count', 'countBefore'] as $read) {
            try {
                match ($read) {
                    'window' => $reader->personWindow($filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 50, 0, 299),
                    'count' => $reader->personCount($filter, 299),
                    'countBefore' => $reader->personCountBefore($filter, AnalyticsWindowOrder::NUMBER, new AnalyticsWindowAnchor(null, 2), 299),
                };
                self::fail('Every read must recheck the server upper bound');
            } catch (ValidationException $exception) {
                self::assertStringContainsString('server time', $exception->getMessage());
            }
        }
    }

    public function testSessionPeriodSetIncludesGuestsAndBothClosedBoundariesWithoutMultiplyingActions(): void
    {
        $spanning = $this->session('spanning', 7, 50, 450, 'Windows NT 10.0 Chrome/128.0 Safari/537.36 Edg/129.0');
        $this->action($this->connection('one', $spanning), 350, 'one', null);
        $this->action($this->connection('two', $spanning), 350, 'two', null);
        $week = $this->session('week-touch', null, 50, 100);
        $day = $this->session('day-touch', 8, 150, 200);
        $hour = $this->session('hour-touch', null, 250, 300);
        $upper = $this->session('upper-touch', null, 400, 500);
        $old = $this->session('before-week', 7, 50, 99);
        $this->session('future', 7, 401, 500);
        $reader = new AnalyticsSectionReader();
        foreach ([[null, [$spanning, $week, $day, $hour, $upper, $old]], ['this-week', [$spanning, $week, $day, $hour, $upper]],
            ['today', [$spanning, $day, $hour, $upper]], ['this-hour', [$spanning, $hour, $upper]]] as [$period, $ids]) {
            $filter = $this->personFilter($period);
            $rows = $reader->browserSessionPeriodWindow($filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 50, 0, 400);
            self::assertSame($ids, $this->rowIds($rows));
            self::assertSame(count($ids), $reader->browserSessionPeriodCount($filter, 400));
            self::assertSame(2, $rows[0]->actionCount);
            self::assertSame('Edge 129 · Windows', $rows[0]->browser);
            self::assertSame(array_fill(0, count($ids) - 1, 0), array_column(array_slice($rows, 1), 'actionCount'));
        }
    }

    public function testSessionPeriodReadsSafeLastIdentityAndMountedRawNames(): void
    {
        Database::sql('INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, ?), (8, ?)', ['Current name', 'Deleted private name']);
        $live = $this->session('live', 7, 100, 350);
        $deleted = $this->session('deleted', 8, 100, 350);
        $guest = $this->session('guest', null, 100, 350);
        Database::sql('DELETE FROM `hilos_user` WHERE `id` = 8');
        $invalidIds = [];
        foreach ([['email', '7'], ['user_id', '0'], ['user_id', '-1'], ['user_id', '1.0'], ['user_id', ' 7'],
            ['user_id', '9007199254740992'], ['user_id', '18446744073709551616'], ['user_id', 'hash'], ['user_id', '']]
            as $index => [$type, $value]) {
            $id = $this->session('identity-' . $index, null, 100, 350);
            Database::sql('UPDATE `hilos_analytics_browser_session` SET `user_identity_type` = ?, `user_identity_value` = ? WHERE `id` = ?',
                [$type, $value, $id]);
            $invalidIds[] = $id;
        }
        $safe = $this->session('safe', 9007199254740991, 100, 350);
        $padded = $this->session('padded', null, 100, 350);
        Database::sql('UPDATE `hilos_analytics_browser_session` SET `user_identity_type` = ?, `user_identity_value` = ? WHERE `id` = ?',
            ['user_id', '0007', $padded]);
        $reader = new AnalyticsSectionReader();
        $filter = $this->personFilter('today');
        $rows = $reader->browserSessionPeriodWindow($filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 50, 0, 400);
        self::assertSame([$live, $deleted, $guest, ...$invalidIds, $safe, $padded], $this->rowIds($rows));
        self::assertSame([7, 8, null, ...array_fill(0, count($invalidIds), null), 9007199254740991, 7], array_column($rows, 'lastSignedInUserId'));
        self::assertSame(['Current name', 'Deleted user #8', null, ...array_fill(0, count($invalidIds), null),
            'Deleted user #9007199254740991', 'Current name'], array_column($rows, 'lastSignedInUserLabel'));
        Hilos::$db->users[7]->actions->rename('Renamed account');
        self::assertSame('Renamed account', $reader->browserSessionPeriodWindow(
            $filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 1, 0, 400,
        )[0]->lastSignedInUserLabel);
        Database::sql('UPDATE `hilos_analytics_browser_session` SET `user_identity_value` = ? WHERE `id` = ?', ['8', $live]);
        self::assertSame(8, $reader->browserSessionPeriodWindow(
            $filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 1, 0, 400,
        )[0]->lastSignedInUserId);
        self::assertSame('Current name', $rows[0]->lastSignedInUserLabel);
    }

    public function testSessionPeriodAnchorsSkipsAndReverseOrdersStayInsideTheSelectedSet(): void
    {
        $later = $this->session('later', null, 100, 350);
        $firstTie = $this->session('first-tie', 7, 100, 300);
        $lastTie = $this->session('last-tie', 8, 300, 300);
        $this->session('outside', null, 100, 199);
        $this->session('future', null, 401, 500);
        $reader = new AnalyticsSectionReader();
        $filter = $this->personFilter('today');
        foreach ([[AnalyticsWindowOrder::TIME_ASC, [$firstTie, $lastTie, $later]],
            [AnalyticsWindowOrder::TIME_DESC, [$later, $lastTie, $firstTie]],
            [AnalyticsWindowOrder::NUMBER, [$later, $firstTie, $lastTie]]] as [$order, $ids]) {
            $this->assertWindows(
                fn(?AnalyticsWindowAnchor $anchor, TableAnchorDirection $direction, int $limit, int $skip): array =>
                    $reader->browserSessionPeriodWindow($filter, $order, $anchor, $direction, $limit, $skip, 400),
                fn(AnalyticsWindowAnchor $anchor, int $ceiling): int =>
                    $reader->browserSessionPeriodCountBefore($filter, $order, $anchor, 400, $ceiling),
                $order, $ids, 'lastSeenTs',
            );
        }
    }

    public function testSessionPeriodRefusesAStoredSessionNumberOutsideTheWireRange(): void
    {
        Database::sql(
            'INSERT INTO `hilos_analytics_browser_session` (`id`, `session_token`, `first_seen_ts`, `last_seen_ts`)
             VALUES (9007199254740992, ?, 100, 300)',
            ['unsafe-session-number'],
        );
        $this->expectException(DatabaseRuntimeException::class);
        new AnalyticsSectionReader()->browserSessionPeriodWindow(
            $this->personFilter(), AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 1, 0, 400,
        );
    }

    public function testSessionPeriodCountsProbeOneExtraRowAndWindowsRemainBounded(): void
    {
        $reader = new AnalyticsSectionReader();
        $filter = $this->personFilter('this-hour');
        self::assertSame(0, $reader->browserSessionPeriodCount($filter, 400));
        $ids = [];
        for ($index = 0; $index < 500; $index++) {
            $ids[] = $this->session('count-' . $index, null, 100, 300);
        }
        self::assertSame(500, $reader->browserSessionPeriodCount($filter, 400));
        self::assertSame(500, $reader->browserSessionPeriodCount($filter, 400, 1000));
        self::assertCount(50, $reader->browserSessionPeriodWindow(
            $filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 1000, 0, 400,
        ));
        $ids[] = $this->session('probe', null, 100, 300);
        $ids[] = $this->session('past-probe', null, 100, 300);
        self::assertSame(501, $reader->browserSessionPeriodCount($filter, 400));
        foreach ([0, 1, -1, 1000] as $ceiling) {
            self::assertSame(max(0, min($ceiling, 500)) + 1, $reader->browserSessionPeriodCount($filter, 400, $ceiling));
        }
        self::assertSame(array_slice($ids, 500), $this->rowIds($reader->browserSessionPeriodWindow(
            $filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 50, 500, 400,
        )));
        foreach ([[AnalyticsWindowOrder::NUMBER, new AnalyticsWindowAnchor(null, end($ids) + 1)],
            [AnalyticsWindowOrder::TIME_ASC, new AnalyticsWindowAnchor(301, 1)],
            [AnalyticsWindowOrder::TIME_DESC, new AnalyticsWindowAnchor(299, 1)]] as [$order, $anchor]) {
            self::assertSame(501, $reader->browserSessionPeriodCountBefore($filter, $order, $anchor, 400));
        }
    }

    public function testColdSessionPeriodContextAndOutOfBoundsWindowsDoNotReadSql(): void
    {
        Database::sql('SET FOREIGN_KEY_CHECKS = 0');
        Database::sql('DROP TABLE `hilos_analytics_browser_session`');
        Database::sql('SET FOREIGN_KEY_CHECKS = 1');
        $reader = new AnalyticsSectionReader();
        $cold = AnalyticsPeriodFilter::fromArray([], 400);
        self::assertSame(0, $reader->browserSessionPeriodCount($cold, 400));
        self::assertSame(0, $reader->browserSessionPeriodCountBefore($cold, AnalyticsWindowOrder::NUMBER, new AnalyticsWindowAnchor(null, 1), 400));
        self::assertSame([], $reader->browserSessionPeriodWindow($cold, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 50, 0, 400));
        $filter = $this->personFilter();
        foreach ([
            [0, 0, TableAnchorDirection::After], [-1, 0, TableAnchorDirection::Before],
            [50, 501, TableAnchorDirection::After], [50, 501, TableAnchorDirection::Before],
        ] as [$limit, $skip, $direction]) {
            self::assertSame([], $reader->browserSessionPeriodWindow($filter, AnalyticsWindowOrder::NUMBER, null, $direction, $limit, $skip, 400));
        }
    }

    public function testSessionPeriodSqlFailuresAreNotEmptyWindowsOrCounts(): void
    {
        Database::sql('SET FOREIGN_KEY_CHECKS = 0');
        Database::sql('DROP TABLE `hilos_analytics_browser_session`');
        Database::sql('SET FOREIGN_KEY_CHECKS = 1');
        $reader = new AnalyticsSectionReader();
        $filter = $this->personFilter();
        $failures = [];
        foreach (['window', 'count', 'countBefore'] as $read) {
            try {
                match ($read) {
                    'window' => $reader->browserSessionPeriodWindow(
                        $filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 50, 0, 400,
                    ),
                    'count' => $reader->browserSessionPeriodCount($filter, 400),
                    'countBefore' => $reader->browserSessionPeriodCountBefore(
                        $filter, AnalyticsWindowOrder::NUMBER, new AnalyticsWindowAnchor(null, 1), 400,
                    ),
                };
                self::fail('A failed session period read must throw');
            } catch (DatabaseException) {
                $failures[] = $read;
            }
        }
        self::assertSame(['window', 'count', 'countBefore'], $failures);
    }

    public function testInvalidSessionPeriodAnchorsSkipsAndChangedServerBoundsAreRefusedBeforeSql(): void
    {
        Database::sql('SET FOREIGN_KEY_CHECKS = 0');
        Database::sql('DROP TABLE `hilos_analytics_browser_session`');
        Database::sql('SET FOREIGN_KEY_CHECKS = 1');
        $reader = new AnalyticsSectionReader();
        $filter = $this->personFilter();
        foreach (AnalyticsWindowOrder::cases() as $order) {
            foreach ([-1, 1] as $skip) {
                try {
                    $reader->browserSessionPeriodWindow(
                        $filter, $order, new AnalyticsWindowAnchor(350, 2), TableAnchorDirection::After, 50, $skip, 400,
                    );
                    self::fail('An anchor cannot accompany a skip');
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString('skip', $exception->getMessage());
                }
            }
        }
        foreach ([AnalyticsWindowOrder::TIME_ASC, AnalyticsWindowOrder::TIME_DESC] as $order) {
            foreach (['window', 'countBefore'] as $read) {
                try {
                    match ($read) {
                        'window' => $reader->browserSessionPeriodWindow(
                            $filter, $order, new AnalyticsWindowAnchor(null, 2), TableAnchorDirection::After, 50, 0, 400,
                        ),
                        'countBefore' => $reader->browserSessionPeriodCountBefore($filter, $order, new AnalyticsWindowAnchor(null, 2), 400),
                    };
                    self::fail('A time anchor requires its moment');
                } catch (InvalidArgumentException $exception) {
                    self::assertStringContainsString('moment', $exception->getMessage());
                }
            }
        }
        foreach (['window', 'count', 'countBefore'] as $read) {
            try {
                match ($read) {
                    'window' => $reader->browserSessionPeriodWindow(
                        $filter, AnalyticsWindowOrder::NUMBER, null, TableAnchorDirection::After, 50, 0, 299,
                    ),
                    'count' => $reader->browserSessionPeriodCount($filter, 299),
                    'countBefore' => $reader->browserSessionPeriodCountBefore(
                        $filter, AnalyticsWindowOrder::NUMBER, new AnalyticsWindowAnchor(null, 2), 299,
                    ),
                };
                self::fail('Every read must recheck the server upper bound');
            } catch (ValidationException $exception) {
                self::assertStringContainsString('server time', $exception->getMessage());
            }
        }
    }

    /**
     * @param ?string $period Selected period, or any time
     * @return AnalyticsPeriodFilter Captured calendar starts shared by the person fixtures
     */
    private function personFilter(?string $period = null): AnalyticsPeriodFilter
    {
        $filter = ['timeZone' => 'US/Eastern', 'weekStartTs' => 100, 'dayStartTs' => 200, 'hourStartTs' => 300];
        if ($period !== null) {
            $filter['period'] = $period;
        }
        return AnalyticsPeriodFilter::fromArray($filter, 400);
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
