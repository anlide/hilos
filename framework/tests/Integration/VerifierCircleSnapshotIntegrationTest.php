<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\ProtectedMode\VerifierCircleSnapshot;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;

/**
 * Integration coverage for photographing the verifier circle at a freeze (HIL-643).
 *
 * Three tables and one runtime collection have to agree for the answer to mean anything - who was
 * named, who those names belong to, and which of them had a browser open - so the mechanism cannot
 * be pinned without a real database. What each case asserts is the one thing the freeze acts on:
 * which session token hashes end up on the row, and how many people were named beside them.
 *
 * The named count is asserted as carefully as the hashes because the two answer different
 * questions, and only their pair separates "nobody was named" from "nobody named was online" once
 * the archive's own circle has replaced the one just read.
 */
final class VerifierCircleSnapshotIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const string TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const string SECOND_TOKEN = 'ffeeddccbbaa99887766554433221100';

    private const string CREATED_AT = '2026-08-01 09:15:00';

    /** Well past any run of this suite: these cases are about live sessions, not about expiry. */
    private const string EXPIRES_AT = '2036-09-01 09:15:00';

    private const int MEMBER_USER_ID = 41;

    private const int STRANGER_USER_ID = 77;

    private const string EMAIL_TYPE = 'password';

    private const string MEMBER_EMAIL = 'ann@example.test';

    private const string STRANGER_EMAIL = 'someone.else@example.test';

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

        self::runCircleStub(down: true);
        self::runCircleStub(down: false);

        $this->previousRt = Hilos::$rt;
        $rt = new VerifierCircleTestRtContext();
        $rt->configure();
        Hilos::$rt = $rt;

        // The photograph is skipped outright by a project that does not declare backup, so every
        // case that expects a query needs a facade that does.
        VerifierCircleTestHilos::initBrowser();
    }

    /**
     * @throws HilosException When dropping the stub table fails
     */
    protected function tearDown(): void
    {
        Hilos::initBrowser();
        Hilos::resetBrowser();
        Hilos::$rt = $this->previousRt;
        self::runCircleStub(down: true);

        parent::tearDown();
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testANamedMemberWithATabOpenIsPhotographed(): void
    {
        self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedSession(self::TOKEN, self::MEMBER_USER_ID, self::CREATED_AT, self::EXPIRES_AT);
        $this->connect('accept-1', self::MEMBER_USER_ID, self::TOKEN);

        $snapshot = VerifierCircleSnapshot::capture();

        $this->assertSame(1, $snapshot->namedCount);
        $this->assertSame(
            [ProtectedModeRuntime::hashSessionToken(self::TOKEN)],
            $snapshot->sessionTokenHashes,
            'What travels is the hash of the token, never the token itself',
        );
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testSomebodyOnlineWhoWasNeverNamedIsNotPhotographed(): void
    {
        self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::STRANGER_USER_ID, self::EMAIL_TYPE, self::STRANGER_EMAIL);
        self::seedSession(self::TOKEN, self::STRANGER_USER_ID, self::CREATED_AT, self::EXPIRES_AT);
        $this->connect('accept-1', self::STRANGER_USER_ID, self::TOKEN);

        $snapshot = VerifierCircleSnapshot::capture();

        $this->assertSame(1, $snapshot->namedCount);
        $this->assertSame([], $snapshot->sessionTokenHashes);
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testANamedMemberWithNoTabOpenIsNamedButNotAdmitted(): void
    {
        // The pair the count exists for. Both numbers are needed afterwards, because the circle
        // table is about to be replaced by the archive's own and cannot be asked again.
        self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedSession(self::TOKEN, self::MEMBER_USER_ID, self::CREATED_AT, self::EXPIRES_AT);

        $snapshot = VerifierCircleSnapshot::capture();

        $this->assertSame(1, $snapshot->namedCount);
        $this->assertSame([], $snapshot->sessionTokenHashes);
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAGuestConnectionBelongsToNoCircle(): void
    {
        self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedSession(self::SECOND_TOKEN, null, self::CREATED_AT, self::EXPIRES_AT);
        $this->connect('accept-1', null, self::SECOND_TOKEN);

        $snapshot = VerifierCircleSnapshot::capture();

        $this->assertSame([], $snapshot->sessionTokenHashes);
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAnImpersonatedSessionIsJudgedByThePersonAtTheKeyboard(): void
    {
        // The administrator behind the takeover is the one the circle named; the account being
        // watched is not. The right to watch was granted in a database that is about to be gone,
        // so what carries over is the human being holding the keyboard.
        self::seedCircle(self::SMS_TYPE, self::PHONE);
        self::seedIdentity(self::STRANGER_USER_ID, self::EMAIL_TYPE, self::STRANGER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::SMS_TYPE, self::PHONE);
        self::seedSession(
            self::TOKEN,
            self::STRANGER_USER_ID,
            self::CREATED_AT,
            self::EXPIRES_AT,
            impersonatorUserId: self::MEMBER_USER_ID,
        );
        $this->connect('accept-1', self::STRANGER_USER_ID, self::TOKEN);

        $snapshot = VerifierCircleSnapshot::capture();

        $this->assertSame(
            [ProtectedModeRuntime::hashSessionToken(self::TOKEN)],
            $snapshot->sessionTokenHashes,
        );
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testTwoTabsOfOneBrowserArePhotographedOnce(): void
    {
        self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedSession(self::TOKEN, self::MEMBER_USER_ID, self::CREATED_AT, self::EXPIRES_AT);
        $this->connect('accept-1', self::MEMBER_USER_ID, self::TOKEN);
        $this->connect('accept-2', self::MEMBER_USER_ID, self::TOKEN);

        $snapshot = VerifierCircleSnapshot::capture();

        $this->assertCount(1, $snapshot->sessionTokenHashes);
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAnAddressNobodyProvedNamesNobody(): void
    {
        // A pair may be named before an identity carries it, and stays named after that identity
        // is gone: the circle is a list of addresses, not a set of rows hanging off other rows.
        self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedSession(self::TOKEN, self::MEMBER_USER_ID, self::CREATED_AT, self::EXPIRES_AT);
        $this->connect('accept-1', self::MEMBER_USER_ID, self::TOKEN);

        $snapshot = VerifierCircleSnapshot::capture();

        $this->assertSame(1, $snapshot->namedCount);
        $this->assertSame([], $snapshot->sessionTokenHashes);
    }

    /**
     * @throws HilosException When the snapshot fails
     */
    public function testAProjectWithoutTheBackupFeatureIsAskedNothing(): void
    {
        // The circle table only exists where backup is declared, so the early return is what keeps
        // the other demos free of a query for a table their migrations never created. Asserted by
        // dropping the table first: a photograph that queried it would fail rather than come back
        // empty, which is the only way to tell "asked and found nothing" from "never asked".
        self::runCircleStub(down: true);
        Hilos::initBrowser();
        Hilos::resetBrowser();

        $snapshot = VerifierCircleSnapshot::capture();

        $this->assertSame(0, $snapshot->namedCount);
        $this->assertSame([], $snapshot->sessionTokenHashes);
    }

    /**
     * Inserts one named member of the circle, the way the admin surface would have.
     *
     * @param string $type Identity type of the named pair
     * @param string $identifier Normalized identifier of the named pair
     * @throws HilosException When the insert fails
     */
    private static function seedCircle(string $type, string $identifier): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_verifier_circle` (`identity_type`, `identifier`) VALUES (?, ?)',
            [$type, $identifier],
        );
    }

    /**
     * Runs one direction of the circle table's stub file.
     *
     * Raised here rather than in the shared session base: the other cases on that base neither
     * read nor write this table, and a base that created it for them would say they did.
     *
     * @param bool $down Run the down (drop) stub when true, the create stub when false
     * @throws HilosException When the stub statement fails
     */
    private static function runCircleStub(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_hilos_verifier_circle{$suffix}.sql";
        Database::sqlRun((string)file_get_contents($stub));
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
        /** @var VerifierCircleTestRtContext $rt */
        $rt = Hilos::$rt;
        $rt->connections()->add(VerifierCircleTestConnection::create($acceptKey, $userId, $sessionToken));
    }
}

/**
 * Project facade fixture declaring the feature the circle table belongs to.
 */
final class VerifierCircleTestHilos extends Hilos
{
    protected const array FEATURES = [HilosFeature::BACKUP];

    /**
     * Creates a no-op DB context for the abstract facade contract.
     *
     * The cases run against the context the session base mounts; this exists only because the
     * facade is abstract, and is never the one asked a question.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new HilosSessionTestDbContext();
    }
}

/**
 * The smallest concrete connection row: the framework session triple and nothing else.
 */
final class VerifierCircleTestConnection extends HilosSessionConnection
{
    /**
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return VerifierCircleTestRtContext::connections;
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
 * @extends HilosSessionConnections<VerifierCircleTestConnection>
 */
final class VerifierCircleTestConnections extends HilosSessionConnections
{
    public const string STATE_CLASS = VerifierCircleTestConnection::class;
}

/**
 * A runtime context whose connections extend the framework base, as demo/chat does.
 */
final class VerifierCircleTestRtContext extends RtContext
{
    public const string connections = 'connections';

    /**
     * Mounts the one collection these cases need: the project's live connections.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = VerifierCircleTestConnections::init();
    }

    /**
     * @return VerifierCircleTestConnections Live connections of this context
     */
    public function connections(): VerifierCircleTestConnections
    {
        /** @var VerifierCircleTestConnections $connections */
        $connections = $this->_stateCollections[self::connections];

        return $connections;
    }
}
