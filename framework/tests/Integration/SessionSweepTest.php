<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\SessionsSweptSignalData;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\WebSocket\DTO\WebSocketHandshakeSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;
use ReflectionProperty;

/**
 * Integration coverage for the bounded cleanup of session rows (HIL-1075).
 *
 * The selection, deletion, in-memory eviction, live-connection veto and handshake
 * refresh are database behavior end to end, so the cases use the real session table
 * and the same sessions library tick production runs.
 */
final class SessionSweepTest extends HilosSessionIntegrationTestCase
{
    private const string EXPIRED_AUTHENTICATED = '11000000000000000000000000000011';
    private const string EXPIRED_ANONYMOUS = '22000000000000000000000000000022';
    private const string OPEN_ENDED = '33000000000000000000000000000033';
    private const string NEVER_RETURNED = '44000000000000000000000000000044';
    private const string FRESH_ANONYMOUS = '55000000000000000000000000000055';
    private const string RETURNED_ANONYMOUS = '66000000000000000000000000000066';
    private const string OLD_AUTHENTICATED = '77000000000000000000000000000077';
    private const string LIVE_SESSION = '88000000000000000000000000000088';

    /** One row beyond the production batch proves continuation without waiting for cron. */
    private const int BATCH_PLUS_ONE = 501;

    private string $boundAppClass;

    private ?RtContext $previousRt = null;

    private ?SignalRouter $previousSignalRouter = null;

    /**
     * @throws DatabaseException When a stub statement or the schema reset fails
     * @throws HilosException When the runtime context cannot be built
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->boundAppClass = Hilos::appClass();
        $this->previousRt = Hilos::$rt;
        $this->previousSignalRouter = Hilos::$sr;
        self::bindAppClass(SessionSweepTestHilos::class);
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new SessionSweepTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionRotation::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionToastStack::RT_COLLECTION);
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionRotation::RT_COLLECTION);
        SourceChangeBus::reset();
        SessionSweepTestConnections::$mounted = null;
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
        self::bindAppClass($this->boundAppClass);

        parent::tearDown();
    }

    /**
     * Expiry removes both authenticated and anonymous rows, while a null expiry survives.
     *
     * @throws HilosException When the sweep fails
     * @throws DatabaseException When seeding or reading a row fails
     */
    public function testExpiryRemovesAuthenticatedAndAnonymousRowsButSparesOpenEndedRows(): void
    {
        self::seedSession(self::EXPIRED_AUTHENTICATED, 7, self::daysAgo(2), self::daysAgo(1));
        self::seedSession(self::EXPIRED_ANONYMOUS, null, self::daysAgo(2), self::daysAgo(1));
        self::seedSession(self::OPEN_ENDED, 8, self::daysAgo(20), null);

        $this->runSweep();

        self::assertNull(self::sessionRow(self::EXPIRED_AUTHENTICATED));
        self::assertNull(self::sessionRow(self::EXPIRED_ANONYMOUS));
        self::assertNotNull(self::sessionRow(self::OPEN_ENDED));
    }

    /**
     * The second criterion removes only an old anonymous row with one handshake.
     *
     * @throws HilosException When the sweep fails
     * @throws DatabaseException When seeding or reading a row fails
     */
    public function testNeverReturnedCriterionKeepsFreshReturnedAndAuthenticatedRows(): void
    {
        self::seedSession(self::NEVER_RETURNED, null, self::daysAgo(8), null);
        self::seedSession(self::FRESH_ANONYMOUS, null, self::daysAgo(1), null);
        self::seedSession(self::RETURNED_ANONYMOUS, null, self::daysAgo(9), null);
        self::setLastSeen(self::RETURNED_ANONYMOUS, self::daysAgo(8));
        self::seedSession(self::OLD_AUTHENTICATED, 9, self::daysAgo(9), null);

        $this->runSweep();

        self::assertNull(self::sessionRow(self::NEVER_RETURNED));
        self::assertNotNull(self::sessionRow(self::FRESH_ANONYMOUS));
        self::assertNotNull(self::sessionRow(self::RETURNED_ANONYMOUS));
        self::assertNotNull(self::sessionRow(self::OLD_AUTHENTICATED));
    }

