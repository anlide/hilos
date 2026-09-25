<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTableRow;

/**
 * Integration coverage for the live online mark of the verifier circle table (HIL-1119).
 *
 * The mark is only worth anything if the row of the right person is re-drawn when one of that
 * person's connections comes or goes, and the answer runs through three tables and the live
 * connections: who was named, whose address that is, and whether that person still has a tab
 * open once the change has landed. So the table is asked with a real database and a real
 * connections collection, and each case asserts the mutation it hands back - the row it names
 * and the mark it carries - or that it hands back nothing at all.
 */
final class HilosVerifierCircleTableLiveMarkIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const string TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private const string SECOND_TOKEN = 'ffeeddccbbaa99887766554433221100';

    private const int MEMBER_USER_ID = 41;

    private const int STRANGER_USER_ID = 77;

    private const string EMAIL_TYPE = 'password';

    private const string MEMBER_EMAIL = 'ann@example.test';

    private const string STRANGER_EMAIL = 'someone.else@example.test';

    private const string FIRST_TAB = 'accept-1';

    private const string SECOND_TAB = 'accept-2';

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
        $rt = new CircleLiveMarkTestRtContext();
        $rt->configure();
        // The table finds the connections by the name they are mounted under, so the name has to
        // be told; nobody is subscribed to hear the announcements that telling it switches on.
        $rt->bindStateCollectionNames();
        SourceChangeBus::reset();
        Hilos::$rt = $rt;
    }

    /**
     * @throws HilosException When dropping the stub table fails
     */
    protected function tearDown(): void
    {
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRt;
        self::runCircleStub(down: true);

        parent::tearDown();
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAMemberOpeningATabIsMarkedSignedIn(): void
    {
        $memberId = self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::EMAIL_TYPE, self::MEMBER_EMAIL);
        $connection = $this->connect(self::FIRST_TAB, self::MEMBER_USER_ID, self::TOKEN);

        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::rtCreated(CircleLiveMarkTestRtContext::connections, self::FIRST_TAB, $connection->toArray()),
        );

        $this->assertMarkedRow($mutation, $memberId, true);
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAMemberClosingTheLastTabIsMarkedNotSignedIn(): void
    {
        $memberId = self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::EMAIL_TYPE, self::MEMBER_EMAIL);
        $connection = $this->connect(self::FIRST_TAB, self::MEMBER_USER_ID, self::TOKEN);
        $this->connections()->remove(self::FIRST_TAB);

        // A removal carries the row the connection held: the person is read off it, because the
        // connection itself is already gone.
        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::rtDeleted(CircleLiveMarkTestRtContext::connections, self::FIRST_TAB, $connection->toArray()),
        );

        $this->assertMarkedRow($mutation, $memberId, false);
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testClosingOneOfTwoTabsKeepsTheMemberSignedIn(): void
    {
        $memberId = self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::EMAIL_TYPE, self::MEMBER_EMAIL);
        $closed = $this->connect(self::FIRST_TAB, self::MEMBER_USER_ID, self::TOKEN);
        $this->connect(self::SECOND_TAB, self::MEMBER_USER_ID, self::TOKEN);
        $this->connections()->remove(self::FIRST_TAB);

        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::rtDeleted(CircleLiveMarkTestRtContext::connections, self::FIRST_TAB, $closed->toArray()),
        );

        $this->assertMarkedRow($mutation, $memberId, true);
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAConnectionOfSomebodyNotNamedRedrawsNothing(): void
    {
        self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::STRANGER_USER_ID, self::EMAIL_TYPE, self::STRANGER_EMAIL);
        $connection = $this->connect(self::FIRST_TAB, self::STRANGER_USER_ID, self::TOKEN);

        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::rtCreated(CircleLiveMarkTestRtContext::connections, self::FIRST_TAB, $connection->toArray()),
        );

        $this->assertNull($mutation);
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAGuestConnectionRedrawsNothing(): void
    {
        self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::EMAIL_TYPE, self::MEMBER_EMAIL);
        $connection = $this->connect(self::FIRST_TAB, null, self::SECOND_TOKEN);

        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::rtCreated(CircleLiveMarkTestRtContext::connections, self::FIRST_TAB, $connection->toArray()),
        );

        $this->assertNull($mutation);
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAnUpdateThatLeavesTheBindingAloneRedrawsNothing(): void
    {
        // A project connection moves fields of its own all the time; none of them can change
        // whether the person holds a connection, and a re-drawn row would flash for nothing.
        self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::EMAIL_TYPE, self::MEMBER_EMAIL);
        $this->connect(self::FIRST_TAB, self::MEMBER_USER_ID, self::TOKEN);

        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::rtUpdated(CircleLiveMarkTestRtContext::connections, self::FIRST_TAB, []),
        );

        $this->assertNull($mutation);
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testSigningInOnAnOpenConnectionMarksTheMemberSignedIn(): void
    {
        $memberId = self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::EMAIL_TYPE, self::MEMBER_EMAIL);
        $this->connect(self::FIRST_TAB, self::MEMBER_USER_ID, self::TOKEN);

        $mutation = $this->table()->buildMutationForSourceEvent(SourceChange::rtUpdated(
            CircleLiveMarkTestRtContext::connections,
            self::FIRST_TAB,
            [HilosConnection::userId => self::MEMBER_USER_ID],
        ));

        $this->assertMarkedRow($mutation, $memberId, true);
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testAChangeOfAnotherCollectionRedrawsNothing(): void
    {
        self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::EMAIL_TYPE, self::MEMBER_EMAIL);
        $connection = $this->connect(self::FIRST_TAB, self::MEMBER_USER_ID, self::TOKEN);

        $mutation = $this->table()->buildMutationForSourceEvent(
            SourceChange::rtCreated('somethingElse', self::FIRST_TAB, $connection->toArray()),
        );

        $this->assertNull($mutation);
    }

    /**
     * @throws HilosException When a step against the database fails
     */
    public function testACircleChangeStillRedrawsItsMembership(): void
    {
        $memberId = self::seedCircle(self::EMAIL_TYPE, self::MEMBER_EMAIL);
        self::seedIdentity(self::MEMBER_USER_ID, self::EMAIL_TYPE, self::MEMBER_EMAIL);
        $this->connect(self::FIRST_TAB, self::MEMBER_USER_ID, self::TOKEN);

        $created = $this->table()->buildMutationForSourceEvent(
            SourceChange::dbCreated(HilosDbContext::verifierCircle, (string)$memberId, []),
        );
        $deleted = $this->table()->buildMutationForSourceEvent(
            SourceChange::dbDeleted(HilosDbContext::verifierCircle, (string)$memberId, []),
        );

        $this->assertNotNull($created);
        $this->assertSame(TableMutationType::Create, $created->type);
        $this->assertSame($memberId, $created->rowKey);
        $this->assertNotNull($deleted);
        $this->assertSame(TableMutationType::Delete, $deleted->type);
        $this->assertSame($memberId, $deleted->rowKey);
    }

    /**
     * Asserts that a mutation re-draws one membership's row with the given mark.
     *
     * @param ?TableRowMutationDTO $mutation Mutation the table handed back
     * @param int $memberId Membership whose row is expected
     * @param bool $online Mark the row is expected to carry
     */
    private function assertMarkedRow(?TableRowMutationDTO $mutation, int $memberId, bool $online): void
    {
        $this->assertNotNull($mutation);
        // A connection coming or going never adds or removes a member: the row is re-drawn.
        $this->assertSame(TableMutationType::Update, $mutation->type);
        $this->assertSame($memberId, $mutation->rowKey);
        $this->assertInstanceOf(HilosVerifierCircleTableRow::class, $mutation->row);
        $this->assertSame($online, $mutation->row->online);
    }

    /**
     * @return HilosVerifierCircleTable The table under test
     */
    private function table(): HilosVerifierCircleTable
    {
        return new HilosVerifierCircleTable();
    }

    /**
     * Inserts one named member of the circle, the way the admin surface would have.
     *
     * @param string $type Identity type of the named pair
     * @param string $identifier Normalized identifier of the named pair
     * @return int Membership id of the inserted row
     * @throws HilosException When the insert fails
     */
    private static function seedCircle(string $type, string $identifier): int
    {
        // Not sqlRun(): it restores the statement timeout after the insert, and that statement
        // resets the id the insert left behind.
        Database::sql(
            'INSERT INTO `hilos_verifier_circle` (`identity_type`, `identifier`) VALUES (?, ?)',
            [$type, $identifier],
        );

        return Database::lastInsertId();
    }

    /**
     * Runs one direction of the circle table's stub file.
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
     * @return CircleLiveMarkTestConnection The registered connection
     */
    private function connect(string $acceptKey, ?int $userId, string $sessionToken): CircleLiveMarkTestConnection
    {
        $connection = CircleLiveMarkTestConnection::create($acceptKey, $userId, $sessionToken);
        $this->connections()->add($connection);

        return $connection;
    }

    /**
     * @return CircleLiveMarkTestConnections Live connections of the mounted context
     */
    private function connections(): CircleLiveMarkTestConnections
    {
        /** @var CircleLiveMarkTestRtContext $rt */
        $rt = Hilos::$rt;

        return $rt->connections();
    }
}

/**
 * The smallest concrete connection row: the framework session triple and nothing else.
 */
final class CircleLiveMarkTestConnection extends HilosSessionConnection
{
    /**
     * @return string Runtime collection key
     */
    public static function getRtCollectionKey(): string
    {
        return CircleLiveMarkTestRtContext::connections;
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
 * @extends HilosSessionConnections<CircleLiveMarkTestConnection>
 */
final class CircleLiveMarkTestConnections extends HilosSessionConnections
{
    public const string STATE_CLASS = CircleLiveMarkTestConnection::class;
}

/**
 * A runtime context whose connections extend the framework base, as demo/chat does.
 */
final class CircleLiveMarkTestRtContext extends RtContext
{
    public const string connections = 'connections';

    /**
     * Mounts the one collection these cases need: the project's live connections.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = CircleLiveMarkTestConnections::init();
    }

    /**
     * @return CircleLiveMarkTestConnections Live connections of this context
     */
    public function connections(): CircleLiveMarkTestConnections
    {
        /** @var CircleLiveMarkTestConnections $connections */
        $connections = $this->_stateCollections[self::connections];

        return $connections;
    }
}
