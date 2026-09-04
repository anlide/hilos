<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Users;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Users\AdminCommandConstants;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the agent side of the admin:grant / admin:revoke command route (HIL-553).
 *
 * The route is declared on the sessions library since HIL-729, so it exists in every project
 * and is answered by whatever reaches the unauthenticated command socket - the CLI class that
 * normally sends it is not on the path. What needs pinning here is everything the framework
 * owns: that both wire names land on the handler, that the payload is validated before the
 * project seam is called, and that the seam's outcome - refusal, failure, success - always
 * becomes exactly one reply. The write itself belongs to a project and is exercised by the
 * demo runs.
 *
 * A write that lands is ANNOUNCED, and the announcement is what moving the route bought: one
 * session state frame per live session of the person, ahead of the reply. The project handler
 * on the other end re-sends the identity from it and re-decides the open pages - the same
 * frame and the same handler a sign-in travels through. The refusing branches read their reply
 * straight off the queue, which is what pins that nothing was announced for a write that never
 * happened.
 *
 * An announcement that FAILS is pinned here too (HIL-849): the write stands, so the operator is
 * owed an ok reply naming the half that did not happen, rather than the silence he used to get
 * while his CLI waited out its budget.
 */
final class AdminGrantCommandRouteTest extends TestCase
{
    /** @var string Session token of the one live connection the fixture runtime holds */
    private const string LIVE_SESSION_TOKEN = 'aaaaaaaabbbbbbbbccccccccdddddddd';

    /** @var string Accept key of that connection */
    private const string LIVE_ACCEPT_KEY = 'accept-1';

    /** @var int User the fixture connection is signed in as */
    private const int LIVE_USER_ID = 7;

    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new AdminGrantRouteTestRtContext();
        Hilos::$rt->configure();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testBothWireNamesDeclareTheRoute(): void
    {
        self::assertContains(CliCommands::ADMIN_GRANT, AbstractSessionsLibraryAgent::AGENT_COMMANDS);
        self::assertContains(CliCommands::ADMIN_REVOKE, AbstractSessionsLibraryAgent::AGENT_COMMANDS);
    }

    public function testGrantReachesTheProjectSeamAndAnswersWithTheFlag(): void
    {
        $agent = new AdminGrantRouteTestAgent();

        $this->sendCommand($agent, CliCommands::ADMIN_GRANT, [
            AdminCommandConstants::FIELD_USER_ID => self::LIVE_USER_ID,
            AdminCommandConstants::FIELD_ADMIN => true,
        ]);

        $this->consumeAnnouncement(self::LIVE_USER_ID);
        $reply = $this->consumeReply();
        self::assertTrue($reply->isOk());
        self::assertSame([self::LIVE_USER_ID, true], $agent->applied);
        self::assertSame(self::LIVE_USER_ID, $reply->payload[AdminCommandConstants::FIELD_USER_ID]);
        self::assertTrue($reply->payload[AdminCommandConstants::FIELD_ADMIN]);
        self::assertTrue($reply->payload[AdminCommandConstants::FIELD_ANNOUNCED]);
        self::assertSame(1, $reply->payload[AdminCommandConstants::FIELD_ANNOUNCED_SESSIONS]);
        self::assertNull($reply->payload[AdminCommandConstants::FIELD_ANNOUNCE_ERROR]);
    }

    public function testRevokeCarriesItsFlagThroughTheSameHandler(): void
    {
        // The flag travels in the payload rather than in the wire name, so the revoke path
        // is only honest if a false flag survives the handler that both names share.
        $agent = new AdminGrantRouteTestAgent();

        $this->sendCommand($agent, CliCommands::ADMIN_REVOKE, [
            AdminCommandConstants::FIELD_USER_ID => self::LIVE_USER_ID,
            AdminCommandConstants::FIELD_ADMIN => false,
        ]);

        $this->consumeAnnouncement(self::LIVE_USER_ID);
        $reply = $this->consumeReply();
        self::assertTrue($reply->isOk());
        self::assertSame([self::LIVE_USER_ID, false], $agent->applied);
        self::assertFalse($reply->payload[AdminCommandConstants::FIELD_ADMIN]);
        self::assertTrue($reply->payload[AdminCommandConstants::FIELD_ANNOUNCED]);
        self::assertSame(1, $reply->payload[AdminCommandConstants::FIELD_ANNOUNCED_SESSIONS]);
        self::assertNull($reply->payload[AdminCommandConstants::FIELD_ANNOUNCE_ERROR]);
    }

