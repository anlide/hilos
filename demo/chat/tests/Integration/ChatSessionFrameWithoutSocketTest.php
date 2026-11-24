<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Core\Daemon\ChatWorkerManager;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\SessionToken;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Page\DTO\PageAccessReassessUserSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Runtime\Ordinary\RtOrdinaryAdmission;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\DTO\UserSessionsRestateSignalData;
use ReflectionMethod;
use ReflectionProperty;

/** Session restatements use the project's socket registry before doing any identity work. */
final class ChatSessionFrameWithoutSocketTest extends IntegrationTestCase
{
    use ChatModerationFixture;

    private const string SESSION_TOKEN = 'aaaabbbbccccddddeeeeffff11112222';
    private const string ACCEPT_KEY = 'session-frame-socket';

    private ChatAgent $agent;
    private int $userId;
    private int $sessionId;

    /** Mounts the real chat routes with a person whose session initially has no sockets. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->claimChatChecks();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        $this->userId = (int)Hilos::$db->users->actions->createWithName('Session frame person')->id;
        $session = Hilos::$db->sessions->actions->createAnonymous(self::SESSION_TOKEN);
        $session->actions->bindUser($this->userId);
        $this->sessionId = $session->id;
        $this->agent = new ChatAgent();
        $this->drainSignals();
    }

    /** Removes the case's connection, transient user state and person. */
    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->sessions[$this->sessionId]->actions->delete();
        Hilos::$db->users[$this->userId]->actions->delete();
        parent::tearDown();
    }

    public function testASessionWithoutSocketsSendsNoIdentityOrPageReassessment(): void
    {
        $this->deliverChatSignal($this->agent, HilosSignalConstants::HILOS_SESSION_STATE,
            new SessionStateSignalData(self::SESSION_TOKEN, $this->sessionId, $this->userId, []));

        $names = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $names[] = $signal->signalName->getName();
        }
        self::assertNotContains(ChatSignalConstants::HANDSHAKE_RESPONSE, $names);
        self::assertNotContains(SignalConstants::PAGE_ACCESS_REASSESS_USER, $names);
        self::assertCount(0, Hilos::$rt->connections);
        self::assertCount(0, Hilos::$rt->userStates);
    }

    public function testAnUnnamedSocketKnownToTheProjectGetsIdentityAndPageReassessment(): void
    {
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $this->userId, self::SESSION_TOKEN);
        $this->drainSignals();

        $this->deliverChatSignal($this->agent, HilosSignalConstants::HILOS_SESSION_STATE,
            new SessionStateSignalData(self::SESSION_TOKEN, $this->sessionId, $this->userId, []));

        $responses = [];
        $reassessments = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === ChatSignalConstants::HANDSHAKE_RESPONSE) {
                self::assertInstanceOf(WebSocketSignalData::class, $signal->data);
                self::assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
                self::assertInstanceOf(HandshakeResponseSignalData::class, $signal->data->data);
                $responses[] = $signal->data->data;
            }
            if ($signal->signalName->getName() === SignalConstants::PAGE_ACCESS_REASSESS_USER) {
                self::assertInstanceOf(PageAccessReassessUserSignalData::class, $signal->data);
                $reassessments[] = $signal->data->userId;
            }
        }
        self::assertCount(1, $responses);
        self::assertSame($this->userId, $responses[0]->selfId);
        self::assertSame([$this->userId], $reassessments);
    }

    public function testAnAuthenticatedRestatementForARotatedAwayTokenLeavesNoWork(): void
    {
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $this->userId, self::SESSION_TOKEN, $this->sessionId);
        $library = $this->sessionsLibrary();
        $this->underAgent($library, fn () => Hilos::$db->sessions[$this->sessionId]->actions->rotateTokenAndUnbindUser(SessionToken::mint()));
        self::assertNull(Hilos::$db->sessions->findByToken(self::SESSION_TOKEN));
        $this->drainSignals();

        // The library can still name the old row from its mirror. Chat sees the close before the frame.
        $frame = $this->restateSession();
        self::assertSame($this->userId, $frame->data->userId);
        self::assertNull($frame->data->sessionId);
        $this->deliverRestatementAfterClose($frame, fullOrdinary: false);
    }

    public function testALateSingletonCannotRegisterAfterItsFirstVisitWasRefused(): void
    {
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $this->userId, self::SESSION_TOKEN, $this->sessionId);
        $this->drainSignals();

        $frame = $this->restateSession();
        self::assertSame($this->sessionId, $frame->data->sessionId);
        self::assertNull($frame->data->requestId);
        $this->deliverRestatementAfterClose($frame, fullOrdinary: true);
    }

    public function testANullableGuestFrameStillAnswersItsRegisteredInitiator(): void
    {
        $token = SessionToken::mint();
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, null, $token);
        $this->drainSignals();
        $this->deliverChatSignal($this->agent, HilosSignalConstants::HILOS_SESSION_STATE,
            new SessionStateSignalData($token, null, null, [self::ACCEPT_KEY],
                requestId: 'guest-request', action: HilosSignalConstants::HILOS_DISMISS_ACCOUNT_BLOCKED));

        $effects = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                self::assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
                $payload = $signal->data->data;
                if ($payload instanceof HandshakeResponseSignalData) {
                    self::assertNull($payload->selfId);
                    $effects[] = $signal->signalName->getName();
                } elseif ($payload instanceof PageActionSuccessSignalData) {
                    $effects[] = $payload->requestId;
                }
            }
        }
        self::assertSame([ChatSignalConstants::HANDSHAKE_RESPONSE, 'guest-request'], $effects);
    }

    public function testAnEligibleSiblingCannotAnswerForTheFilteredInitiator(): void
    {
        $older = Hilos::$db->sessions->findByToken(self::SESSION_TOKEN);
        $newer = $this->openModerationSession($this->userId);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $this->userId, $newer->token, $newer->id);
        Hilos::$rt->connections->actions->register('sibling', $this->userId, $older->token, $older->id);
        $this->drainSignals();

        $this->deliverChatSignal($this->agent, HilosSignalConstants::HILOS_SESSION_STATE,
            new SessionStateSignalData(
                $older->token, $older->id, $this->userId, [self::ACCEPT_KEY],
                rotationTicket: 'rotation-ticket', requestId: 'old-request', action: 'sign-out',
            ));

        $browserSignals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof WebSocketSignalData) {
                $browserSignals[] = [$signal->signalName->getName(), $signal->data->targetAcceptKey];
            }
        }
        self::assertSame([[ChatSignalConstants::HANDSHAKE_RESPONSE, 'sibling']], $browserSignals);
        self::assertSame($newer->id, Hilos::$rt->connections[self::ACCEPT_KEY]->sessionId);
        $newer->actions->delete();
    }

    /** @return AgentSignalData The library's real restatement of the only mirrored connection */
    private function restateSession(): AgentSignalData
    {
        $library = $this->sessionsLibrary();
        $this->underAgent($library, fn () => $library->onSignalAgent(
            new AgentSignalData(new UserSessionsRestateSignalData($this->userId)),
            'users',
            HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE,
        ));
        $frames = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->data instanceof AgentSignalData && $signal->data->data instanceof SessionStateSignalData) {
                $frames[] = $signal->data;
            }
        }
        self::assertCount(1, $frames);
        self::assertSame([self::ACCEPT_KEY], $frames[0]->data->acceptKeys);

        return $frames[0];
    }

    private function deliverRestatementAfterClose(AgentSignalData $frame, bool $fullOrdinary): void
    {
        $this->withSessionWorker(
            $this->agent,
            [self::ACCEPT_KEY],
            function (ChatWorkerManager $worker, string $agentId) use ($frame, $fullOrdinary): void {
                $this->sendWorkerSignal($worker, $agentId, SignalTypeConstants::CONNECTION_CLOSE, new WebSocketCloseSignalDTO(self::ACCEPT_KEY),
                    SignalTypeConstants::CONNECTION_CLOSE, SignalSource::WEBSOCKET);
                self::assertFalse(isset(Hilos::$rt->connections[self::ACCEPT_KEY]));
                $this->drainSignals();
                if ($fullOrdinary) {
                    $this->fillSessionWorkerOrdinaryQueue($agentId);
                }
                $this->sendWorkerSignal($worker, $agentId, HilosSignalConstants::HILOS_SESSION_STATE, $frame);
                self::assertFalse(isset(Hilos::$rt->connections[self::ACCEPT_KEY]));

                RtOrdinaryAdmission::consumeBy(null);
                RtOrdinaryAdmission::handOnCommitted();
                $tick = new ReflectionMethod(WorkerManager::class, 'tickAgents');
                $tick->invoke($worker);
                $tick->invoke($worker);

                self::assertFalse(isset(Hilos::$rt->connections[self::ACCEPT_KEY]));
                self::assertNull($this->lastHandshakeResponseFor(self::ACCEPT_KEY));
                $manager = new ReflectionProperty(WorkerManager::class, 'agentManager')->getValue($worker);
                $owner = $manager->getAgent($agentId);
                self::assertInstanceOf(ChatAgent::class, $owner);
                self::assertSame([], new ReflectionProperty(ChatAgent::class, 'sessionStateTargets')->getValue($owner));
                self::assertSame([], new ReflectionProperty(ChatAgent::class, 'sessionStateReplies')->getValue($owner));
            },
        );
    }
}
