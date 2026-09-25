<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\SessionEndActionDTO;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\DTO\SessionsEndOthersActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\View\Item\Session;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration coverage for ending one or every other browser session.
 */
final class SessionsEndActionsTest extends HilosSessionIntegrationTestCase
{
    private const int USER_ID = 41;
    private const int ADMIN_ID = 7;

    private const string CURRENT_TOKEN = '11111111111111111111111111111111';
    private const string OTHER_TOKEN = '22222222222222222222222222222222';
    private const string ADMIN_TOKEN = '33333333333333333333333333333333';

    private const string CURRENT_ACCEPT_KEY = 'accept-current';
    private const string OTHER_ACCEPT_KEY = 'accept-other';
    private const string ADMIN_ACCEPT_KEY = 'accept-admin';

    protected function setUp(): void
    {
        parent::setUp();

        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new SessionsEndActionsRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionToastStack::RT_COLLECTION);
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        Hilos::$sr = null;
        Hilos::$rt = null;

        parent::tearDown();
    }

    public function testSessionEndPayloadRequiresAPositiveId(): void
    {
        $this->expectException(InvalidFormatException::class);

        SessionEndActionDTO::fromArray([SessionEndActionDTO::sessionId => 0]);
    }

    public function testEndingAnUnknownSessionUsesOneIndistinguishableRefusal(): void
    {
        $current = $this->session(self::CURRENT_TOKEN, self::CURRENT_ACCEPT_KEY);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('This session has already ended');

        $this->dispatch(new SessionEndActionDTO((int)$current->id + 100));
    }

    public function testTheCurrentSessionCannotEndItselfThroughTheOtherSessionAction(): void
    {
        $current = $this->session(self::CURRENT_TOKEN, self::CURRENT_ACCEPT_KEY);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('This is the session you are using — sign out instead');

        $this->dispatch(new SessionEndActionDTO((int)$current->id));
    }

    public function testAnAdministratorsImpersonatingSessionCannotBeEnded(): void
    {
        $this->session(self::CURRENT_TOKEN, self::CURRENT_ACCEPT_KEY);
        $adminSession = $this->session(self::ADMIN_TOKEN, self::ADMIN_ACCEPT_KEY, self::ADMIN_ID);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("An administrator's session cannot be ended from here");

        $this->dispatch(new SessionEndActionDTO((int)$adminSession->id));
    }

    public function testEndingAnotherSessionMakesItAnonymousAndPublishesItsSessionId(): void
    {
        $this->session(self::CURRENT_TOKEN, self::CURRENT_ACCEPT_KEY);
        $other = $this->session(self::OTHER_TOKEN, self::OTHER_ACCEPT_KEY);

        $this->dispatch(new SessionEndActionDTO((int)$other->id));

        self::assertNull($other->userId);
        $frame = $this->nextSessionState();
        self::assertNotNull($frame);
        self::assertSame($other->id, $frame->sessionId);
        self::assertNull($frame->userId);
        self::assertSame([self::OTHER_ACCEPT_KEY], $frame->acceptKeys);
        self::assertSame("Session #{$other->id} ended", $this->nextSuccessMessage());
    }

    public function testEndingAllOthersKeepsCurrentAndAdministratorSessions(): void
    {
        $current = $this->session(self::CURRENT_TOKEN, self::CURRENT_ACCEPT_KEY);
        $other = $this->session(self::OTHER_TOKEN, self::OTHER_ACCEPT_KEY);
        $admin = $this->session(self::ADMIN_TOKEN, self::ADMIN_ACCEPT_KEY, self::ADMIN_ID);

        $this->dispatch(new SessionsEndOthersActionDTO());

        self::assertSame(self::USER_ID, $current->userId);
        self::assertNull($other->userId);
        self::assertSame(self::USER_ID, $admin->userId);
        self::assertSame(self::ADMIN_ID, $admin->impersonatorUserId);
        self::assertSame('Signed out of 1 session', $this->nextSuccessMessage());
    }

    public function testEndingAllOthersReportsWhenThereWereNone(): void
    {
        $this->session(self::CURRENT_TOKEN, self::CURRENT_ACCEPT_KEY);

        $this->dispatch(new SessionsEndOthersActionDTO());

        self::assertSame('No other sessions were signed in', $this->nextSuccessMessage());
    }

    /**
     * @param string $token Session token
     * @param string $acceptKey Live connection key
     * @param ?int $impersonatorUserId Administrator behind a takeover, or null for an ordinary session
     * @return Session Persisted signed-in session
     */
    private function session(string $token, string $acceptKey, ?int $impersonatorUserId = null): Session
    {
        $session = Hilos::$db->sessions->actions->createAnonymous($token);
        $session->actions->bindUser(self::USER_ID);
        if ($impersonatorUserId !== null) {
            $session->actions->setImpersonator($impersonatorUserId);
        }
        Hilos::$rt?->addConnection(SessionsEndActionsConnection::create(
            $acceptKey,
            self::USER_ID,
            $token,
            $session->id,
        ));

        return $session;
    }

    /**
     * @param SessionEndActionDTO|SessionsEndOthersActionDTO $dto Action payload
     */
    private function dispatch(SessionEndActionDTO|SessionsEndOthersActionDTO $dto): void
    {
        $agent = new SessionsEndActionsAgent();
        $agent->beginActionDispatch('request-1');
        try {
            $agent->onAgentAction(self::CURRENT_ACCEPT_KEY, $dto->getAction(), $dto);
            $agent->sendActionSuccess(self::CURRENT_ACCEPT_KEY, $dto->getAction(), 'request-1');
        } finally {
            $agent->endActionDispatch();
        }
    }

    /**
     * @return ?SessionStateSignalData Next session-state frame in the router queue
     */
    private function nextSessionState(): ?SessionStateSignalData
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_SESSION_STATE) {
                continue;
            }
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof SessionStateSignalData) {
                return $signal->data->data;
            }
        }

        return null;
    }

    /**
     * @return ?string Success sentence from the next tracked action acknowledgement
     */
    private function nextSuccessMessage(): ?string
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() !== SignalConstants::ACTION_SUCCESS) {
                continue;
            }
            if (
                $signal->data instanceof WebSocketSignalData
                && $signal->data->data instanceof PageActionSuccessSignalData
            ) {
                return $signal->data->data->message;
            }
        }

        return null;
    }
}

final class SessionsEndActionsAgent extends AbstractSessionsLibraryAgent
{
    public function onStop(): void
    {
    }
}

final class SessionsEndActionsRtContext extends RtContext
{
    /** @var SessionsEndActionsConnections Fixture connection rows */
    private SessionsEndActionsConnections $connections;

    public function configure(): void
    {
        $this->connections = SessionsEndActionsConnections::init();
        $this->_stateCollections[SessionsEndActionsConnections::RT_COLLECTION] = $this->connections;
    }

    /**
     * @param SessionsEndActionsConnection $connection Connection row to mount
     */
    public function addConnection(SessionsEndActionsConnection $connection): void
    {
        $this->connections->add($connection);
    }
}

final class SessionsEndActionsConnections extends HilosSessionConnections
{
    public const string RT_COLLECTION = 'sessionsEndActionsConnections';
    public const string STATE_CLASS = SessionsEndActionsConnection::class;
}

final class SessionsEndActionsConnection extends HilosSessionConnection
{
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