    public function testAnUnwiredProjectRefusesAsAnErrorReply(): void
    {
        $this->sendCommand(new AdminGrantRouteTestUnwiredAgent(), CliCommands::ADMIN_GRANT, [
            AdminCommandConstants::FIELD_USER_ID => self::LIVE_USER_ID,
            AdminCommandConstants::FIELD_ADMIN => true,
        ]);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('not wired', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
    }

    public function testAnUnknownUserAnswersAsAnErrorReply(): void
    {
        $agent = new AdminGrantRouteTestAgent();
        $agent->refuseWith = new ItemNotFoundForUpdateException('No such user: 404');

        $this->sendCommand($agent, CliCommands::ADMIN_GRANT, [
            AdminCommandConstants::FIELD_USER_ID => 404,
            AdminCommandConstants::FIELD_ADMIN => true,
        ]);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('No such user', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
    }

    public function testANonPositiveUserIdIsRefusedBeforeTheSeamIsCalled(): void
    {
        $agent = new AdminGrantRouteTestAgent();

        $this->sendCommand($agent, CliCommands::ADMIN_GRANT, [
            AdminCommandConstants::FIELD_USER_ID => 0,
            AdminCommandConstants::FIELD_ADMIN => true,
        ]);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertStringContainsString('positive userId', (string)$reply->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertNull($agent->applied, 'A rejected payload never reaches the project write');
    }

    public function testAMissingUserIdIsRefusedRatherThanReadAsZero(): void
    {
        // The socket authenticates nobody, so a payload arrives as whatever was typed at it;
        // a missing id must not fall through to a user id of 0.
        $agent = new AdminGrantRouteTestAgent();

        $this->sendCommand($agent, CliCommands::ADMIN_GRANT, [
            AdminCommandConstants::FIELD_ADMIN => true,
        ]);

        $reply = $this->consumeReply();
        self::assertFalse($reply->isOk());
        self::assertNull($agent->applied);
    }

    public function testAFailedAnnouncementStillAnswersTheOperator(): void
    {
        // The flag is written by the time the announcement runs, so a failure there used to
        // leave the operator with no reply at all and a CLI parked until its budget ran out.
        Hilos::$rt = new AdminGrantRouteTestFailingRtContext();
        Hilos::$rt->configure();
        $agent = new AdminGrantRouteTestAgent();

        $this->sendCommand($agent, CliCommands::ADMIN_GRANT, [
            AdminCommandConstants::FIELD_USER_ID => self::LIVE_USER_ID,
            AdminCommandConstants::FIELD_ADMIN => true,
        ]);

        $reply = $this->consumeReply();
        self::assertTrue($reply->isOk(), 'A written flag is not reported as an error');
        self::assertSame([self::LIVE_USER_ID, true], $agent->applied);
        self::assertFalse($reply->payload[AdminCommandConstants::FIELD_ANNOUNCED]);
        self::assertSame(0, $reply->payload[AdminCommandConstants::FIELD_ANNOUNCED_SESSIONS]);
        self::assertNotSame('', (string)$reply->payload[AdminCommandConstants::FIELD_ANNOUNCE_ERROR]);
    }

    /**
     * Drives one command through the agent under test.
     *
     * @param AbstractSessionsLibraryAgent $agent Agent under test
     * @param string $command Command-channel wire name to send
     * @param array<string, mixed> $payload Request payload
     */
    private function sendCommand(AbstractSessionsLibraryAgent $agent, string $command, array $payload): void
    {
        $agent->onSignalCommand(
            new CommandRequestDTO(correlationId: 'corr-1', command: $command, payload: $payload),
            '',
            '',
        );
    }

    /**
     * Takes the session state frame the grant queued ahead of its reply.
     *
     * One frame per live session, and the fixture runtime holds exactly one, so the queue in
     * front of the reply is one frame deep. It has to NAME the socket: a frame with an empty
     * accept-key list would reach nobody while still costing the project a page sweep, and
     * that is the difference between announcing the grant and merely queueing something.
     *
     * @param int $userId User the frame must name
     */
    private function consumeAnnouncement(int $userId): void
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal, 'A write that landed is announced to the person\'s tabs');
        // The frame travels to the project agent, so the queue holds it inside the envelope
        // sendToAgent() wraps every agent-addressed payload in.
        self::assertInstanceOf(AgentSignalData::class, $signal->data);
        $frame = $signal->data->data;
        self::assertInstanceOf(SessionStateSignalData::class, $frame);
        self::assertSame($userId, $frame->userId);
        self::assertSame(self::LIVE_SESSION_TOKEN, $frame->sessionToken);
        self::assertSame([self::LIVE_ACCEPT_KEY], $frame->acceptKeys);
    }

    /**
     * Takes the single reply the agent queued and fails the test when it queued none.
     *
     * @return CommandReplyDTO The queued reply
     */
    private function consumeReply(): CommandReplyDTO
    {
        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal, 'Every command branch answers exactly once');
        self::assertInstanceOf(CommandReplyDTO::class, $signal->data);
        self::assertNull(Hilos::$sr->getNextQueuedSignal(), 'No branch answers twice');

        return $signal->data;
    }
}

