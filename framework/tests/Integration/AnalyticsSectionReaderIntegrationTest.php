<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Analytics\AnalyticsSectionAction;
use Hilos\Core\Analytics\AnalyticsSectionReader;
use Hilos\Core\Analytics\AnalyticsStore;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Schema\Schema;
use Hilos\Hilos;
use Hilos\HilosException;

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
        $first = $reader->actions($firstSession, null, null, 1);
        self::assertSame([$earlier], array_map(static fn(AnalyticsSectionAction $action): int => $action->id, $first));
        self::assertSame('settings', $first[0]->pageName);
        self::assertSame('earlier', $first[0]->actionName);
        self::assertSame(['id', 'createdTs', 'pageName', 'actionName'], array_keys(get_object_vars($first[0])));
        self::assertStringNotContainsString('private payload', json_encode($first[0], JSON_THROW_ON_ERROR));

        $next = $reader->actions($firstSession, $first[0]->createdTs, $first[0]->id, 50);
        self::assertSame([$tied, $later], array_map(static fn(AnalyticsSectionAction $action): int => $action->id, $next));
        self::assertNull($next[0]->pageName);
        self::assertSame([], $reader->actions($firstSession, $next[1]->createdTs, $next[1]->id, 50));
        self::assertCount(3, $reader->actions($firstSession, null, null, 500));
    }

    /** @throws HilosException When a fixture or section query fails */
    public function testSessionSummariesUseLastIdentityAndOnlyAttachedActionCounts(): void
    {
        Database::sql('INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, ?)', ['Current name']);
        $old = $this->session('old', 7, 100, 300, str_repeat('é', 170));
        $tied = $this->session('tied', 7, 110, 300);
        $new = $this->session('new', 7, 120, 400);
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
        self::assertSame(160, mb_strlen($summary->browserDescription));
        self::assertSame(2, $summary->actionCount);
        self::assertSame(['id', 'firstSeenTs', 'lastSeenTs', 'browserDescription',
            'lastSignedInUserId', 'lastSignedInUserLabel', 'actionCount'], array_keys(get_object_vars($summary)));
        self::assertNull($reader->browserSession(999999));
        self::assertNull($reader->browserSession($guest)?->lastSignedInUserLabel);
        self::assertNull($reader->browserSession($guest)?->lastSignedInUserId);
        self::assertNull($reader->browserSession($masked)?->lastSignedInUserId);
        self::assertNull($reader->browserSession($masked)?->lastSignedInUserLabel);

        $window = $reader->browserSessionsForUser(7, null, null, 2);
        self::assertSame([$new, $tied], array_map(static fn($session): int => $session->id, $window));
        self::assertSame([0, 0], array_map(static fn($session): int => $session->actionCount, $window));
        $tail = $reader->browserSessionsForUser(7, $window[1]->lastSeenTs, $window[1]->id, 50);
        self::assertSame([$old], array_map(static fn($session): int => $session->id, $tail));
        self::assertSame(2, $tail[0]->actionCount);
        self::assertSame([], $reader->browserSessionsForUser(999, null, null, 50));
    }

    /** @throws HilosException When a fixture or section query fails */
    public function testRequestedWindowsCannotExceedTheServerCap(): void
    {
        $sessionId = $this->session('capped', 7, 100, 200);
        $connectionId = $this->connection('capped', $sessionId);
        for ($index = 0; $index <= AnalyticsSectionReader::MAX_PAGE_ROWS; $index++) {
            $this->action($connectionId, 100 + $index, 'action_' . $index, null);
        }

        $reader = new AnalyticsSectionReader();
        $first = $reader->actions($sessionId, null, null, 500);
        self::assertCount(AnalyticsSectionReader::MAX_PAGE_ROWS, $first);
        self::assertCount(1, $reader->actions($sessionId, $first[49]->createdTs, $first[49]->id, 500));
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
        self::assertSame('Deleted user #7', $reader->browserSessionsForUser(7, null, null, 50)[0]->lastSignedInUserLabel);

        (new AnalyticsStore())->renameBrowserSession('vanished', 'survivor', 260);
        self::assertNull($reader->browserSession($vanished));
        self::assertSame([], $reader->actions($survivor, null, null, 50));
        self::assertSame(0, $reader->browserSession($survivor)?->actionCount);
    }

    /** @throws HilosException When a fixture or query fails */
    public function testSqlFailureIsNotAnEmptyWindow(): void
    {
        Database::sql('SET FOREIGN_KEY_CHECKS = 0');
        Database::sql('DROP TABLE `hilos_analytics_user_action`');
        Database::sql('SET FOREIGN_KEY_CHECKS = 1');

        $this->expectException(DatabaseException::class);
        (new AnalyticsSectionReader())->actions(1, null, null, 50);
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
