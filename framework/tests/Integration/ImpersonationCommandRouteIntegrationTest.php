<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\ImpersonateStartActionDTO;
use Hilos\Auth\Session\DTO\ImpersonateStopActionDTO;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\HilosException;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Users\AdminCommandConstants;

/**
 * The agent side of the impersonate:start / impersonate:stop command route (HIL-166, HIL-729).
 *
 * An integration case rather than a unit one for the reason
 * {@see AdminCreateCommandRouteIntegrationTest} gives: every branch below the wire name starts
 * at `Hilos::$db->sessions->findByToken()` and the two that succeed end in a real rebind, so a
 * case that faked the session would pin its own fake instead of the path an operator walks.
 *
 * What is pinned is everything the FRAMEWORK owns, which is all of it but one question. The
 * guards that need no project - a token nobody holds, a session carrying nobody, a session
 * already inside a takeover, a session naming its own user - and the write itself, marker and
 * bind, live here since HIL-729; before that they were copied into whichever project wanted
 * the feature. The one question left to a project is whether the asker may take the target
 * over ({@see AbstractSessionsLibraryAgent::assertImpersonationAllowed()}), and what is pinned
 * about it is the shape of the seam rather than any answer: it is reached with both ids, it is
 * NOT reached once a cheaper guard has refused, and a project that never wired it refuses
 * every takeover instead of writing one.
 *
 * The browser half is the same core through another door, so it is driven here too - one case
 * each way, enough to pin that the door leads to the same place and answers with no reply of
 * its own.
 */
final class ImpersonationCommandRouteIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Token of the acting session; the shape SessionToken accepts. */
    private const string TOKEN = '4f9c1b8e2d7a6053c4e1f8b90a2d3c56';

    /** Token no session row carries. */
    private const string UNKNOWN_TOKEN = '00112233445566778899aabbccddeeff';

    /** User the acting session carries - an administrator, as far as the wired seam is concerned. */
    private const int ADMIN_USER_ID = 7;

    /** User the acting session asks to act as. */
    private const int TARGET_USER_ID = 9;

    /** Accept key standing in for the browser that submitted an action. */
    private const string ACCEPT_KEY = 'accept-1';

    /**
     * @var list<string> Framework tables this case needs. `hilos_setting` is the one framework
     *     collection loaded eagerly, so mounting the context reaches for it.
     */
    private const array TABLES = ['hilos_session', 'hilos_setting'];

    /** @var ?DbContext Database context to restore after the test */
    private ?DbContext $previousDb = null;

    /** @var ?SignalRouter Signal router to restore after the test */
    private ?SignalRouter $previousSignalRouter = null;

    /** @var ?RtContext Runtime to restore after the test; only the browser cases mount one */
    private ?RtContext $previousRt = null;

    /**
     * @throws DatabaseException When a stub statement fails
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);

        $this->previousDb = Hilos::$db;
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;

        $db = new ImpersonationRouteTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new SignalRouter();
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionRotation::RT_COLLECTION);
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$db = $this->previousDb;

        self::runStubs(down: true);

        parent::tearDown();
    }

    public function testBothWireNamesDeclareTheRouteOnTheLibrary(): void
    {
        self::assertContains(CliCommands::IMPERSONATE_START, AbstractSessionsLibraryAgent::AGENT_COMMANDS);
        self::assertContains(CliCommands::IMPERSONATE_STOP, AbstractSessionsLibraryAgent::AGENT_COMMANDS);
    }

    public function testBothBrowserNamesDeclareTheActionOnTheLibrary(): void
    {
        self::assertSame(
            ImpersonateStartActionDTO::class,
            AbstractSessionsLibraryAgent::AGENT_ACTIONS[HilosSignalConstants::HILOS_IMPERSONATE_START] ?? null,
        );
        self::assertSame(
            ImpersonateStopActionDTO::class,
            AbstractSessionsLibraryAgent::AGENT_ACTIONS[HilosSignalConstants::HILOS_IMPERSONATE_STOP] ?? null,
        );
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testAStartReachesTheSeamAndWritesTheMarkerWithTheBind(): void
    {
        self::seedSession(self::TOKEN, self::ADMIN_USER_ID);
        $agent = new ImpersonationRouteTestAgent();

        $this->sendStart($agent, self::TOKEN, self::TARGET_USER_ID);

        $reply = $this->consumeReply();
        self::assertTrue($reply->isOk());
        self::assertSame([self::ADMIN_USER_ID, self::TARGET_USER_ID], $agent->asked);
        self::assertSame(self::TARGET_USER_ID, self::boundUserId(self::TOKEN));
        self::assertSame(self::ADMIN_USER_ID, self::impersonatorUserId(self::TOKEN));
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testAStopRestoresTheAdministratorAndClearsTheMarker(): void
    {
        self::seedSession(self::TOKEN, self::TARGET_USER_ID, self::ADMIN_USER_ID);
        $agent = new ImpersonationRouteTestAgent();

        $this->sendStop($agent, self::TOKEN);

        $reply = $this->consumeReply();
        self::assertTrue($reply->isOk());
        self::assertSame(self::ADMIN_USER_ID, self::boundUserId(self::TOKEN));
        self::assertNull(self::impersonatorUserId(self::TOKEN));
        self::assertNull($agent->asked, 'Ending a takeover asks the project nothing');
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testATokenNamingNoSessionAnswersAsAnErrorReply(): void
    {
        self::seedSession(self::TOKEN, self::ADMIN_USER_ID);
        $agent = new ImpersonationRouteTestAgent();

        $this->sendStart($agent, self::UNKNOWN_TOKEN, self::TARGET_USER_ID);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('No such session', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertNull($agent->asked, 'A token nobody holds never reaches the project seam');
        self::assertSame(self::ADMIN_USER_ID, self::boundUserId(self::TOKEN));
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testAnAnonymousSessionIsRefusedBeforeTheSeamIsAsked(): void
    {
        self::seedSession(self::TOKEN, null);
        $agent = new ImpersonationRouteTestAgent();

        $this->sendStart($agent, self::TOKEN, self::TARGET_USER_ID);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('admin session', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
        // A session carrying nobody has no id to ask the seam about, so the framework has to
        // answer this one itself rather than hand a project a null it cannot judge.
        self::assertNull($agent->asked);
        self::assertNull(self::boundUserId(self::TOKEN));
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testAnUnwiredProjectRefusesEveryTakeover(): void
    {
        self::seedSession(self::TOKEN, self::ADMIN_USER_ID);

        $this->sendStart(new ImpersonationRouteTestUnwiredAgent(), self::TOKEN, self::TARGET_USER_ID);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('not wired', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertSame(self::ADMIN_USER_ID, self::boundUserId(self::TOKEN));
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testASeamRefusalBecomesOneErrorReplyAndWritesNothing(): void
    {
        self::seedSession(self::TOKEN, self::ADMIN_USER_ID);
        $agent = new ImpersonationRouteTestAgent();
        $agent->refuseWith = new ValidationException('Session is not an admin session');

        $this->sendStart($agent, self::TOKEN, self::TARGET_USER_ID);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('admin session', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertSame(self::ADMIN_USER_ID, self::boundUserId(self::TOKEN));
        self::assertNull(self::impersonatorUserId(self::TOKEN));
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testASecondStartIsRefusedWhileAlreadyInsideATakeover(): void
    {
        // The marker is what makes this reachable at all: a session already impersonating a
        // NON-admin fails the project seam first, because the user it carries is the target.
        self::seedSession(self::TOKEN, self::ADMIN_USER_ID, self::ADMIN_USER_ID);
        $agent = new ImpersonationRouteTestAgent();

        $this->sendStart($agent, self::TOKEN, self::TARGET_USER_ID);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        $message = (string)$reply->payload[CommandConstants::FIELD_MESSAGE];
        self::assertStringContainsString('Already impersonating', $message);
        self::assertSame(self::ADMIN_USER_ID, self::boundUserId(self::TOKEN));
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testASessionNamingItsOwnUserIsRefused(): void
    {
        self::seedSession(self::TOKEN, self::ADMIN_USER_ID);
        $agent = new ImpersonationRouteTestAgent();

        $this->sendStart($agent, self::TOKEN, self::ADMIN_USER_ID);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('yourself', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertNull(self::impersonatorUserId(self::TOKEN));
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testAStopOnASessionInsideNoTakeoverAnswersAsAnErrorReply(): void
    {
        self::seedSession(self::TOKEN, self::ADMIN_USER_ID);
        $agent = new ImpersonationRouteTestAgent();

        $this->sendStop($agent, self::TOKEN);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        $message = (string)$reply->payload[CommandConstants::FIELD_MESSAGE];
        self::assertStringContainsString('not impersonating', $message);
        self::assertSame(self::ADMIN_USER_ID, self::boundUserId(self::TOKEN));
    }

    /**
     * The browser door reaches the same core and answers with no reply of its own: what a tab
     * gets back is the identity the state frame carries, not a command reply.
     *
     * @throws DatabaseException When the seed or the read-back fails
     * @throws HilosException When the action fails
     */
    public function testTheBrowserStartActionWritesTheSameTakeoverAndRepliesToNobody(): void
    {
        self::seedSession(self::TOKEN, self::ADMIN_USER_ID);
        $this->mountLiveConnection();
        $agent = new ImpersonationRouteTestAgent();

        $agent->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_IMPERSONATE_START,
            new ImpersonateStartActionDTO(self::TARGET_USER_ID),
        );

        self::assertSame([self::ADMIN_USER_ID, self::TARGET_USER_ID], $agent->asked);
        self::assertSame([self::TARGET_USER_ID, self::ADMIN_USER_ID], self::soleSessionIds());
        self::assertSame([], $this->drainReplies(), 'A browser is answered by its identity, not by a command reply');
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     * @throws HilosException When the action fails
     */
    public function testTheBrowserStopActionEndsTheSameTakeover(): void
    {
        self::seedSession(self::TOKEN, self::TARGET_USER_ID, self::ADMIN_USER_ID);
        $this->mountLiveConnection();
        $agent = new ImpersonationRouteTestAgent();

        $agent->onAgentAction(
            self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_IMPERSONATE_STOP,
            new ImpersonateStopActionDTO(),
        );

        self::assertSame([self::ADMIN_USER_ID, null], self::soleSessionIds());
        self::assertSame([], $this->drainReplies());
    }

    /**
     * Mounts a runtime holding the one live connection the browser cases act from.
     *
     * A browser action reads its session off the acting CONNECTION, so without this the two
     * cases below would take the no-op branch and pin nothing. The command cases mount no
     * runtime on purpose: an operator names the token, and that is the difference.
     */
    private function mountLiveConnection(): void
    {
        $rt = new ImpersonationRouteTestRtContext();
        $rt->configure();
        Hilos::$rt = $rt;

        // A takeover asked for by a browser rotates the token, and a rotation is a runtime
        // write - so somebody has to own that collection or the guard refuses it. Registered
        // agent-less because this case drives the library as a plain object, outside the
        // agent execution context a worker would have entered.
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionRotation::RT_COLLECTION);
    }

    /**
     * Drives one impersonate:start command through the agent under test.
     *
     * @param AbstractAgent $agent Agent under test
     * @param string $sessionToken Session cookie token to send
     * @param int $targetUserId User id to impersonate
     */
    private function sendStart(AbstractAgent $agent, string $sessionToken, int $targetUserId): void
    {
        $agent->onSignalCommand(
            new CommandRequestDTO(
                correlationId: 'corr-1',
                command: CliCommands::IMPERSONATE_START,
                payload: [
                    AdminCommandConstants::FIELD_SESSION_TOKEN => $sessionToken,
                    AdminCommandConstants::FIELD_TARGET_USER_ID => $targetUserId,
                ],
            ),
            '',
            '',
        );
    }

    /**
     * Drives one impersonate:stop command through the agent under test.
     *
     * @param AbstractAgent $agent Agent under test
     * @param string $sessionToken Session cookie token to send
     */
    private function sendStop(AbstractAgent $agent, string $sessionToken): void
    {
        $agent->onSignalCommand(
            new CommandRequestDTO(
                correlationId: 'corr-1',
                command: CliCommands::IMPERSONATE_STOP,
                payload: [AdminCommandConstants::FIELD_SESSION_TOKEN => $sessionToken],
            ),
            '',
            '',
        );
    }

    /**
     * Takes the one reply the agent queued and fails the test when it queued none or two.
     *
     * @return CommandReplyDTO The queued reply
     */
    private function consumeReply(): CommandReplyDTO
    {
        $replies = $this->drainReplies();
        self::assertCount(1, $replies, 'Every command branch answers exactly once');

        return $replies[0];
    }

    /**
     * Empties the signal queue and returns the command replies that were in it.
     *
     * The whole queue is drained rather than read once because a bind writes a row, and a row
     * this worker owns is announced to the others as a DB-sync signal - so a reply is not
     * alone in there on the paths that succeed.
     *
     * @return list<CommandReplyDTO> Replies the run queued, in order
     */
    private function drainReplies(): array
    {
        $replies = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof CommandReplyDTO) {
                $replies[] = $signal->data;
            }
        }

        return $replies;
    }

    /**
     * Inserts a session row the way the handshake would have.
     *
     * @param string $token Session cookie token
     * @param ?int $userId Bound user id, or null for an anonymous session
     * @param ?int $impersonatorUserId Administrator behind a takeover, or null when there is none
     * @throws DatabaseException When the insert fails
     */
    private static function seedSession(string $token, ?int $userId, ?int $impersonatorUserId = null): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_session` (`token`, `user_id`, `impersonator_user_id`) VALUES (?, ?, ?)',
            [$token, $userId, $impersonatorUserId],
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
        return self::sessionColumn($token, 'user_id');
    }

    /**
     * Reads the administrator behind a session's takeover straight from the database.
     *
     * @param string $token Session cookie token
     * @return ?int Impersonator user id, or null when the session is inside no takeover
     * @throws DatabaseException When the query fails
     */
    private static function impersonatorUserId(string $token): ?int
    {
        return self::sessionColumn($token, 'impersonator_user_id');
    }

    /**
     * Reads the bound user and the impersonator off the one session row this case seeded.
     *
     * Named by no token on purpose: a takeover asked for by a BROWSER rotates the token, so
     * the row a browser case has to read back is precisely the one whose name changed. One
     * row is seeded per case, which is what makes reading it without a name honest.
     *
     * @return array{?int, ?int} Bound user id and impersonator user id
     * @throws DatabaseException When the query fails
     */
    private static function soleSessionIds(): array
    {
        Database::sql('SELECT `user_id`, `impersonator_user_id` FROM `hilos_session`', []);
        $row = Database::row();
        self::assertNotNull($row, 'The case seeds exactly one session');

        return [
            $row['user_id'] === null ? null : (int)$row['user_id'],
            $row['impersonator_user_id'] === null ? null : (int)$row['impersonator_user_id'],
        ];
    }

    /**
     * Reads one integer column off a session row.
     *
     * @param string $token Session cookie token
     * @param string $column Column name, named by this class and never by a caller outside it
     * @return ?int Column value, or null when it is null or the session is unknown
     * @throws DatabaseException When the query fails
     */
    private static function sessionColumn(string $token, string $column): ?int
    {
        Database::sql("SELECT `{$column}` FROM `hilos_session` WHERE `token` = ?", [$token]);
        $row = Database::row();

        return $row === null || $row[$column] === null ? null : (int)$row[$column];
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
 */
final class ImpersonationRouteTestDbContext extends HilosDbContext
{
}

/**
 * The framework half of the sessions library, standing in for a project's concrete subclass.
 *
 * A base rather than two copies because the case needs the SAME library twice - once with the
 * seam wired and once without - and the difference is exactly the seam.
 */
abstract class ImpersonationRouteTestHost extends AbstractSessionsLibraryAgent
{
}

/**
 * Sessions library with the impersonation seam wired, standing in for a project binding: it
 * records what the seam was asked instead of reading a project's own admin flag.
 */
final class ImpersonationRouteTestAgent extends ImpersonationRouteTestHost
{
    /** @var ?array{int, int} Ids the seam was asked about, or null when it was not asked */
    public ?array $asked = null;

    /** @var ?ValidationException Refusal the seam raises instead of allowing the takeover */
    public ?ValidationException $refuseWith = null;

    /**
     * Records the question, or refuses the way a project refuses a non-administrator.
     *
     * @param int $adminUserId User the acting session currently carries
     * @param int $targetUserId User that session asks to act as
     * @throws ValidationException When the test asked this seam to refuse
     */
    protected function assertImpersonationAllowed(int $adminUserId, int $targetUserId): void
    {
        if ($this->refuseWith !== null) {
            throw $this->refuseWith;
        }

        $this->asked = [$adminUserId, $targetUserId];
    }
}

/**
 * Sessions library of a project that never wired the seam - the framework default, unchanged.
 */
final class ImpersonationRouteTestUnwiredAgent extends ImpersonationRouteTestHost
{
}

/**
 * Runtime holding one live session-stage connection, so a browser action has a session to act
 * from - the shape the admin-grant route case mounts for the same reason.
 */
final class ImpersonationRouteTestRtContext extends RtContext
{
    /**
     * @throws StateCollectionNotFoundException When the framework's own runtime cannot be mounted
     */
    public function configure(): void
    {
        $connections = ImpersonationRouteTestConnections::init();
        $connections->add(ImpersonationRouteTestConnection::create(
            'accept-1',
            7,
            '4f9c1b8e2d7a6053c4e1f8b90a2d3c56',
        ));
        $this->_stateCollections[ImpersonationRouteTestConnections::RT_COLLECTION] = $connections;

        // What every project's runtime carries whether it asked for it or not, the pending
        // token rotations among it: a takeover asked for by a browser rotates, and a fixture
        // without this collection would fail on the framework's own write rather than on
        // anything the case is about.
        $this->mountFeatureRuntime([]);
    }
}

/**
 * Session-stage connection collection of the fixture project.
 */
final class ImpersonationRouteTestConnections extends HilosSessionConnections
{
    /** @var string Runtime collection name this fixture mounts under */
    public const string RT_COLLECTION = 'impersonationRouteTestConnections';

    public const string STATE_CLASS = ImpersonationRouteTestConnection::class;
}

/**
 * Session-stage connection row of the fixture project, adding nothing of its own.
 */
final class ImpersonationRouteTestConnection extends HilosSessionConnection
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
     * @return array<string, mixed> Own fields, of which this fixture has none
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
