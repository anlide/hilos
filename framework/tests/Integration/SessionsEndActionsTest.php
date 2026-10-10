<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Library\DTO\AuthOtherSessionsEndSignalData;
use Hilos\HilosException;
use Hilos\Auth\Session\DTO\SessionEndActionDTO;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\DTO\SessionsEndOthersActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Database;
use Hilos\Database\Object\Item\Identity as ObjectIdentity;
use Hilos\Database\View\Item\Session;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\HilosSessionConnections;
use Hilos\Runtime\State\Item\HilosSessionConnection;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\State\Item\HilosSessionToastStack as StateHilosSessionToastStack;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\DTO\UserBrowserTrustRevokeDoneSignalData;
use Hilos\Users\DTO\UserPasswordChangeDoneSignalData;
use Hilos\Users\DTO\UserPasswordChangeSignalData;

/**
 * Integration coverage for ending one or every other browser session.
 *
 * The holder ends the sessions and answers the browser; the trust of the browsers to skip the second
 * factor is the person's, and the person's agent takes it away on the frame the holder sends after
 * (HIL-1407). A new password takes it away in the agent's own write.
 */
final class SessionsEndActionsTest extends HilosSessionIntegrationTestCase
{
    use PersonAgentFrames;

    private const int USER_ID = 41;
    private const int ADMIN_ID = 7;

    private const string CURRENT_TOKEN = '11111111111111111111111111111111';
    private const string OTHER_TOKEN = '22222222222222222222222222222222';
    private const string ADMIN_TOKEN = '33333333333333333333333333333333';

    private const string CURRENT_ACCEPT_KEY = 'accept-current';
    private const string OTHER_ACCEPT_KEY = 'accept-other';
    private const string ADMIN_ACCEPT_KEY = 'accept-admin';

