<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Session\HilosSessionHost;
use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\View\Item\Session;
use Hilos\Hilos;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
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
 * What is pinned is everything the framework owns: that a token naming no session and an
 * unwired project each become exactly one error reply, that the project seam is reached with
 * the user the session carries (or with null when it carries none), and that `created` tells
 * a mint from a grant. The row the seam writes belongs to a project and is exercised by the
 * demo runs.
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
     * @var list<string> Framework tables this case needs. `hilos_registration_wait` is not
     *     read by the command: the handshake response the bind fans out asks every session
     *     whether it left a registration unfinished, so the table is reached whether the case
     *     cares about it or not. `hilos_setting` is the one framework collection loaded
     *     eagerly, so mounting the context reaches for it.
     */
    private const array TABLES = ['hilos_session', 'hilos_setting', 'hilos_registration_wait'];

    /** @var ?DbContext Database context to restore after the test */
    private ?DbContext $previousDb = null;

    /** @var ?SignalRouter Signal router to restore after the test */
    private ?SignalRouter $previousSignalRouter = null;

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

        $db = new AdminCreateRouteTestDbContext();
        $db->configure();
        Hilos::$db = $db;
        Hilos::$sr = new SignalRouter();
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
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
        self::assertFalse($agent->called, 'A token nobody holds never reaches the project write');
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
     * @throws DatabaseException When the seed fails
     */
    public function testAnUnwiredProjectRefusesAsAnErrorReply(): void
    {
        self::seedSession(self::TOKEN, self::EXISTING_USER_ID);

        $this->sendCommand(new AdminCreateRouteTestUnwiredAgent(), self::TOKEN);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('not wired', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testASessionCarryingAUserReachesTheSeamWithThatId(): void
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
        self::assertSame(self::EXISTING_USER_ID, self::boundUserId(self::TOKEN));
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testASessionCarryingNoUserReachesTheSeamWithNullAndIsBoundToWhatItMints(): void
    {
        self::seedSession(self::TOKEN, null);
        $agent = new AdminCreateRouteTestAgent();

        $this->sendCommand($agent, self::TOKEN);

        $reply = $this->consumeReply();
        self::assertTrue($reply->isOk());
        self::assertTrue($agent->called);
        self::assertNull($agent->seenUserId, 'A session with no user asks the seam to mint one');
        self::assertSame(AdminCreateRouteTestAgent::MINTED_USER_ID, $reply->payload[AdminCommandConstants::FIELD_USER_ID]);
        self::assertTrue($reply->payload[AdminCommandConstants::FIELD_CREATED]);
        // The bind is the half that makes the mint usable: without it the operator owns an
        // administrator he has no session to reach it with.
        self::assertSame(AdminCreateRouteTestAgent::MINTED_USER_ID, self::boundUserId(self::TOKEN));
    }

    /**
     * @throws DatabaseException When the seed or the read-back fails
     */
    public function testAFailingSeamAnswersAsAnErrorReply(): void
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
            }
        }

        self::assertCount(1, $replies, 'Every command branch answers exactly once');

        return $replies[0];
    }

    /**
     * Inserts a session row the way the handshake would have.
     *
     * @param string $token Session cookie token
     * @param ?int $userId Bound user id, or null for an anonymous session
     * @throws DatabaseException When the insert fails
     */
    private static function seedSession(string $token, ?int $userId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_session` (`token`, `user_id`) VALUES (?, ?)',
            [$token, $userId],
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
 * The framework half of a session host, standing in for a project agent: it mounts the
 * trait, routes the one command, and holds no connections of its own.
 *
 * A base rather than two copies because the case needs the SAME host twice - once with the
 * minting seam wired and once without - and the difference is exactly the seam.
 */
abstract class AdminCreateRouteTestHost extends AbstractAgent
{
    use HilosSessionHost;

    public const string AGENT_TYPE = 'adminCreateRouteTest';

    public function onStop(): void
    {
    }

    /**
     * Routes the one command this stand-in hosts, exactly as a project agent does.
     *
     * @param CommandRequestDTO $data Command request payload
     * @param string $source Signal source (unused)
     * @param string $name Signal name (unused)
     * @throws InvalidArgumentException When the reply carries an empty correlation id
     */
    public function onSignalCommand(CommandRequestDTO $data, string $source, string $name): void
    {
        $this->handleAdminCreateCommand($data);
    }

    /**
     * Answers the identity of a session without a user store to read it from.
     *
     * @param ?Session $session Session to describe, or null for an anonymous response
     * @return HandshakeResponseSignalData Handshake response for the session
     */
    protected function handshakeResponseFor(?Session $session): HandshakeResponseSignalData
    {
        return new HandshakeResponseSignalData(selfId: $session?->userId, selfAdmin: true);
    }

    /**
     * Re-points one live connection; this stand-in holds none, so nothing is written.
     *
     * @param string $acceptKey Connection accept key to re-point
     * @param ?int $userId User id to bind the connection to, or null for anonymous
     */
    protected function bindConnectionUser(string $acceptKey, ?int $userId): void
    {
    }

    /**
     * Marks one live connection's ack; this stand-in holds none, so nothing is written.
     *
     * @param string $acceptKey Connection accept key to mark
     * @param ?string $ack Ack the connection owes, or null to clear it
     */
    protected function markConnectionAck(string $acceptKey, ?string $ack): void
    {
    }

    /**
     * @return string Signal name the handshake response would go out under
     */
    protected function handshakeResponseSignalName(): string
    {
        return 'adminCreateRouteTestHandshakeResponse';
    }
}

/**
 * Session host with the minting seam wired, standing in for a project binding: it records
 * what the seam was asked and names a user instead of writing a row.
 */
final class AdminCreateRouteTestAgent extends AdminCreateRouteTestHost
{
    /** @var int User id this seam reports having minted for a session that carried none */
    public const int MINTED_USER_ID = 42;

    /** @var bool Whether the seam was reached at all */
    public bool $called = false;

    /** @var ?int User id the seam was handed, meaningful only once called */
    public ?int $seenUserId = null;

    /** @var ?ItemNotFoundForUpdateException Failure the seam raises instead of naming a user */
    public ?ItemNotFoundForUpdateException $refuseWith = null;

    /**
     * Records the call and names the user, or fails the way a project refuses an unknown one.
     *
     * @param ?int $userId User the session carries, or null when it carries none
     * @return int Id of the user that is now an administrator
     * @throws ItemNotFoundForUpdateException When the test asked this seam to refuse
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

/**
 * Session host of a project that never wired the minting seam - the framework default,
 * unchanged.
 */
final class AdminCreateRouteTestUnwiredAgent extends AdminCreateRouteTestHost
{
}