/**
 * Sessions library with the grant wired, standing in for a project binding: it records the
 * call instead of writing a row, and can be told to fail the way a project refuses an unknown
 * user. The announcement is stubbed too - reading a person's sessions needs a database this
 * suite deliberately does not have, so the fixture states the one session it pretends to hold.
 */
final class AdminGrantRouteTestAgent extends AbstractSessionsLibraryAgent
{
    /** @var ?array{int, bool} Arguments the seam was called with, or null when it was not */
    public ?array $applied = null;

    /** @var ?ItemNotFoundForUpdateException Failure the seam raises instead of writing */
    public ?ItemNotFoundForUpdateException $refuseWith = null;

    public function onStop(): void
    {
    }

    /**
     * Records the grant, or fails the way a project refuses an unknown user.
     *
     * @param int $userId Target user id
     * @param bool $admin New admin flag
     * @throws ItemNotFoundForUpdateException When the test asked this seam to refuse
     */
    protected function applyAdminGrant(int $userId, bool $admin): void
    {
        if ($this->refuseWith !== null) {
            throw $this->refuseWith;
        }

        $this->applied = [$userId, $admin];
    }
}

/**
 * Sessions library of a project that never wired the grant - the framework default, unchanged.
 */
final class AdminGrantRouteTestUnwiredAgent extends AbstractSessionsLibraryAgent
{
    public function onStop(): void
    {
    }
}

/**
 * Runtime holding one live session-stage connection, so the grant has a tab to announce to.
 *
 * The announcement reads the CONNECTIONS rather than the session table - what has to be told
 * is the tabs that are open - so a runtime with one socket is the whole world this route needs.
 */
final class AdminGrantRouteTestRtContext extends RtContext
{
    public function configure(): void
    {
        $connections = AdminGrantRouteTestConnections::init();
        $connections->add(AdminGrantRouteTestConnection::create(
            'accept-1',
            7,
            'aaaaaaaabbbbbbbbccccccccdddddddd',
        ));
        $this->_stateCollections[AdminGrantRouteTestConnections::RT_COLLECTION] = $connections;
    }
}

/**
 * Session-stage connection collection of the fixture project.
 */
final class AdminGrantRouteTestConnections extends HilosSessionConnections
{
    /** @var string Runtime collection name this fixture mounts under */
    public const string RT_COLLECTION = 'adminGrantRouteTestConnections';

    public const string STATE_CLASS = AdminGrantRouteTestConnection::class;
}

/**
 * Session-stage connection row of the fixture project, adding nothing of its own.
 */
final class AdminGrantRouteTestConnection extends HilosSessionConnection
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

/**
 * Runtime whose connection lookup fails, standing in for a broken announcement path.
 *
 * The failure is mounted on the COLLECTION rather than on the context because
 * {@see RtContext::sessionConnectionsSource()} is final: what a test can make throw here is
 * the lookup the announcement calls, which is also the honest place for a runtime failure.
 */
final class AdminGrantRouteTestFailingRtContext extends RtContext
{
    public function configure(): void
    {
        $this->_stateCollections[AdminGrantRouteTestFailingConnections::RT_COLLECTION]
            = AdminGrantRouteTestFailingConnections::init();
    }
}

/**
 * Session-stage connection collection that refuses to name anybody's connections.
 */
final class AdminGrantRouteTestFailingConnections extends HilosSessionConnections
{
    /** @var string Runtime collection name this fixture mounts under */
    public const string RT_COLLECTION = 'adminGrantRouteTestFailingConnections';

    public const string STATE_CLASS = AdminGrantRouteTestConnection::class;

    /**
     * Fails the way the announcement path fails: on the very first thing it asks the runtime.
     *
     * @param ?int $userId User id the announcement is asking about
     * @return array<string, AdminGrantRouteTestConnection> Never returned
     * @throws HilosException Always, which is the whole point of this fixture
     */
    public function findByUser(?int $userId): array
    {
        throw new HilosException('Runtime connections are unreadable');
    }
}
