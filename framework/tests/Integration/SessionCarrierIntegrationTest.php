<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Session\SessionCarrier;
use Hilos\Auth\Session\SessionCarryover;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\View\Context\RtContext;

/**
 * Integration coverage for carrying live sessions across a database replacement (HIL-479).
 *
 * Each case plays the whole move: rows as they were before the restore, a snapshot taken over
 * them, then the database swapped underneath (the identity rows rewritten and the in-memory
 * collections re-hydrated, exactly what a restore does to a live node) and the snapshot carried
 * over into it. What is asserted is the only thing that matters to the person at the browser -
 * whether the token still resolves to them afterwards, and to whom.
 */
final class SessionCarrierIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const string TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const string SECOND_TOKEN = 'ffeeddccbbaa99887766554433221100';

    private const string CREATED_AT = '2026-08-01 09:15:00';

    /** Well past any run of this suite: the case is about a live session, not about expiry. */
    private const string EXPIRES_AT = '2036-09-01 09:15:00';

    /** User id before the swap; every "after" id differs, so a carried id can only come from an identity. */
    private const int OLD_USER_ID = 41;

    private const int NEW_USER_ID = 77;

    private const string EMAIL_TYPE = 'password';

    private const string EMAIL = 'ann@example.test';

    private const string SMS_TYPE = 'sms';

    private const string PHONE = '+10000000001';

    /** @var ?RtContext Runtime context to restore after the test */
    private ?RtContext $previousRt = null;

    /**
     * @throws HilosException When the schema reset or the context build fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRt = Hilos::$rt;
        $rt = new SessionCarrierTestRtContext();
        $rt->configure();
        Hilos::$rt = $rt;
    }

    /**
     * @throws HilosException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        Hilos::$rt = $this->previousRt;

        parent::tearDown();
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testASessionLandsOnTheUserItsIdentityNamesInTheRestoredDatabase(): void
    {
        $snapshot = $this->captureLiveSession();
        $this->swapDatabase([[self::NEW_USER_ID, self::EMAIL_TYPE, self::EMAIL]]);

        $result = SessionCarrier::carryOver($snapshot);

        $this->assertSame(1, $result->carried);
        $this->assertSame(0, $result->dropped);
        $this->assertSame(0, $result->kept, 'The third number stays at zero where the pass did the work');
        $row = self::sessionRow(self::TOKEN);
        $this->assertNotNull($row, 'The token resolves again after the restore');
        $this->assertSame((string)self::NEW_USER_ID, (string)$row['user_id'], 'The identity, not the old id, decides');
        $this->assertSame(self::CREATED_AT, (string)$row['created_at']);
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAPersonAbsentFromTheArchiveGetsNoSession(): void
    {
        $snapshot = $this->captureLiveSession();
        $this->swapDatabase([[self::NEW_USER_ID, self::EMAIL_TYPE, 'someone.else@example.test']]);

        $result = SessionCarrier::carryOver($snapshot);

        $this->assertSame(0, $result->carried);
        $this->assertSame(1, $result->dropped);
        $this->assertNull(self::sessionRow(self::TOKEN), 'An unrecognized person is anonymous, not guessed at');
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testIdentitiesPointingAtDifferentAccountsDropTheSession(): void
    {
        self::seedIdentity(self::OLD_USER_ID, self::SMS_TYPE, self::PHONE);
        $snapshot = $this->captureLiveSession();
        // The archive knows the email and the phone number as two different people. Which one the
        // session belonged to is unknowable here, and handing over the wrong account is the one
        // outcome worse than a login screen.
        $this->swapDatabase([
            [self::NEW_USER_ID, self::EMAIL_TYPE, self::EMAIL],
            [self::NEW_USER_ID + 1, self::SMS_TYPE, self::PHONE],
        ]);

        $result = SessionCarrier::carryOver($snapshot);

        $this->assertSame(0, $result->carried);
        $this->assertSame(1, $result->dropped);
        $this->assertNull(self::sessionRow(self::TOKEN));
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testASessionThatOutlivedItsExpiryIsNotCarried(): void
    {
        $snapshot = $this->captureLiveSession(expiresAt: '2026-08-02 09:15:00');
        $this->swapDatabase([[self::NEW_USER_ID, self::EMAIL_TYPE, self::EMAIL]]);

        $result = SessionCarrier::carryOver($snapshot);

        $this->assertSame(0, $result->carried);
        $this->assertSame(1, $result->dropped);
        $this->assertNull(self::sessionRow(self::TOKEN), 'A restore does not resurrect an expired session');
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testASessionThatCameBackWithTheArchiveIsCountedOnItsOwn(): void
    {
        $snapshot = $this->captureLiveSession();
        $this->swapDatabase([[self::NEW_USER_ID, self::EMAIL_TYPE, self::EMAIL]]);
        self::seedSession(self::TOKEN, self::NEW_USER_ID, '2026-07-07 07:07:07', null);

        $result = SessionCarrier::carryOver($snapshot);

        $this->assertSame(0, $result->carried, 'Nothing was written');
        $this->assertSame(0, $result->dropped, 'Nobody was logged out either');
        $this->assertSame(1, $result->kept, 'The outcome has a number of its own now');
        $row = self::sessionRow(self::TOKEN);
        $this->assertNotNull($row);
        $this->assertSame('2026-07-07 07:07:07', (string)$row['created_at'], 'The archived row is left as it is');
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAnImpersonatedSessionComesBackAsTheRealAdministrator(): void
    {
        // The takeover target owns the email; the administrator behind it owns the phone number.
        self::seedIdentity(self::OLD_USER_ID + 1, self::SMS_TYPE, self::PHONE);
        self::seedSession(
            self::TOKEN,
            self::OLD_USER_ID,
            self::CREATED_AT,
            self::EXPIRES_AT,
            impersonatorUserId: self::OLD_USER_ID + 1,
        );
        self::seedIdentity(self::OLD_USER_ID, self::EMAIL_TYPE, self::EMAIL);
        $this->connect('accept-1', self::OLD_USER_ID, self::TOKEN);

        $snapshot = SessionCarrier::capture();
        $this->swapDatabase([
            [self::NEW_USER_ID, self::EMAIL_TYPE, self::EMAIL],
            [self::NEW_USER_ID + 1, self::SMS_TYPE, self::PHONE],
        ]);

        $result = SessionCarrier::carryOver($snapshot);

        $this->assertSame(1, $result->carried);
        $row = self::sessionRow(self::TOKEN);
        $this->assertNotNull($row);
        $this->assertSame(
            (string)(self::NEW_USER_ID + 1),
            (string)$row['user_id'],
            'The session comes back on the human being who held it, not on the account being watched',
        );
        $this->assertNull($row['impersonator_user_id'], 'The right to watch was granted in a database that is gone');
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testTheSnapshotHoldsOneEntryPerTokenAndSkipsAnonymousConnections(): void
    {
        self::seedSession(self::TOKEN, self::OLD_USER_ID, self::CREATED_AT, self::EXPIRES_AT);
        self::seedSession(self::SECOND_TOKEN, null, self::CREATED_AT, self::EXPIRES_AT);
        self::seedIdentity(self::OLD_USER_ID, self::EMAIL_TYPE, self::EMAIL);
        // Two tabs of the same person, plus somebody who never logged in.
        $this->connect('accept-1', self::OLD_USER_ID, self::TOKEN);
        $this->connect('accept-2', self::OLD_USER_ID, self::TOKEN);
        $this->connect('accept-3', null, self::SECOND_TOKEN);

        $snapshot = SessionCarrier::capture();

        $this->assertCount(1, $snapshot);
        $this->assertSame(self::TOKEN, $snapshot[0]->token);
        $this->assertCount(1, $snapshot[0]->identities);
        $this->assertSame(self::EMAIL, $snapshot[0]->identities[0]->identifier);
    }

    /**
     * @throws HilosException When the snapshot fails
     */
    public function testAProjectWithoutSessionConnectionsCarriesNothing(): void
    {
        // A project whose runtime mounts no session-stage connection collection at all: the
        // mechanism is expected to stay silent there rather than report a broken activation,
        // since there are no rows carrying a token it could photograph.
        $rt = new SessionCarrierNoConnectionsRtContext();
        $rt->configure();
        Hilos::$rt = $rt;

        $this->assertSame([], SessionCarrier::capture());
    }

    /**
     * Seeds one live authenticated session with a single identity and photographs it.
     *
     * @param ?string $expiresAt Session expiry as an SQL datetime, or null for an open-ended session
     * @return list<SessionCarryover> The snapshot the swap will be replayed against
     * @throws HilosException When a step against the database fails
     */
    private function captureLiveSession(?string $expiresAt = self::EXPIRES_AT): array
    {
        self::seedSession(self::TOKEN, self::OLD_USER_ID, self::CREATED_AT, $expiresAt);
        self::seedIdentity(self::OLD_USER_ID, self::EMAIL_TYPE, self::EMAIL);
        $this->connect('accept-1', self::OLD_USER_ID, self::TOKEN);

        return SessionCarrier::capture();
    }

    /**
     * Replaces the database contents the way a restore does, and re-reads the collections.
     *
     * The re-hydration is not decoration: without it the context would answer the carry-over
     * from rows it loaded out of the database that no longer exists, which is the very failure
     * this mechanism is built around.
     *
     * @param list<array{int, string, string}> $identities Identity rows of the restored database
     * @throws HilosException When the swap or the re-read fails
     */
    private function swapDatabase(array $identities): void
    {
        Database::sqlRun('DELETE FROM `hilos_session`');
        Database::sqlRun('DELETE FROM `hilos_identity`');
        foreach ($identities as [$userId, $type, $identifier]) {
            self::seedIdentity($userId, $type, $identifier);
        }

        Hilos::$db->reHydrateDbBackedCollections();
    }

    /**
     * Registers one live connection in the runtime collection.
     *
     * @param string $acceptKey WebSocket accept key
     * @param ?int $userId Bound user id, or null for an anonymous connection
     * @param string $sessionToken Session cookie token the socket belongs to
     */
    private function connect(string $acceptKey, ?int $userId, string $sessionToken): void
    {
        /** @var SessionCarrierTestRtContext $rt */
        $rt = Hilos::$rt;
        $rt->connections()->add(SessionCarrierTestConnection::create($acceptKey, $userId, $sessionToken));
    }
}

