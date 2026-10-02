<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Users\AdminCommandConstants;

/**
 * The agent side of the admin:create command route (HIL-609).
 *
 * An integration case rather than a unit one because the route IS a session lookup: every
 * branch below the wire name starts at `Hilos::$db->sessions->findByToken()` and the two
 * that succeed end in a real bind, so a case that faked the session would be pinning its own
 * fake instead of the path an operator walks. The two framework tables that path reads are
 * raised from their migration stubs here.
 *
 * What is pinned is the route: that a token naming no session and a failing write each become
 * exactly one error reply, that the write is reached with the user the session carries (or
 * with null when it carries none, expiry included), that `created` tells a mint from a grant
 * and `expired` tells why the user changed (HIL-700). The write itself is the framework's over
 * `hilos_user` since HIL-1197 and is replaced here, so the route is pinned apart from the
 * table; {@see SessionsLibraryPersonIntegrationTest} pins the row it writes.
 */
final class AdminCreateCommandRouteIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Token of a session that exists in every case below; the shape SessionToken accepts. */
    private const string TOKEN = '4f9c1b8e2d7a6053c4e1f8b90a2d3c56';

    /** Token no session row carries. */
    private const string UNKNOWN_TOKEN = '00112233445566778899aabbccddeeff';

    /** User a seeded session already carries, standing in for today's visitor row. */
    private const int EXISTING_USER_ID = 7;

    /**
     * An expiry that has certainly passed, for seeding a session the door has to drop.
     *
     * A fixed date rather than an offset from the clock: the door only asks whether the
     * value is behind now, and a literal cannot land on the wrong side of a slow test.
     */
    private const string PAST_EXPIRY = '2000-01-01 00:00:00';

    /**
     * @var list<string> Framework tables this case needs. `hilos_setting` is the one
     *     framework collection loaded eagerly, so mounting the context reaches for it;
     *     the unfinished registration the handshake response asks about is a column on
     *     `hilos_session` since HIL-612 and needs no table of its own. The people and their
     *     deletion requests have to be there to be empty (HIL-945): every state frame the holder
     *     sends carries the standing of the person the session acts as. The access log joins
     *     because the operator's sign-in writes a row of it (HIL-1174).
     */
    private const array TABLES = ['hilos_session', 'hilos_setting', 'hilos_user', 'hilos_account_deletion', 'hilos_access_log'];

    /** @var ?DbContext Database context to restore after the test */
    private ?DbContext $previousDb = null;

    /** @var ?SignalRouter Signal router to restore after the test */
    private ?SignalRouter $previousSignalRouter = null;

    /** @var ?RtContext Runtime to restore after the test */
    private ?RtContext $previousRt = null;

    /** @var list<SessionStateSignalData> State frames queued alongside the command reply */
    private array $sessionFrames = [];

    /**
     * @throws DatabaseException When a stub statement fails
     * @throws HilosException When the runtime cannot be mounted
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);

        $this->previousDb = Hilos::$db;
        $this->previousSignalRouter = Hilos::$sr;

        $db = new AdminCreateRouteTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new SignalRouter();
        $this->previousRt = Hilos::$rt;
        Hilos::$rt = new AdminCreateRouteTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionRotation::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionToastStack::RT_COLLECTION);
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionRotation::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$db = $this->previousDb;

        self::runStubs(down: true);

        parent::tearDown();
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testATokenNamingNoSessionAnswersAsAnErrorReply(): void
    {
        self::seedSession(self::TOKEN, self::EXISTING_USER_ID);
        $agent = new AdminCreateRouteTestAgent();

        $this->sendCommand($agent, self::UNKNOWN_TOKEN);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('No session', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertFalse($agent->called, 'A token nobody holds never reaches the write');
    }

    /**
     * @throws DatabaseException When the seed fails
     */
    public function testATokenOfTheWrongShapeIsRefusedBeforeTheLookup(): void
    {
        // The socket authenticates nobody, so the payload is whatever was typed at it.
        $agent = new AdminCreateRouteTestAgent();

        $this->sendCommand($agent, 'not-a-token');

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertFalse($agent->called);
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testASessionCarryingAUserReachesTheWriteWithThatId(): void
    {
        self::seedSession(self::TOKEN, self::EXISTING_USER_ID);
        $agent = new AdminCreateRouteTestAgent();

        $this->sendCommand($agent, self::TOKEN);

        $reply = $this->consumeReply();
        self::assertTrue($reply->isOk());
        self::assertSame(self::EXISTING_USER_ID, $agent->seenUserId);
        self::assertSame(self::EXISTING_USER_ID, $reply->payload[AdminCommandConstants::FIELD_USER_ID]);
        self::assertTrue($reply->payload[AdminCommandConstants::FIELD_ADMIN]);
        self::assertFalse($reply->payload[AdminCommandConstants::FIELD_CREATED], 'Nothing was minted');
        self::assertFalse($reply->payload[AdminCommandConstants::FIELD_EXPIRED], 'The session was open-ended');
        self::assertSame(self::EXISTING_USER_ID, self::boundUserId(self::TOKEN));
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testASessionCarryingNoUserReachesTheWriteWithNullAndIsBoundToWhatItMints(): void
    {
        self::seedSession(self::TOKEN, null);
        $agent = new AdminCreateRouteTestAgent();

        $this->sendCommand($agent, self::TOKEN);

        $reply = $this->consumeReply();
        self::assertTrue($reply->isOk());
        self::assertTrue($agent->called);
        self::assertNull($agent->seenUserId, 'A session with no user asks the write to mint one');
        self::assertSame(AdminCreateRouteTestAgent::MINTED_USER_ID, $reply->payload[AdminCommandConstants::FIELD_USER_ID]);
        self::assertTrue($reply->payload[AdminCommandConstants::FIELD_CREATED]);
        self::assertFalse($reply->payload[AdminCommandConstants::FIELD_EXPIRED], 'It carried nobody to lose');
        // The bind is the half that makes the mint usable: without it the operator owns an
        // administrator he has no session to reach it with.
        self::assertSame(AdminCreateRouteTestAgent::MINTED_USER_ID, self::boundUserId(self::TOKEN));
    }

    /**
     * An expired session loses the user it carried and the administrator is somebody new.
     *
     * The point of HIL-700. The command used to find the row with a plain lookup, so a
     * cookie whose expiry had passed was re-bound and slid forward - an expired access
     * became an administrator. It now goes through the same door a handshake uses, which
     * drops it to anonymous first (HIL-398), so the write is asked to mint rather than handed
     * the stale user, and `expired` is what tells the operator why the reply names a user id
     * he has never seen.
     *
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testAnExpiredSessionLosesItsUserAndTheWriteMintsANewOne(): void
    {
        self::seedSession(self::TOKEN, self::EXISTING_USER_ID, self::PAST_EXPIRY);
        $sessionId = Hilos::$db->sessions->findByToken(self::TOKEN)->id;
        $agent = new AdminCreateRouteTestAgent();

        $this->sendCommand($agent, self::TOKEN);

        $reply = $this->consumeReply();
        self::assertTrue($reply->isOk());
        self::assertTrue($agent->called);
        self::assertNull($agent->seenUserId, 'The expired user is gone before the write is asked');
        self::assertSame(AdminCreateRouteTestAgent::MINTED_USER_ID, $reply->payload[AdminCommandConstants::FIELD_USER_ID]);
        self::assertTrue($reply->payload[AdminCommandConstants::FIELD_CREATED]);
        self::assertTrue($reply->payload[AdminCommandConstants::FIELD_EXPIRED]);
        self::assertNull(Hilos::$db->sessions->findByToken(self::TOKEN));
        self::assertSame(AdminCreateRouteTestAgent::MINTED_USER_ID, self::boundUserId(Hilos::$db->sessions[$sessionId]->token));
        self::assertCount(0, Hilos::$rt->hilosSessionRotations, 'No live tab means no ticket to hand out');
    }

    /**
     * An operator has no arriving socket to carry a ticket: expiry gives it to one of
     * the browser's live tabs, and the new administrator is bound under the rotated token.
     *
     * @throws HilosException When a session or runtime fixture cannot be prepared
     */
    public function testExpiredCommandHandsOneLiveTabTheTicketForTheNewAdministrator(): void
    {
        self::seedSession(self::TOKEN, self::EXISTING_USER_ID, self::PAST_EXPIRY);
        $sessionId = Hilos::$db->sessions->findByToken(self::TOKEN)->id;
        self::assertInstanceOf(AdminCreateRouteTestRtContext::class, Hilos::$rt);
        Hilos::$rt->addConnection(AdminCreateRouteTestConnection::create('first-tab', self::EXISTING_USER_ID, self::TOKEN));
        Hilos::$rt->addConnection(AdminCreateRouteTestConnection::create('second-tab', self::EXISTING_USER_ID, self::TOKEN));

        $this->sendCommand(new AdminCreateRouteTestAgent(), self::TOKEN);

        $reply = $this->consumeReply();
        self::assertTrue($reply->isOk());
        self::assertTrue($reply->payload[AdminCommandConstants::FIELD_EXPIRED]);
        self::assertNull(Hilos::$db->sessions->findByToken(self::TOKEN));
        $tickets = array_values(array_filter(
            $this->sessionFrames,
            static fn(SessionStateSignalData $frame): bool => $frame->rotationTicket !== null,
        ));
        self::assertCount(1, $tickets, 'A command must not discard a ticket as if it could answer a handshake');
        self::assertSame(['first-tab'], $tickets[0]->acceptKeys);
        self::assertSame($sessionId, $tickets[0]->sessionId);
        self::assertNotSame(self::TOKEN, $tickets[0]->sessionToken);
        self::assertSame(AdminCreateRouteTestAgent::MINTED_USER_ID, self::boundUserId($tickets[0]->sessionToken));
        $rotation = Hilos::$rt->hilosSessionRotations[$tickets[0]->rotationTicket];
        self::assertNotNull($rotation);
        self::assertSame(['second-tab'], $rotation->acceptKeysToDrop);
        self::assertSame($tickets[0]->sessionToken, $rotation->sessionToken);
        self::assertSame(['second-tab'], $this->sessionFrames[0]->acceptKeys);
        self::assertNull($this->sessionFrames[0]->userId, 'The sibling learns it is a guest before the ticket leaves');
        self::assertNull($this->sessionFrames[0]->rotationTicket);
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testAFailingWriteAnswersAsAnErrorReply(): void
    {
        self::seedSession(self::TOKEN, self::EXISTING_USER_ID);
        $agent = new AdminCreateRouteTestAgent();
        $agent->refuseWith = new ItemNotFoundForUpdateException('No such user: 7');

        $this->sendCommand($agent, self::TOKEN);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('No such user', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
    }

    /**
     * Drives one admin:create command through the agent under test.
     *
     * @param AbstractAgent $agent Agent under test
     * @param string $sessionToken Session cookie token to send
     */
    private function sendCommand(AbstractAgent $agent, string $sessionToken): void
    {
        $agent->onSignalCommand(
            new CommandRequestDTO(
                correlationId: 'corr-1',
                command: CliCommands::ADMIN_CREATE,
                payload: [AdminCommandConstants::FIELD_SESSION_TOKEN => $sessionToken],
            ),
            '',
            '',
        );
    }

    /**
     * Takes the one reply the agent queued and fails the test when it queued none or two.
     *
     * The whole queue is drained rather than read once because the bind writes a row, and a
     * row this worker owns is announced to the others as a DB-sync signal - so the reply is
     * not alone in there on the paths that succeed.
     *
     * @return CommandReplyDTO The queued reply
     */
    private function consumeReply(): CommandReplyDTO
    {
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            } elseif ($signal->data instanceof AgentSignalData && $signal->data->data instanceof SessionStateSignalData) {
                $this->sessionFrames[] = $signal->data->data;
            }
        }

        self::assertCount(1, $replies, 'Every command branch answers exactly once');

        return $replies[0];
    }

    /**
     * Inserts a session row the way the handshake would have.
     *
     * The expiry is written too, because it is what the door judges: a null one is the
     * open-ended session every other case here wants, and a past one is the drop.
     *
     * @param string $token Session cookie token
     * @param ?int $userId Bound user id, or null for an anonymous session
     * @param ?string $expiresAt SQL datetime the session expires at, or null for open-ended
     * @throws DatabaseException When the insert fails
     */
    private static function seedSession(string $token, ?int $userId, ?string $expiresAt = null): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_session` (`token`, `user_id`, `expires_at`) VALUES (?, ?, ?)',
            [$token, $userId, $expiresAt],
        );
    }

    /**
     * Reads a session's bound user straight from the database, past every in-memory collection.
     *
     * @param string $token Session cookie token
     * @return ?int Bound user id, or null when the session is anonymous or unknown
     * @throws DatabaseException When the query fails
     */
    private static function boundUserId(string $token): ?int
    {
        Database::sql('SELECT `user_id` FROM `hilos_session` WHERE `token` = ?', [$token]);
        $row = Database::row();

        return $row === null || $row['user_id'] === null ? null : (int)$row['user_id'];
    }

    /**
     * Runs one direction of the stub file of every table this case uses.
     *
     * @param bool $down Run the down (drop) stubs when true, the create stubs when false
     * @throws DatabaseException When a stub statement fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        foreach (self::TABLES as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/**
 * A framework database context with nothing but the framework's own collections.
 *
 * The route is framework-owned and reads only framework tables, so the smallest honest
 * context for it is {@see HilosDbContext} with no project collections added.
 */
final class AdminCreateRouteTestDbContext extends HilosDbContext
{
}

/**
 * The sessions library with the framework's administrator write replaced, so the route is
 * pinned apart from the person table: it inherits the command route whole, holds no connections
 * of its own, and records what the write was asked and names a user instead of writing a row.
 * The write itself is pinned by {@see SessionsLibraryPersonIntegrationTest}.
 */
final class AdminCreateRouteTestAgent extends AbstractSessionsLibraryAgent
{
    /** @var int User id this write reports having minted for a session that carried none */
    public const int MINTED_USER_ID = 42;

    /** @var bool Whether the write was reached at all */
    public bool $called = false;

    /** @var ?int User id the write was handed, meaningful only once called */
    public ?int $seenUserId = null;

    /** @var ?ItemNotFoundForUpdateException Failure the write raises instead of naming a user */
    public ?ItemNotFoundForUpdateException $refuseWith = null;

    /**
     * Records the call and names the user, or fails the way the framework refuses an unknown one.
     *
     * @param ?int $userId User the session carries, or null when it carries none
     * @return int Id of the user that is now an administrator
     * @throws ItemNotFoundForUpdateException When the test asked this write to refuse
     */
    protected function ensureAdminUser(?int $userId): int
    {
        if ($this->refuseWith !== null) {
            throw $this->refuseWith;
        }

        $this->called = true;
        $this->seenUserId = $userId;

        return $userId ?? self::MINTED_USER_ID;
    }
}

/** Runtime supplying the live browser tabs an operator can name. */
final class AdminCreateRouteTestRtContext extends RtContext
{
    private AdminCreateRouteTestConnections $connections;

    /** Mounts an empty collection that the case can populate with live browser tabs. */
    public function configure(): void
    {
        $this->connections = AdminCreateRouteTestConnections::init();
        $this->_stateCollections[AdminCreateRouteTestConnections::RT_COLLECTION] = $this->connections;
    }

    /**
     * @param AdminCreateRouteTestConnection $connection Fixture socket to expose to the library
     * @throws HilosException When the fixture collection refuses the socket
     */
    public function addConnection(AdminCreateRouteTestConnection $connection): void
    {
        $this->connections->add($connection);
    }
}

/** Session-stage fixture with no project-specific fields. */
final class AdminCreateRouteTestConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'adminCreateRouteTestConnections';
    public const string STATE_CLASS = AdminCreateRouteTestConnection::class;
}

/** Session-stage socket fixture. */
final class AdminCreateRouteTestConnection extends HilosSessionConnection
{
    /** This fixture adds no fields to the session stage. */
    protected function initOwn(): void
    {
    }

    /** @param array<string, mixed> $row Serialized runtime row */
    protected function hydrateOwn(array $row): void
    {
    }

    /** @return array<string, mixed> No project-owned fields */
    protected function ownToArray(): array
    {
        return [];
    }

    /** @param array<string, mixed> $diff Incoming field changes */
    protected function applyOwnDiff(array $diff): void
    {
    }
}
