<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseException;
use Hilos\Database\Exception\SqlConnection\CantConnectToMysqlServerException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;

/**
 * Integration coverage for a WebSocket connection finding its browser session (HIL-580).
 *
 * The two halves of a handshake are written by two different processes: the master opens
 * the connection row on its accept loop, where reading a session is forbidden, and a worker
 * attaches the session afterwards from the handshake signal. Nothing but the accept key
 * crosses that boundary, so the join is only as good as the key - which is why it is played
 * here against the real schema, with a separate collector standing in for each process so
 * neither can quietly answer from the other's cache.
 *
 * What used to happen instead is worth naming: the master fed analytics a header no browser
 * can send, so every connection row was written ownerless and stayed that way.
 */
final class AnalyticsWsConnectionSessionIntegrationTest extends AnalyticsSchemaIntegrationTestCase
{
    private const string ACCEPT_KEY = 'accept-key-hil-580';

    private const string SESSION_TOKEN = '0123456789abcdef0123456789abcdef';

    private const string OTHER_TOKEN = 'fedcba9876543210fedcba9876543210';

    private const string USER_AGENT = 'Mozilla/5.0 (HIL-580)';

    private const string ACCEPT_LANGUAGE = 'en-GB,en;q=0.9';

    /** Connection index for the worker that wins the race; the test itself holds the primary one. */
    private const int RIVAL_INDEX = 1;

    private const int RIVAL_SEEN_TS = 1_760_000_000_000;

    /**
     * @throws DatabaseException When reading back the recorded rows fails
     */
    public function testTheWorkerGivesTheConnectionTheSessionTheMasterCouldNotResolve(): void
    {
        $master = new AnalyticsCollector();
        $opened = $master->openWsConnection(self::ACCEPT_KEY, '203.0.113.7');
        $this->assertNotNull($opened);

        // The master writes what it can afford to write, and no more.
        $this->assertNull($this->browserSessionIdOf($opened));

        new AnalyticsCollector()->attachWsConnectionToBrowserSession(
            self::ACCEPT_KEY,
            self::SESSION_TOKEN,
            self::USER_AGENT,
            self::ACCEPT_LANGUAGE,
        );

        $attached = $this->browserSessionIdOf($opened);
        $this->assertNotNull($attached);
        $this->assertSame(self::SESSION_TOKEN, $this->tokenOf($attached));
        $this->assertSame(self::USER_AGENT, $this->userAgentOf($attached));
    }

    /**
     * @throws DatabaseException When reading back the recorded rows fails
     */
    public function testTheAttachTouchesOnlyTheConnectionItWasHandshakenFor(): void
    {
        $master = new AnalyticsCollector();
        $mine = $master->openWsConnection(self::ACCEPT_KEY, null);
        $other = $master->openWsConnection('accept-key-of-a-stranger', null);
        $this->assertNotNull($mine);
        $this->assertNotNull($other);

        new AnalyticsCollector()->attachWsConnectionToBrowserSession(
            self::ACCEPT_KEY,
            self::SESSION_TOKEN,
            null,
            null,
        );

        $this->assertNotNull($this->browserSessionIdOf($mine));
        $this->assertNull($this->browserSessionIdOf($other));
    }

    /**
     * @throws DatabaseException When reading back the recorded rows fails
     */
    public function testAHandshakeWithoutATokenLeavesTheConnectionUnowned(): void
    {
        $opened = new AnalyticsCollector()->openWsConnection(self::ACCEPT_KEY, null);
        $this->assertNotNull($opened);

        new AnalyticsCollector()->attachWsConnectionToBrowserSession(self::ACCEPT_KEY, '', null, null);

        $this->assertNull($this->browserSessionIdOf($opened));
        $this->assertSame([], $this->browserSessionIds());
    }

    /**
     * A second tab is served by a second worker, and both now open a browser session for the
     * same token - a visitor whose tabs each got their own session would be counted twice and
     * joined to their account only once.
     *
     * The two collectors stand in for two worker processes, so neither can find the session in
     * the other's cache: the only thing that keeps them on one row is the table.
     *
     * @throws DatabaseException When reading back the recorded rows fails
     */
    public function testTwoWorkersSeeingTheSameVisitorEndUpOnOneSession(): void
    {
        $firstTab = new AnalyticsCollector();
        $secondTab = new AnalyticsCollector();

        $first = $firstTab->openWsConnection(self::ACCEPT_KEY, null);
        $second = $secondTab->openWsConnection('accept-key-second-tab', null);
        $this->assertNotNull($first);
        $this->assertNotNull($second);

        $firstTab->attachWsConnectionToBrowserSession(self::ACCEPT_KEY, self::SESSION_TOKEN, self::USER_AGENT, null);
        $secondTab->attachWsConnectionToBrowserSession(
            'accept-key-second-tab',
            self::SESSION_TOKEN,
            self::USER_AGENT,
            null,
        );

        $sessions = $this->browserSessionIds();
        $this->assertCount(1, $sessions);
        $this->assertSame($sessions[0], $this->browserSessionIdOf($first));
        $this->assertSame($sessions[0], $this->browserSessionIdOf($second));
    }