/**
 * The smallest concrete connection row: the framework session triple and nothing else.
 *
 * It stands on the session stage because that is where the token this mechanism
 * photographs lives; a row with nothing of its own leaves all four project hooks
 * empty, which is exactly what the two simple demos do.
 */
final class SessionCarrierTestConnection extends HilosSessionConnection
{
    /**
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return SessionCarrierTestRtContext::connections;
    }

    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (nothing of its own to read)
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> Always empty: the row is the framework base
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Partial update (nothing of its own to apply)
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/**
 * A project connections collection, as every project that has sessions declares one.
 *
 * @extends HilosSessionConnections<SessionCarrierTestConnection>
 */
final class SessionCarrierTestConnections extends HilosSessionConnections
{
    public const string STATE_CLASS = SessionCarrierTestConnection::class;
}

/**
 * A runtime context whose connections extend the framework base, as demo/chat does.
 */
final class SessionCarrierTestRtContext extends RtContext
{
    public const string connections = 'connections';

    /**
     * Mounts the one collection these cases need: the project's live connections.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = SessionCarrierTestConnections::init();
    }

    /**
     * @return SessionCarrierTestConnections Live connections of this context
     */
    public function connections(): SessionCarrierTestConnections
    {
        /** @var SessionCarrierTestConnections $connections */
        $connections = $this->_stateCollections[self::connections];

        return $connections;
    }
}

/**
 * A runtime context of a project that keeps no framework-based connections.
 */
final class SessionCarrierNoConnectionsRtContext extends RtContext
{
    /**
     * Mounts nothing: the project this stands for keeps no runtime connections at all.
     */
    public function configure(): void
    {
    }
}
