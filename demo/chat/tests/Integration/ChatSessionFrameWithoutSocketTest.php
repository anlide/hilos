<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Page\DTO\PageAccessReassessUserSignalData;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\TruthSource\RtTruthSourceRegistry;

/** Session restatements use the project's socket registry before doing any identity work. */
final class ChatSessionFrameWithoutSocketTest extends IntegrationTestCase
{
    use ChatModerationFixture;

    private const string SESSION_TOKEN = 'aaaabbbbccccddddeeeeffff11112222';
    private const string ACCEPT_KEY = 'session-frame-socket';

    private ChatAgent $agent;
    private int $userId;

    /** Mounts the real chat routes with a person whose session initially has no sockets. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->claimChatChecks();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        $this->userId = (int)Hilos::$db->users->actions->createWithName('Session frame person')->id;
        Hilos::$db->sessions->actions->createAnonymous(self::SESSION_TOKEN)->actions->bindUser($this->userId);
        $this->agent = new ChatAgent();
        $this->drainSignals();
    }

    /** Removes the case's connection, transient user state and person. */
    protected function tearDown(): void
    {
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->sessions->findByToken(self::SESSION_TOKEN)->actions->delete();
        Hilos::$db->users[$this->userId]->actions->delete();
        parent::tearDown();
    }

    public function testASessionWithoutSocketsSendsNoIdentityOrPageReassessment(): void
    {
        $this->deliverChatSignal($this->agent, HilosSignalConstants::HILOS_SESSION_STATE,
            new SessionStateSignalData(self::SESSION_TOKEN, null, $this->userId, []));

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
            new SessionStateSignalData(self::SESSION_TOKEN, null, $this->userId, []));

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
}