    /**
     * @throws HilosException When the person or runtime fixture cannot be prepared
     */
    protected function setUp(): void
    {
        parent::setUp();
        Database::sqlRun(
            "INSERT INTO `hilos_user` (`id`, `name`) VALUES (?, 'Person'), (?, 'Administrator')",
            [self::USER_ID, self::ADMIN_ID],
        );

        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new SessionsEndActionsRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$rt->configure();
        Hilos::$rt->bindStateCollectionNames();
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosSessionRotation::RT_COLLECTION);
    }

    protected function tearDown(): void
    {
        $this->releasePersonAgents();
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionToastStack::RT_COLLECTION);
        RtTruthSourceRegistry::unregisterDaemon(StateHilosSessionRotation::RT_COLLECTION);
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
        Hilos::$db->secondFactorTrusts->actions->trust(
            $other->id,
            self::USER_ID,
            date('Y-m-d H:i:s', time() + 30 * TimeConstants::SECONDS_PER_DAY),
        );

        $this->dispatch(new SessionEndActionDTO((int)$other->id));

        self::assertNull($other->userId);
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($other->id, self::USER_ID, 30), 'The holder takes no trust itself');
        $revoked = $this->carryTrustRevoke();
        self::assertNull($revoked->error);
        self::assertSame((int)$other->id, $revoked->request->sessionId);
        self::assertFalse($revoked->request->others);
        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($other->id, self::USER_ID, 30));
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
        $signedOut = Hilos::$db->sessions->actions->createAnonymous('44444444444444444444444444444444');
        $until = date('Y-m-d H:i:s', time() + 30 * TimeConstants::SECONDS_PER_DAY);
        foreach ([$current, $other, $signedOut] as $session) {
            Hilos::$db->secondFactorTrusts->actions->trust($session->id, self::USER_ID, $until);
        }

        $this->dispatch(new SessionsEndOthersActionDTO());

        self::assertSame(self::USER_ID, $current->userId);
        self::assertNull($other->userId);
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($other->id, self::USER_ID, 30), 'The holder takes no trust itself');
        $revoked = $this->carryTrustRevoke();
        self::assertNull($revoked->error);
        self::assertSame((int)$current->id, $revoked->request->sessionId);
        self::assertTrue($revoked->request->others);
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($current->id, self::USER_ID, 30));
        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($other->id, self::USER_ID, 30));
        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($signedOut->id, self::USER_ID, 30));
        self::assertSame(self::USER_ID, $admin->userId);
        self::assertSame(self::ADMIN_ID, $admin->impersonatorUserId);
        self::assertSame('Signed out of 1 session', $this->nextSuccessMessage());
    }

    /**
     * The password-change frame uses the same session-ending path as the profile control, and takes
     * no trust: the person's agent took it away before it wrote the password (HIL-1407).
     *
     * @throws HilosException When a seed or session update fails
     */
    public function testPasswordChangeFrameKeepsTheCurrentAndImpersonatedSessions(): void
    {
        $current = $this->session(self::CURRENT_TOKEN, self::CURRENT_ACCEPT_KEY);
        $other = $this->session(self::OTHER_TOKEN, self::OTHER_ACCEPT_KEY);
        $admin = $this->session(self::ADMIN_TOKEN, self::ADMIN_ACCEPT_KEY, self::ADMIN_ID);
        $until = date('Y-m-d H:i:s', time() + 30 * TimeConstants::SECONDS_PER_DAY);
        Hilos::$db->secondFactorTrusts->actions->trust($current->id, self::USER_ID, $until);
        Hilos::$db->secondFactorTrusts->actions->trust($other->id, self::USER_ID, $until);

        new SessionsEndActionsAgent()->onSignalAgent(
            new AgentSignalData(new AuthOtherSessionsEndSignalData(self::USER_ID, self::CURRENT_TOKEN, $current->id)),
            'users-library',
            HilosSignalConstants::HILOS_AUTH_OTHER_SESSIONS_END,
        );

        self::assertSame(self::USER_ID, $current->userId);
        self::assertNull($other->userId);
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($current->id, self::USER_ID, 30));
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($other->id, self::USER_ID, 30));
        self::assertSame(self::USER_ID, $admin->userId);
        self::assertSame(self::ADMIN_ID, $admin->impersonatorUserId);
        $state = $this->nextSessionState();
        self::assertNotNull($state);
        self::assertNull($state->userId);
        self::assertSame([self::OTHER_ACCEPT_KEY], $state->acceptKeys);
        self::assertNull($this->nextSuccessMessage(), 'The holder owes no second acknowledgement');
    }

    /**
     * A profile password change may keep other sessions while their browsers lose trust: the
     * person's agent takes it away in the write of the new password, before the hash (HIL-1407).
     *
     * @throws HilosException When a seed or the agent's write fails
     */
    public function testTrustOnlyPasswordChangeLeavesOtherSessionsSignedIn(): void
    {
        $current = $this->session(self::CURRENT_TOKEN, self::CURRENT_ACCEPT_KEY);
        $other = $this->session(self::OTHER_TOKEN, self::OTHER_ACCEPT_KEY);
        $until = date('Y-m-d H:i:s', time() + 30 * TimeConstants::SECONDS_PER_DAY);
        Hilos::$db->secondFactorTrusts->actions->trust($current->id, self::USER_ID, $until);
        Hilos::$db->secondFactorTrusts->actions->trust($other->id, self::USER_ID, $until);
        $password = Hilos::$db->identities->createPasswordIdentity(self::USER_ID, 'person@example.test', 'old-password-1407');

        $this->asPerson(self::USER_ID, static fn ($agent) => $agent->onSignalAgent(
            new AgentSignalData(new UserPasswordChangeSignalData(
                userId: self::USER_ID,
                identityId: (int)$password->id,
                passwordHash: ObjectIdentity::hashPassword('new-password-1407'),
                signOutOthers: false,
                flowOpen: false,
                keepSessionId: (int)$current->id,
                replySignal: HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE_DONE,
                acceptKey: self::CURRENT_ACCEPT_KEY,
                requestId: 'request-1',
                action: HilosSignalConstants::PROFILE_CHANGE_PASSWORD,
                successMessage: null,
            )),
            'users-library',
            HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE,
        ));

        $answer = $this->nextAgentAnswer(HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE_DONE);
        self::assertInstanceOf(UserPasswordChangeDoneSignalData::class, $answer);
        self::assertNull($answer->error);
        self::assertTrue(Hilos::$db->identities->findPasswordByUser(self::USER_ID)?->verifyPassword('new-password-1407'));
        self::assertSame(self::USER_ID, $other->userId);
        self::assertTrue(Hilos::$db->secondFactorTrusts->isTrusted($current->id, self::USER_ID, 30));
        self::assertFalse(Hilos::$db->secondFactorTrusts->isTrusted($other->id, self::USER_ID, 30));
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
     * Hands the holder's request to take trust away to the person's agent, and returns the agent's
     * answer (HIL-1407).
     *
     * Every other signal taken off the queue goes back on it in the order it came, so the case reads
     * the queue as if the hop had never been there.
     *
     * @return UserBrowserTrustRevokeDoneSignalData The agent's answer to the holder
     * @throws HilosException When the agent fails on the frame
     */
    private function carryTrustRevoke(): UserBrowserTrustRevokeDoneSignalData
    {
        $request = null;
        $kept = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($request === null && $signal->signalName->getName() === HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE) {
                $request = $signal;
            } else {
                $kept[] = $signal;
            }
        }
        self::assertNotNull($request, "The holder asks the person's agent to take the trust away");
        $this->deliverToPerson($request);
        $answer = $this->nextAgentAnswer(HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE_DONE);
        foreach ($kept as $signal) {
            Hilos::$sr?->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
        }
        self::assertInstanceOf(UserBrowserTrustRevokeDoneSignalData::class, $answer);

        return $answer;
    }

    /**
     * @param string $name Answer name the person's agent sends under
     * @return mixed Payload of the first answer under that name, or null when none was queued
     */
    private function nextAgentAnswer(string $name): mixed
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === $name && $signal->data instanceof AgentSignalData) {
                return $signal->data->data;
            }
        }

        return null;
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