    /**
     * A live token vetoes both cleanup criteria.
     *
     * @throws HilosException When the sweep fails
     * @throws DatabaseException When seeding or reading a row fails
     */
    public function testLiveConnectionSparesARowReachedByBothQueries(): void
    {
        self::seedSession(self::LIVE_SESSION, null, self::daysAgo(9), self::daysAgo(1));
        SessionSweepTestConnections::$mounted?->add(
            SessionSweepTestConnection::create('accept-live-session', null, self::LIVE_SESSION),
        );

        $this->runSweep();

        self::assertNotNull(self::sessionRow(self::LIVE_SESSION));
    }

    /**
     * Without a connection source expiry remains safe, but age alone does not.
     *
     * @throws HilosException When the sweep fails
     * @throws DatabaseException When seeding or reading a row fails
     */
    public function testMissingConnectionSourceDisablesOnlyNeverReturnedCriterion(): void
    {
        self::seedSession(self::EXPIRED_ANONYMOUS, null, self::daysAgo(9), self::daysAgo(1));
        self::seedSession(self::NEVER_RETURNED, null, self::daysAgo(9), null);
        Hilos::$rt = null;

        $this->runSweep();

        self::assertNull(self::sessionRow(self::EXPIRED_ANONYMOUS));
        self::assertNotNull(self::sessionRow(self::NEVER_RETURNED));
    }

    /**
     * A full batch with removals makes the next tick continue without a cron match.
     *
     * @throws HilosException When the sweep fails
     * @throws DatabaseException When seeding or counting rows fails
     */
    public function testFullBatchContinuesOnTheNextTick(): void
    {
        for ($index = 1; $index <= self::BATCH_PLUS_ONE; $index++) {
            self::seedSession(sprintf('%032x', $index), 10, self::daysAgo(2), self::daysAgo(1));
        }

        $agent = $this->runSweep();
        self::assertSame(1, self::sessionCount());

        $agent->onTick();

        self::assertSame(0, self::sessionCount());
    }

    /**
     * One row selected by both queries is deleted and announced exactly once.
     *
     * @throws HilosException When the sweep fails
     * @throws DatabaseException When seeding or reading a row fails
     */
    public function testOverlappingQueriesDeleteAndAnnounceARowOnce(): void
    {
        self::seedSession(self::EXPIRED_ANONYMOUS, null, self::daysAgo(9), self::daysAgo(1));
        self::seedSession(self::EXPIRED_AUTHENTICATED, 11, self::daysAgo(2), self::daysAgo(1));
        $this->drainSignals();

        $this->runSweep();

        self::assertNull(self::sessionRow(self::EXPIRED_ANONYMOUS));
        self::assertNull(self::sessionRow(self::EXPIRED_AUTHENTICATED));
        self::assertSame(
            [self::EXPIRED_ANONYMOUS, self::EXPIRED_AUTHENTICATED],
            $this->sweptTokens(),
        );
    }