    /**
     * The same two tabs again, but this time genuinely at once.
     *
     * The worker that reads the table a moment before the other writes it finds nothing, and
     * then inserts into a unique key that is no longer free. Losing that race must cost the
     * loser nothing: the collector turns itself off on any exception, so an unhandled
     * duplicate would not lose one session row, it would end collection in that worker until
     * the process restarts. The loser is expected to come out holding the winner's session.
     *
     * The interleaving is real rather than simulated: the reading worker takes its snapshot
     * before the writing one commits, so its own read genuinely cannot see the row its insert
     * then collides with.
     *
     * @throws DatabaseException When reading back the recorded rows fails
     * @throws DatabaseConnectionException When the second connection cannot be opened
     * @throws CantConnectToMysqlServerException When connect retries are exhausted
     * @throws EnvException When env variables are missing or invalid
     */
    public function testTheLoserOfTheRaceIsHandedTheWinnersSession(): void
    {
        $loser = new AnalyticsCollector();

        Database::transactionStart();
        // Pins this connection's snapshot to a moment when the session does not exist yet.
        $this->assertSame([], $this->browserSessionIds());

        $winner = $this->insertRivalBrowserSession();

        $joined = $loser->ensureBrowserSession(self::SESSION_TOKEN, self::USER_AGENT, null);
        Database::transactionCommit();

        $this->assertSame($winner, $joined);
        $this->assertSame([$winner], $this->browserSessionIds());
    }

    /**
     * @throws DatabaseException When reading back the recorded rows fails
     */
    public function testTwoVisitorsKeepTheirOwnSessions(): void
    {
        $collector = new AnalyticsCollector();
        $mine = $collector->openWsConnection(self::ACCEPT_KEY, null);
        $theirs = $collector->openWsConnection('accept-key-of-a-stranger', null);
        $this->assertNotNull($mine);
        $this->assertNotNull($theirs);

        $collector->attachWsConnectionToBrowserSession(self::ACCEPT_KEY, self::SESSION_TOKEN, null, null);
        $collector->attachWsConnectionToBrowserSession('accept-key-of-a-stranger', self::OTHER_TOKEN, null, null);

        $this->assertCount(2, $this->browserSessionIds());
        $this->assertNotSame($this->browserSessionIdOf($mine), $this->browserSessionIdOf($theirs));
    }

    /**
     * Writes the competing browser session over a connection of its own, and commits it.
     *
     * A second connection is what makes the race real: the row has to become visible to the
     * unique index while staying invisible to the reader that is already mid-transaction.
     *
     * @return int Id of the session the rival worker created
     * @throws DatabaseException When the insert fails
     * @throws DatabaseConnectionException When the second connection cannot be opened
     * @throws CantConnectToMysqlServerException When connect retries are exhausted
     * @throws EnvException When env variables are missing or invalid
     */
    private function insertRivalBrowserSession(): int
    {
        Database::configure(
            index: self::RIVAL_INDEX,
            host: Hilos::$env[EnvConstants::DB_HOST],
            user: Hilos::$env[EnvConstants::DB_USERNAME],
            password: Hilos::$env[EnvConstants::DB_PASSWORD],
            database: Hilos::$env[EnvConstants::DB_DATABASE],
            port: Hilos::$env->int(EnvConstants::DB_PORT),
            charset: DatabaseConnectionDefaults::CHARSET,
        );
        Database::connect(self::RIVAL_INDEX);
        Database::useConnection(self::RIVAL_INDEX);

        try {
            Database::sql(
                'INSERT INTO `hilos_analytics_browser_session`
                    (`session_token`, `first_seen_ts`, `last_seen_ts`)
                 VALUES (?, ?, ?)',
                [self::SESSION_TOKEN, self::RIVAL_SEEN_TS, self::RIVAL_SEEN_TS],
            );

            return Database::lastInsertId();
        } finally {
            Database::close(self::RIVAL_INDEX);
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }

    /**
     * @param int $wsConnectionId WS connection id
     * @return ?int Browser session the connection belongs to, or null when it has none
     * @throws DatabaseException When the query fails
     */
    private function browserSessionIdOf(int $wsConnectionId): ?int
    {
        Database::sql(
            'SELECT `browser_session_id` FROM `hilos_analytics_ws_connection` WHERE `id` = ?',
            [$wsConnectionId],
        );
        $row = Database::row();
        $this->assertNotNull($row);

        return isset($row['browser_session_id']) ? (int)$row['browser_session_id'] : null;
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
     * @return ?string User agent recorded as the session's current one, or null when none was seen
     * @throws DatabaseException When the query fails
     */
    private function userAgentOf(int $id): ?string
    {
        Database::sql(
            'SELECT `ua`.`value` AS `user_agent`
             FROM `hilos_analytics_browser_session` AS `bs`
             JOIN `hilos_analytics_user_agent` AS `ua` ON `ua`.`id` = `bs`.`current_user_agent_id`
             WHERE `bs`.`id` = ?',
            [$id],
        );
        $row = Database::row();

        return $row === null ? null : (string)$row['user_agent'];
    }
}
