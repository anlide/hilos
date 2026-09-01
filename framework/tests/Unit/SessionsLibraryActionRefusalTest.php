<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\DismissSessionAckActionDTO;
use Hilos\Auth\Session\DTO\ImpersonateStartActionDTO;
use Hilos\Auth\Session\DTO\ImpersonateStopActionDTO;
use Hilos\Auth\Session\DTO\LogoutActionDTO;
use Hilos\Auth\Session\Exception\SessionNotOnConnectionException;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\Exception\AgentUnknownActionException;
use Hilos\Core\Page\Exception\ActionForbiddenException;
use Hilos\Core\Page\Exception\ActionRateLimitedException;
use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\View\Context\RtContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the four controls of the sign-in surface do when the connection carries no session
 * (HIL-730).
 *
 * All four act on the session of the ACTING connection, and all four used to return as if they
 * had acted when that connection held no token. The browser read that as success: the tab still
 * showed the person signed in, the takeover it asked for had not happened, and the only way to
 * learn either was to reload. Sign-out looks like the one that could stay a no-op and is not -
 * the token is gone from the runtime but the cookie is still in the browser.
 *
 * That the throw becomes an action_error frame is the general rail and is pinned by
 * {@see AgentActionRailsTest}; what is pinned here is the refusal itself, its one sentence, and
 * the family the exception is NOT in - {@see PageSignalRouter::failAction()} reads an error code
 * off three families only, and one of those would open the sign-in window on a browser whose
 * reconnect simply had not finished.
 */
final class SessionsLibraryActionRefusalTest extends TestCase
{
    private const string REFUSAL = 'The session behind this tab could not be found; reload the page and try again';

    /** @var string Accept key of the fixture connection that holds no session token */
    private const string SESSIONLESS_ACCEPT_KEY = 'accept-sessionless';

    /** @var string Accept key of the fixture connection that holds one */
    private const string SIGNED_IN_ACCEPT_KEY = 'accept-signed-in';

    protected function setUp(): void
    {
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new SessionsRefusalTestRtContext();
        Hilos::$rt->configure();
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$rt = null;

        parent::tearDown();
    }

    /**
     * @return list<array{string, ActionPayloadDTO}> Each owned action with the payload it takes
     */
    public static function ownedActions(): array
    {
        return [
            'logout' => [HilosSignalConstants::HILOS_LOGOUT, new LogoutActionDTO()],
            'dismiss ack' => [HilosSignalConstants::HILOS_DISMISS_SESSION_ACK, new DismissSessionAckActionDTO()],
            'impersonate start' => [HilosSignalConstants::HILOS_IMPERSONATE_START, new ImpersonateStartActionDTO(9)],
            'impersonate stop' => [HilosSignalConstants::HILOS_IMPERSONATE_STOP, new ImpersonateStopActionDTO()],
        ];
    }

    /**
     * @param string $action Owned action name
     * @param ActionPayloadDTO $dto Payload the action takes
     */
    #[DataProvider('ownedActions')]
    public function testEveryControlRefusesAConnectionThatHoldsNoSession(string $action, ActionPayloadDTO $dto): void
    {
        $agent = new SessionsRefusalTestAgent();

        $this->expectException(SessionNotOnConnectionException::class);
        $this->expectExceptionMessage(self::REFUSAL);

        $agent->onAgentAction(self::SESSIONLESS_ACCEPT_KEY, $action, $dto);
    }

    /**
     * A key no connection row answers to is the same nothing as a row with no token, and the
     * two reach the guard by different routes - a missing row and an empty field.
     */
    public function testAnAcceptKeyNoConnectionAnswersToIsRefusedToo(): void
    {
        $agent = new SessionsRefusalTestAgent();

        $this->expectException(SessionNotOnConnectionException::class);

        $agent->onAgentAction('accept-nobody', HilosSignalConstants::HILOS_LOGOUT, new LogoutActionDTO());
    }

    /**
     * The refusal must not carry an error code: the frontend auth gate opens the sign-in window
     * on a 401-level one, and an empty token is a reconnect in progress, not a person who is
     * signed out. These are the three families {@see PageSignalRouter::failAction()} reads a
     * code off, so being outside all three is what makes the frame an ordinary failure.
     */
    public function testTheRefusalIsInNoFamilyThatCarriesAnErrorCode(): void
    {
        $refusal = new SessionNotOnConnectionException(self::REFUSAL);

        self::assertNotInstanceOf(ActionUnauthorizedException::class, $refusal);
        self::assertNotInstanceOf(ActionForbiddenException::class, $refusal);
        self::assertNotInstanceOf(ActionRateLimitedException::class, $refusal);
    }

    /**
     * The guard runs before the switch, so proving it lets a live session through means
     * reaching a branch past it - and the unknown-action branch is the one that needs no
     * database to answer.
     */
    public function testAConnectionThatHoldsASessionIsNotRefused(): void
    {
        $agent = new SessionsRefusalTestAgent();

        $this->expectException(AgentUnknownActionException::class);

        $agent->onAgentAction(self::SIGNED_IN_ACCEPT_KEY, 'not_an_owned_action', new LogoutActionDTO());
    }
}

/**
 * Sessions library of a fixture project, which needs nothing beyond being instantiable.
 */
final class SessionsRefusalTestAgent extends AbstractSessionsLibraryAgent
{
    public function onStop(): void
    {
    }
}

/**
 * Runtime holding two connection rows: one signed in, one carrying no token.
 */
final class SessionsRefusalTestRtContext extends RtContext
{
    public function configure(): void
    {
        $connections = SessionsRefusalTestConnections::init();
        $connections->add(SessionsRefusalTestConnection::create('accept-signed-in', 7, 'aaaabbbbccccdddd'));
        $connections->add(SessionsRefusalTestConnection::create('accept-sessionless', null, ''));
        $this->_stateCollections[SessionsRefusalTestConnections::RT_COLLECTION] = $connections;
    }
}

/**
 * Session-stage connection collection of the fixture project.
 */
final class SessionsRefusalTestConnections extends HilosSessionConnections
{
    /** @var string Runtime collection name this fixture mounts under */
    public const string RT_COLLECTION = 'sessionsRefusalTestConnections';

    public const string STATE_CLASS = SessionsRefusalTestConnection::class;
}

/**
 * Session-stage connection row of the fixture project, adding nothing of its own.
 */
final class SessionsRefusalTestConnection extends HilosSessionConnection
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