    /**
     * Deletion evicts the object wrapper, so the same token can open a new row.
     *
     * @throws HilosException When the sweep or handshake fails
     * @throws DatabaseException When seeding or reading a row fails
     */
    public function testDeletedSessionLeavesMemoryAndAHandshakeCreatesItAgain(): void
    {
        self::seedSession(self::EXPIRED_AUTHENTICATED, 11, self::daysAgo(2), self::daysAgo(1));
        $oldId = Hilos::$db->sessions->findByToken(self::EXPIRED_AUTHENTICATED)?->id;
        self::assertNotNull($oldId);

        $this->runSweep();
        self::assertNull(Hilos::$db->sessions->findByToken(self::EXPIRED_AUTHENTICATED));

        new SessionSweepTestAgent()->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'accept-returned-after-sweep',
                cookies: [],
                clientIp: null,
                sessionToken: self::EXPIRED_AUTHENTICATED,
            ),
            'test',
            'handshake',
        );

        $newSession = Hilos::$db->sessions->findByToken(self::EXPIRED_AUTHENTICATED);
        self::assertNotNull($newSession);
        self::assertNotSame($oldId, $newSession->id);
        self::assertNull($newSession->userId);
    }

    /**
     * A project that declares no receiver gets no orphan agent frame.
     *
     * @throws HilosException When the sweep fails
     * @throws DatabaseException When seeding a row fails
     */
    public function testNoFrameIsQueuedWhenNoAgentDeclaresIt(): void
    {
        self::bindAppClass(SessionSweepSilentTestHilos::class);
        self::seedSession(self::EXPIRED_AUTHENTICATED, 12, self::daysAgo(2), self::daysAgo(1));
        $this->drainSignals();

        $this->runSweep();

        self::assertSame([], $this->sweptTokens());
    }

    /**
     * Downgrading an expired authenticated session slides the row with the cookie.
     *
     * @throws HilosException When the handshake fails
     * @throws DatabaseException When seeding or reading the row fails
     */
    public function testExpiryDowngradeRefreshesTheSessionRow(): void
    {
        $expiredAt = self::daysAgo(1);
        self::seedSession(self::EXPIRED_AUTHENTICATED, 13, self::daysAgo(2), $expiredAt);
        $sessionId = Hilos::$db->sessions->findByToken(self::EXPIRED_AUTHENTICATED)->id;

        new SessionSweepTestAgent()->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: 'accept-expired-session',
                cookies: [],
                clientIp: null,
                sessionToken: self::EXPIRED_AUTHENTICATED,
            ),
            'test',
            'handshake',
        );

        self::assertNull(self::sessionRow(self::EXPIRED_AUTHENTICATED));
        $row = self::sessionRow(Hilos::$db->sessions[$sessionId]->token);
        self::assertNotNull($row);
        self::assertNull($row['user_id']);
        self::assertGreaterThan($expiredAt, $row['expires_at']);
        self::assertGreaterThan(self::daysAgo(1), $row['last_seen_at']);
    }

    /**
     * Only the opening handshake leaves the two moments equal, which is how the sweep tells a
     * browser that never came back. A later handshake breaks the equality even within one second,
     * because the row keeps milliseconds. The opening also stamps the row from the clock in
     * milliseconds: the creation moment lies between two looks at the clock, and the expiry lies
     * one configured lifetime past them.
     *
     * @throws HilosException When a handshake fails
     * @throws DatabaseException When reading or aging the row fails
     */
    public function testLastSeenEqualsCreatedOnlyUntilTheNextHandshake(): void
    {
        $lifetime = Hilos::$env[EnvConstants::HILOS_SESSION_COOKIE_MAX_AGE]->int() * TimeConstants::MS_PER_SECOND;

        $before = TimeHelper::nowMs();
        $this->handshake(self::NEVER_RETURNED, 'accept-first-visit');
        $after = TimeHelper::nowMs();

        $opened = self::sessionRow(self::NEVER_RETURNED);
        self::assertNotNull($opened);
        self::assertSame($opened['created_at'], $opened['last_seen_at']);
        self::assertGreaterThanOrEqual($before, $opened['created_at']);
        self::assertLessThanOrEqual($after, $opened['created_at']);
        self::assertGreaterThanOrEqual($before + $lifetime, $opened['expires_at']);
        self::assertLessThanOrEqual($after + $lifetime, $opened['expires_at']);

        // Aging the stored moments stands in for waiting: the touch below stamps a later one.
        Database::sqlRun(
            'UPDATE `hilos_session` SET `created_at` = `created_at` - 1, `last_seen_at` = `last_seen_at` - 1 WHERE `token` = ?',
            [self::NEVER_RETURNED],
        );
        Hilos::$db->forgetLoadedDataForTest();
        $this->handshake(self::NEVER_RETURNED, 'accept-second-visit');

        $returned = self::sessionRow(self::NEVER_RETURNED);
        self::assertNotNull($returned);
        self::assertSame($opened['created_at'] - 1, $returned['created_at']);
        self::assertGreaterThan($returned['created_at'], $returned['last_seen_at']);
    }

    /**
     * Delivers one handshake of a browser that names the token, the way the transport does.
     *
     * @param string $token Session token of the browser
     * @param string $acceptKey Connection the handshake arrived on
     * @throws HilosException When the handshake fails
     */
    private function handshake(string $token, string $acceptKey): void
    {
        new SessionSweepTestAgent()->onSignalHandshake(
            new WebSocketHandshakeSignalDTO(
                headers: [],
                acceptKey: $acceptKey,
                cookies: [],
                clientIp: null,
                sessionToken: $token,
            ),
            'test',
            'handshake',
        );
    }

    /**
     * Runs the sweep now by raising the same backlog flag a full prior batch raises.
     *
     * @return SessionSweepTestAgent Agent kept for a following tick
     * @throws HilosException When the library tick fails
     */
    private function runSweep(): SessionSweepTestAgent
    {
        $agent = new SessionSweepTestAgent();
        $agent->onStart();
        new ReflectionProperty(AbstractSessionsLibraryAgent::class, 'sessionSweepBacklog')->setValue($agent, true);
        $agent->onTick();

        return $agent;
    }

    /**
     * @param class-string<Hilos> $hilosClass Project facade used by the route-presence check
     */
    private static function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }

    /**
     * @param int $days Days before now
     * @return int Epoch milliseconds that many days ago
     */
    private static function daysAgo(int $days): int
    {
        return self::secondsAgo($days * TimeConstants::SECONDS_PER_DAY);
    }

    /**
     * @param string $token Session token to update
     * @param int $lastSeenAt New last-seen moment in epoch milliseconds
     * @throws DatabaseException When the update fails
     */
    private static function setLastSeen(string $token, int $lastSeenAt): void
    {
        Database::sqlRun(
            'UPDATE `hilos_session` SET `last_seen_at` = ? WHERE `token` = ?',
            [$lastSeenAt, $token],
        );
    }

    /**
     * @return int Session rows currently stored
     * @throws DatabaseException When the count query fails
     */
    private static function sessionCount(): int
    {
        Database::sql('SELECT COUNT(*) AS `count` FROM `hilos_session`');

        return (int)(Database::row()['count'] ?? 0);
    }

    /**
     * Drains the signal queue and returns tokens from the last sweep frame in it.
     *
     * @return list<string> Removed session tokens, empty when no frame was queued
     */
    private function sweptTokens(): array
    {
        $sessionTokens = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalType->getType() !== SignalTypeConstants::AGENT_SIGNAL
                || $signal->signalName->getName() !== HilosSignalConstants::HILOS_SESSIONS_SWEPT
            ) {
                continue;
            }

            self::assertInstanceOf(AgentSignalData::class, $signal->data);
            self::assertInstanceOf(SessionsSweptSignalData::class, $signal->data->data);
            $sessionTokens = $signal->data->data->sessionTokens;
        }

        return $sessionTokens;
    }

    /** Empties the signal queue before an assertion about the next operation. */
    private function drainSignals(): void
    {
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
        }
    }
}

