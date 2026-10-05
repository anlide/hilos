<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Analytics\AnalyticsJournalLoader;
use Hilos\Core\Analytics\AnalyticsPersonEvent;
use Hilos\Core\Analytics\AnalyticsStore;
use Hilos\Database\Database;
use Hilos\Database\Exception\DatabaseException;
use Hilos\HilosException;

/**
 * Integration coverage for the browser session surviving a token rotation (HIL-582).
 *
 * Analytics names a browser session by the session token, so the login rotation that
 * renames the application session's secret would otherwise leave the whole visit before
 * the login - its page views, WebSocket connections and user-agent history - hanging off
 * a token nobody presents again, while the identify that follows opened a second session
 * for the same person. Joining a visitor to the account they just created is the one thing
 * this table exists for, so the rename is played here end to end against the real schema:
 * the master opens the connection, the worker's records go through the journal (HIL-1154).
 */
final class AnalyticsBrowserSessionRotationIntegrationTest extends AnalyticsSchemaIntegrationTestCase
{
    private const string OLD_TOKEN = '0123456789abcdef0123456789abcdef';

    private const string NEW_TOKEN = 'fedcba9876543210fedcba9876543210';

    private const string EXIT_TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string BOB_TOKEN = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const string ACCEPT_KEY = 'hil-582-accept-key';

    private const int USER_ID = 7;

    private const int BOB_ID = 8;

    /**
     * @throws HilosException When a journal file cannot be loaded
     */
    public function testTwoPeopleKeepSeparateEventsInsideOneBrowserSession(): void
    {
        $collector = new AnalyticsCollector();
        $collector->attachWsConnectionToBrowserSession(self::ACCEPT_KEY, self::OLD_TOKEN, null, null);
        $this->loadJournal($collector);

        $collector->renameBrowserSession(self::OLD_TOKEN, self::NEW_TOKEN);
        $collector->identifyBrowserSessionUser(self::NEW_TOKEN, self::USER_ID);
        $collector->logPersonEvent(new AnalyticsPersonEvent(
            self::NEW_TOKEN, self::USER_ID, null, 11, AnalyticsPersonEvent::ACTION, 'alice_action',
            null, null, '127.0.0.1', 10,
        ));
        $this->loadJournal($collector);

        $collector->logPersonEvent(new AnalyticsPersonEvent(
            self::NEW_TOKEN, self::USER_ID, null, 11, AnalyticsPersonEvent::SIGN_OUT,
            null, null, null, '127.0.0.1', 20,
        ));
        $collector->renameBrowserSession(self::NEW_TOKEN, self::EXIT_TOKEN);
        $collector->renameBrowserSession(self::EXIT_TOKEN, self::BOB_TOKEN);
        $collector->identifyBrowserSessionUser(self::BOB_TOKEN, self::BOB_ID);
        $collector->logPersonEvent(new AnalyticsPersonEvent(
            self::BOB_TOKEN, self::BOB_ID, null, 11, AnalyticsPersonEvent::ACTION, 'bob_action',
            null, null, '127.0.0.2', 30,
        ));
        $this->loadJournal($collector);

        $browserSessionIds = $this->browserSessionIds();
        $this->assertCount(1, $browserSessionIds);
        $this->assertSame((string)self::BOB_ID, $this->identityValueOf($browserSessionIds[0]));
        Database::sql('SELECT `user_id`, `event_kind`, `browser_session_id`, INET_NTOA(`ipv4`)
            FROM `hilos_analytics_person_event` ORDER BY `created_ts`');
        $this->assertSame([
            [(string)self::USER_ID, 'action', (string)$browserSessionIds[0], '127.0.0.1'],
            [(string)self::USER_ID, 'sign_out', (string)$browserSessionIds[0], '127.0.0.1'],
            [(string)self::BOB_ID, 'action', (string)$browserSessionIds[0], '127.0.0.2'],
        ], array_map('array_values', Database::rows()));
    }

    /**
     * @throws HilosException When the journal cannot be loaded
     */
    public function testTheVisitBeforeTheLoginFollowsTheSessionOntoItsNewToken(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWsConnection(self::ACCEPT_KEY, null);
        $collector->attachWsConnectionToBrowserSession(self::ACCEPT_KEY, self::OLD_TOKEN, 'UA/1.0', 'en');
        $this->loadJournal($collector);
        $opened = $this->browserSessionIds();
        $this->assertCount(1, $opened);

        $collector->renameBrowserSession(self::OLD_TOKEN, self::NEW_TOKEN);
        $collector->identifyBrowserSessionUser(self::NEW_TOKEN, self::USER_ID);
        $this->loadJournal($collector);

        // One row, still the one the visit accumulated on, now named by the new token and
        // carrying the user it turned out to belong to.
        $this->assertSame($opened, $this->browserSessionIds());
        $this->assertSame(self::NEW_TOKEN, $this->tokenOf($opened[0]));
        $this->assertSame((string)self::USER_ID, $this->identityValueOf($opened[0]));
    }

    /**
     * Two files, two writers: the second knows nothing in memory, so the rename has only the
     * table to go on - as a writer that moved, or the login served long after the handshake.
     *
     * @throws HilosException When the journal cannot be loaded
     */
    public function testTheRenameFindsTheSessionAgainAfterTheCacheIsGone(): void
    {
        $collector = new AnalyticsCollector();
        $collector->openWsConnection(self::ACCEPT_KEY, null);
        $collector->attachWsConnectionToBrowserSession(self::ACCEPT_KEY, self::OLD_TOKEN, null, null);
        $this->loadJournal($collector);
        $opened = $this->browserSessionIds();
        $this->assertCount(1, $opened);

        $collector->renameBrowserSession(self::OLD_TOKEN, self::NEW_TOKEN);
        $this->loadJournal($collector);

        $this->assertSame(self::NEW_TOKEN, $this->tokenOf($opened[0]));
    }

    /**
     * @throws HilosException When the journal cannot be loaded
     */
    public function testRenamingATokenThatCollectedNothingOpensNoSession(): void
    {
        $collector = new AnalyticsCollector();
        $collector->renameBrowserSession(self::OLD_TOKEN, self::NEW_TOKEN);
        $this->loadJournal($collector);

        $this->assertSame([], $this->browserSessionIds());
    }

    /**
     * @throws HilosException When a journal file cannot be loaded
     */
    public function testAnOldTokenEventLoadedAfterTheRenameJoinsTheNewSession(): void
    {
        $collector = new AnalyticsCollector();
        $loader = new AnalyticsJournalLoader(new AnalyticsStore());
        $collector->attachWsConnectionToBrowserSession(self::ACCEPT_KEY, self::OLD_TOKEN, 'old UA', null);
        $this->loadJournal($collector, $loader);
        $collector->renameBrowserSession(self::OLD_TOKEN, self::NEW_TOKEN);
        $this->loadJournal($collector, $loader);
        $collector->attachWsConnectionToBrowserSession('late-connection', self::OLD_TOKEN, 'late UA', null);
        $this->loadJournal($collector, $loader);
        $collector->attachWsConnectionToBrowserSession('new-connection', self::NEW_TOKEN, 'new UA', null);
        $this->loadJournal($collector, $loader);

        $this->assertCount(1, $this->browserSessionIds());
        $this->assertSame(self::NEW_TOKEN, $this->tokenOf($this->browserSessionIds()[0]));
        Database::sql('SELECT u.`value` FROM `hilos_analytics_browser_session_user_agent_change` c
            JOIN `hilos_analytics_user_agent` u ON u.`id` = c.`old_user_agent_id`
            ORDER BY c.`id` DESC LIMIT 1');
        $this->assertSame('late UA', Database::field('value'));
    }

    /**
     * @throws HilosException When a journal file cannot be loaded
     */
    public function testAVisitUnderBothTokensMergesWhenTheRenameArrivesLast(): void
    {
        $collector = new AnalyticsCollector();
        $loader = new AnalyticsJournalLoader(new AnalyticsStore());
        $collector->attachWsConnectionToBrowserSession(self::ACCEPT_KEY, self::OLD_TOKEN, 'old UA', null);
        $collector->logPersonEvent(new AnalyticsPersonEvent(
            self::OLD_TOKEN, self::USER_ID, null, 11, AnalyticsPersonEvent::ACTION, 'old_action',
            null, null, null, 10,
        ));
        $request = $collector->startApiRequest(self::OLD_TOKEN, 'GET', '/before-login', null, null, null);
        $collector->finishApiRequest($request, 200, 1);
        $this->loadJournal($collector, $loader);
        $collector->attachWsConnectionToBrowserSession('new-connection', self::NEW_TOKEN, 'new UA', null);
        $collector->identifyBrowserSessionUser(self::NEW_TOKEN, self::USER_ID);
        $collector->logPersonEvent(new AnalyticsPersonEvent(
            self::NEW_TOKEN, self::USER_ID, null, 11, AnalyticsPersonEvent::ACTION, 'new_action',
            null, null, null, 20,
        ));
        $this->loadJournal($collector, $loader);
        $collector->renameBrowserSession(self::OLD_TOKEN, self::NEW_TOKEN);
        $this->loadJournal($collector, $loader);
        $collector->attachWsConnectionToBrowserSession('late-old-connection', self::OLD_TOKEN, null, null);
        $this->loadJournal($collector, $loader);

        $this->assertCount(1, $this->browserSessionIds());
        $this->assertSame(self::NEW_TOKEN, $this->tokenOf($this->browserSessionIds()[0]));
        $this->assertSame((string)self::USER_ID, $this->identityValueOf($this->browserSessionIds()[0]));
        Database::sql('SELECT COUNT(*) AS `total` FROM `hilos_analytics_ws_connection` WHERE `browser_session_id` = ?',
            [$this->browserSessionIds()[0]]);
        $this->assertSame(3, (int)Database::field('total'));
        Database::sql('SELECT COUNT(*) AS `total` FROM `hilos_analytics_api_request` WHERE `browser_session_id` = ?',
            [$this->browserSessionIds()[0]]);
        $this->assertSame(1, (int)Database::field('total'));
        $browserSessionId = $this->browserSessionIds()[0];
        Database::sql('SELECT DISTINCT `browser_session_id` FROM `hilos_analytics_person_event`');
        $this->assertSame((string)$browserSessionId, (string)Database::field('browser_session_id'));
        Database::sql('SELECT u.`value` FROM `hilos_analytics_browser_session` b
            JOIN `hilos_analytics_user_agent` u ON u.`id` = b.`current_user_agent_id`
            WHERE b.`id` = ?', [$this->browserSessionIds()[0]]);
        $this->assertSame('new UA', Database::field('value'));
    }

    /**
     * @throws HilosException When a journal file cannot be loaded
     */
    public function testARenameLoadedBeforeEitherTokenDoesNotCreateAnEmptySession(): void
    {
        $collector = new AnalyticsCollector();
        $collector->renameBrowserSession(self::OLD_TOKEN, self::NEW_TOKEN);
        $this->loadJournal($collector);
        $this->assertSame([], $this->browserSessionIds());

        $collector->attachWsConnectionToBrowserSession(self::ACCEPT_KEY, self::OLD_TOKEN, null, null);
        $this->loadJournal($collector);
        $this->assertCount(1, $this->browserSessionIds());
        $this->assertSame(self::NEW_TOKEN, $this->tokenOf($this->browserSessionIds()[0]));
    }

    /**
     * @return list<int> Ids of the recorded browser sessions, ordered by id
     * @throws DatabaseException When the query fails
     */
    private function browserSessionIds(): array
    {
        Database::sql('SELECT `id` FROM `hilos_analytics_browser_session` ORDER BY `id`');

        $ids = [];
        while (($row = Database::row()) !== null) {
            $ids[] = (int)$row['id'];
        }

        return $ids;
    }

    /**
     * @param int $id Browser session id
     * @return string Token the session answers to
     * @throws DatabaseException When the query fails
     */
    private function tokenOf(int $id): string
    {
        Database::sql('SELECT `session_token` FROM `hilos_analytics_browser_session` WHERE `id` = ?', [$id]);
        $row = Database::row();
        $this->assertNotNull($row);

        return (string)$row['session_token'];
    }

    /**
     * @param int $id Browser session id
     * @return string Identity value recorded for the session
     * @throws DatabaseException When the query fails
     */
    private function identityValueOf(int $id): string
    {
        Database::sql('SELECT `user_identity_value` FROM `hilos_analytics_browser_session` WHERE `id` = ?', [$id]);
        $row = Database::row();
        $this->assertNotNull($row);

        return (string)$row['user_identity_value'];
    }
}