/**
 * Sessions library fixture whose framework behavior is under test.
 */
final class SessionSweepTestAgent extends AbstractSessionsLibraryAgent
{
}

/**
 * Agent fixture declaring the sweep notification as a typed direct signal.
 */
final class SessionSweepReceiverTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'session_sweep_receiver_test';

    public const array AGENT_SIGNALS = [
        HilosSignalConstants::HILOS_SESSIONS_SWEPT => SessionsSweptSignalData::class,
    ];

    /** The fixture owns no resources across a stop. */
    public function onStop(): void
    {
    }
}

/**
 * Fixture project with a receiver for the session-sweep notification.
 */
abstract class SessionSweepTestHilos extends Hilos
{
    public const array AGENTS = [
        SessionSweepReceiverTestAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => SessionSweepReceiverTestAgent::class,
        ],
    ];
}

/**
 * Fixture project with no receiver for the session-sweep notification.
 */
abstract class SessionSweepSilentTestHilos extends Hilos
{
}

/**
 * Runtime carrying an empty session-stage connection roster.
 */
final class SessionSweepTestRtContext extends RtContext
{
    public function configure(): void
    {
        $connections = SessionSweepTestConnections::init();
        $this->_stateCollections[SessionSweepTestConnections::RT_COLLECTION] = $connections;
        SessionSweepTestConnections::$mounted = $connections;
    }
}

/**
 * Session-stage connection collection used by the live-token veto.
 *
 * @extends HilosSessionConnections<SessionSweepTestConnection>
 */
final class SessionSweepTestConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'sessionSweepTestConnections';

    public const string STATE_CLASS = SessionSweepTestConnection::class;

    public static ?self $mounted = null;
}

/**
 * Session-stage connection row with no project fields.
 */
final class SessionSweepTestConnection extends HilosSessionConnection
{
    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> No project fields
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Incoming field changes
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}
