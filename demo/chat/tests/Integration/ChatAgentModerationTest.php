<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Browser\OutboundModerationBrowserPayload;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Moderation\ChatModerationKindKey;
use Demo\Chat\Pages\DTO\Main\MessageActionDTO;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Item\HilosModerationCheck;
use Hilos\Runtime\State\Item\HilosUpload;
use Hilos\Runtime\View\Item\HilosModerationCheck as ModerationCheck;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;

/** Chat's consumer lifecycle, correlation and sequential approval chain over the common queue. */
final class ChatAgentModerationTest extends IntegrationTestCase
{
    use ChatModerationFixture;

    private const string ACCEPT_KEY = 'message-ak';

    private ChatAgent $agent;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->claimChatChecks();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        RtTruthSourceRegistry::register(HilosUpload::RT_COLLECTION, TruthSourceKeys::all(), 'test-agent');
        $this->forgetChatChecks();
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();
        $this->userId = (int)Hilos::$db->users->actions->createWithName('Message author')->id;
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $this->userId);
        Hilos::$rt->userStates->actions->ensure($this->userId);
        $this->agent = new ChatAgent();
        $this->drainSignals();
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAcceptKey(null);
        RtStaleness::reset();
        $this->forgetChatChecks();
        Hilos::$rt->hilosUploads->actions->clearAllWithFiles();
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();
        parent::tearDown();
    }

    /**
     * What a stopping agent may empty, and what is not its to empty (HIL-664).
     *
     * The user states are the agent's own, and a stop is the end of them; the uploads are the
     * framework uploads agent's (HIL-144). The connections are not: they say who is on the wire, which is the truth of the node
     * holding the sockets, and a stop closes no socket. Emptying them here is what made a freeze
     * report an empty hall to the rest of the node while every tab was still connected - and the
     * restore that followed photographed nobody. The row below therefore SURVIVES the stop; the
     * sockets that died meanwhile are struck when the agent comes back, against the roster the
     * master hands its start (ConnectionRosterReconciler).
     */
    public function testOnStopClearsWhatIsItsOwnAndLeavesTheConnections(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            Hilos::$rt->connections->actions->register('stop-ak', $user->id);
            Hilos::$rt->userStates->actions->ensure($user->id)->actions->recordOutboundSubmission();
            Hilos::$rt->connections['stop-ak']?->actions->startOutboundModeration(
                'pending moderation',
                [],
            );

            $agent = new ChatAgent();
            $agent->onStop();

            $this->assertSame(1, count(Hilos::$rt->connections));
            $this->assertSame(0, count(Hilos::$rt->userStates));
            $this->assertEventTypeExists(ChatEventType::CHAT_STOPPED);
        } finally {
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    /**
     * A replacement chat agent must restore the user state of a tab that sends no new handshake.
     *
     */
    public function testOnStartRestoresUserStateForOpenConnections(): void
    {
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        try {
            $user = Hilos::$db->users->actions->createWithName('User');
            Hilos::$rt->connections->actions->register('restart-ak-1', $user->id);
            Hilos::$rt->connections->actions->register('restart-ak-2', $user->id);
            Hilos::$rt->connections->actions->register('restart-anonymous-ak', null);
            Hilos::$rt->userStates->actions->ensure($user->id)->actions->recordOutboundSubmission();

            new ChatAgent()->onStop();

            $this->assertCount(3, Hilos::$rt->connections);
            $this->assertCount(0, Hilos::$rt->userStates);
            $this->assertNull(Hilos::$rt->connections['restart-ak-1']?->userState);

            $agent = new ChatAgent();
            $agent->onStart();

            $this->assertCount(1, Hilos::$rt->userStates);
            $this->assertSame($user->id, Hilos::$rt->connections['restart-ak-2']?->userState?->userId);
            $this->assertNull(Hilos::$rt->connections['restart-anonymous-ak']?->userState);

            ExecutionContext::setCurrentAcceptKey('restart-ak-1');
            new MainPage($agent)->onAction(
                'restart-ak-1',
                ChatSignalConstants::MESSAGE,
                new MessageActionDTO('message after restart'),
            );

            $this->assertSame(
                ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
                Hilos::$rt->connections['restart-ak-1']?->outboundModerationPhase,
            );
            $this->assertGreaterThan(0, Hilos::$rt->connections['restart-ak-1']?->userState?->lastOutboundSubmittedAt);
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
            Hilos::$rt->connections->actions->clear();
            Hilos::$rt->userStates->actions->clear();
            Hilos::$db->events->actions->deleteAll();
        }
    }

    public function testChatOwnsOnlyItsCheckSetAndReadsUploadsAndRegistry(): void
    {
        self::assertSame([HilosModerationCheck::RT_COLLECTION => TruthSourceOperation::BY_KIND], ChatAgent::OWNS_RT_SET);
        self::assertSame(AgentType::CHAT, $this->agent->ownedRtSetKey(HilosModerationCheck::RT_COLLECTION));
        self::assertArrayNotHasKey(HilosModerationCheck::RT_COLLECTION, ChatAgent::OWNS_RT);
        self::assertContains(HilosUpload::RT_COLLECTION, ChatAgent::READS_RT);
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_MODERATION_VERDICT, ChatAgent::AGENT_SIGNALS);
    }

    public function testMainPageStartsTheCommonCheckAfterRecordingTheSubmit(): void
    {
        $this->submit('accepted text');
        $check = $this->messageCheck();
        self::assertSame('accepted text', $check->text);
        self::assertSame([], $check->clientUploadIds);
        self::assertSame($this->userId, $check->userId);
        self::assertSame(AgentType::CHAT, $check->verdictTo);
        self::assertGreaterThan(0, Hilos::$rt->connections[self::ACCEPT_KEY]->userState->lastOutboundSubmittedAt);
        $this->decideCheck($this->agent, $check);
        $this->assertMessages(['accepted text']);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        $this->decideCheck($this->agent, $check);
        $this->assertMessages(['accepted text']);
    }

    public function testTextThenPicturesUseAttachmentOrderFreshIdsAndOneDeadline(): void
    {
        $this->attachments();
        $this->submit('hello', ['doc', 'first', 'second', 'third']);
        $text = $this->messageCheck();
        self::assertSame(['doc', 'first', 'second', 'third'], $text->clientUploadIds);
        $this->decideCheck($this->agent, $text);
        $first = $this->pictureCheck();
        self::assertSame('first', $first->clientUploadId);
        self::assertTrue($first->imageCopy);
        self::assertNull($first->text);
        self::assertSame($text->deadlineAt, $first->deadlineAt);
        self::assertNotSame($text->requestId, $first->requestId);
        $this->assertMessages([]);
        $this->decideCheck($this->agent, $first);
        $second = $this->pictureCheck();
        self::assertSame('second', $second->clientUploadId);
        self::assertNotSame($first->requestId, $second->requestId);
        self::assertSame($text->deadlineAt, $second->deadlineAt);
        $this->decideCheck($this->agent, $second, false, 'nudity');
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE_PICTURE));
        self::assertSame('second', Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPicture);
        self::assertSame('Message rejected: attachment 3 (second.png) looks like nudity or sexual content.',
            OutboundModerationBrowserPayload::forConnection(Hilos::$rt->connections[self::ACCEPT_KEY])['reason']);
        self::assertSame(['doc', 'first', 'second', 'third'], Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationAttachments);
        $this->assertMessages([]);
        self::assertSame([], $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
    }

    public function testEveryApprovalIsRequiredBeforeFilesArePublished(): void
    {
        $this->attachments();
        $this->submit('', ['doc', 'first', 'second']);
        self::assertSame('', $this->messageCheck()->text);
        $this->decideCheck($this->agent, $this->messageCheck());
        $this->decideCheck($this->agent, $this->pictureCheck());
        self::assertSame([], $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        $this->decideCheck($this->agent, $this->pictureCheck());
        $requests = $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH);
        self::assertCount(1, $requests);
        self::assertSame(['doc', 'first', 'second'], $requests[0]->clientUploadIds);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        $this->assertMessages([]);
    }

    public function testBareDocumentsAreCheckedAsMetadataAndNeverOpenAnImageCheck(): void
    {
        $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'doc', 'notes.pdf', 'application/pdf', 'pdf');
        $this->submit('', ['doc']);
        $this->decideCheck($this->agent, $this->messageCheck());
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE_PICTURE));
        self::assertCount(1, $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
    }

    public function testTextRefusalStopsBeforeAnyImageAndKeepsTheOriginalLockout(): void
    {
        $this->attachments();
        $this->submit('spam', ['first', 'second']);
        $submittedAt = Hilos::$rt->connections[self::ACCEPT_KEY]->userState->lastOutboundSubmittedAt;
        $this->decideCheck($this->agent, $this->messageCheck(), false, 'spam');
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE_PICTURE));
        self::assertSame($submittedAt, Hilos::$rt->connections[self::ACCEPT_KEY]->userState->lastOutboundSubmittedAt);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_REJECTED,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        $this->assertMessages([]);
        self::assertSame([], $this->queuedNamed(SignalConstants::ACTION_ERROR));
    }

    #[DataProvider('invalidVerdicts')]
    public function testInvalidOrUnavailableVerdictsReturnTheDraftWithoutPublishing(bool $allow, string $reason): void
    {
        $this->submit('draft');
        $this->decideCheck($this->agent, $this->messageCheck(), $allow, $reason);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertSame('draft', Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationMessage);
        self::assertNull(Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPicture);
        $this->assertMessages([]);
        self::assertSame([], $this->queuedNamed(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
    }

    public static function invalidVerdicts(): array
    {
        return [[false, 'service_unavailable'], [false, ''], [false, 'policy'], [true, 'spam'], [false, 'ok'], [false, 'raw provider text']];
    }

    public function testUnknownRequestOldAttemptAndChangedTextOrIdsCannotApproveTheDraft(): void
    {
        $this->submit('first draft');
        $old = $this->messageCheck();
        $this->deliverChatSignal($this->agent, HilosSignalConstants::HILOS_MODERATION_VERDICT,
            new ModerationVerdictSignalData(self::ACCEPT_KEY, $old->kind, 'foreign-request', true, 'ok'));
        self::assertSame($old->requestId, $this->messageCheck()->requestId);
        $this->decideCheck($this->agent, $old, false, 'service_unavailable');
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->startOutboundModeration('first draft', []);
        $this->agent->startMessageModeration(self::ACCEPT_KEY);
        $current = $this->messageCheck();
        self::assertNotSame($old->requestId, $current->requestId);
        $this->decideCheck($this->agent, $old);
        self::assertSame($current->requestId, $this->messageCheck()->requestId);
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->startOutboundModeration('changed text', []);
        $this->decideCheck($this->agent, $current);
        $this->assertMessages([]);
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->startOutboundModeration('first draft', ['changed-id']);
        $this->decideCheck($this->agent, $current);
        $this->assertMessages([]);
        self::assertSame($current->requestId, $this->messageCheck()->requestId);
    }

    public function testAnIncompleteUploadOrRefusedNextOpeningEndsTheWait(): void
    {
        $this->attachments();
        $this->submit('draft', ['first', 'second']);
        $text = $this->messageCheck();
        // An existing same-kind row makes the next open refuse; no partial chain may publish.
        Hilos::$rt->hilosModerationChecks->actions->open(self::ACCEPT_KEY, $this->userId, ChatModerationKindKey::MESSAGE_PICTURE,
            clientUploadId: 'second', imageCopy: true);
        $this->decideCheck($this->agent, $text);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE_PICTURE));
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->startOutboundModeration('draft', ['first']);
        $this->agent->startMessageModeration(self::ACCEPT_KEY);
        $text = $this->messageCheck();
        Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->actions->fail('test_failure', 'Cannot finish upload');
        $this->decideCheck($this->agent, $text);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        $this->assertMessages([]);
        self::assertSame([], $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
    }

    public function testAnImageServiceFailureDoesNotNameThePictureOrNotify(): void
    {
        $this->attachments();
        $this->submit('draft', ['first']);
        $this->decideCheck($this->agent, $this->messageCheck());
        $this->decideCheck($this->agent, $this->pictureCheck(), false, 'service_unavailable');
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertNull(Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPicture);
        self::assertSame([], $this->queuedNamed(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
        $this->assertMessages([]);
    }

    public function testAStartFailureReturnsTheAcceptedDraftWithoutLeavingACheck(): void
    {
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->startOutboundModeration('draft', ['missing']);
        $this->agent->startMessageModeration(self::ACCEPT_KEY);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertSame('draft', Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationMessage);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
        $this->assertMessages([]);
    }

    public function testMissingAndStaleUploadsNeverSkipTheImageStage(): void
    {
        $this->attachments();
        $this->submit('draft', ['first']);
        $check = $this->messageCheck();
        Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->actions->forgetWithFile();
        $this->decideCheck($this->agent, $check);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, $check->kind));
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->startOutboundModeration('draft', ['second']);
        $this->agent->startMessageModeration(self::ACCEPT_KEY);
        RtStaleness::mark(HilosUpload::RT_COLLECTION, [HilosUpload::keyFor(self::ACCEPT_KEY, 'second')], microtime(true));
        $this->decideCheck($this->agent, $this->messageCheck());
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        $this->assertMessages([]);
    }

    public function testStaleChecksAndConnectionsRemainUnknownUntilFresh(): void
    {
        $this->submit('draft');
        $check = $this->messageCheck();
        RtStaleness::mark(HilosModerationCheck::RT_COLLECTION, [$check->getId()], microtime(true));
        $this->decideCheck($this->agent, $check);
        $this->agent->onTick();
        self::assertSame($check->requestId, $this->messageCheck()->requestId);
        RtStaleness::reset();
        RtStaleness::mark(ChatRtContext::connections, [self::ACCEPT_KEY], microtime(true));
        $this->decideCheck($this->agent, $check);
        new ChatAgent()->onTick();
        self::assertSame($check->requestId, $this->messageCheck()->requestId);
        $this->assertMessages([]);
        RtStaleness::reset();
        $this->decideCheck($this->agent, $check);
        $this->assertMessages(['draft']);
    }

    #[DataProvider('deadlinePaths')]
    public function testFixedDeadlineEndsTheWaitEvenWhenTheUploadsSourceIsStale(bool $byVerdict): void
    {
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->startOutboundModeration('expired draft', []);
        $check = Hilos::$rt->hilosModerationChecks->actions->open(self::ACCEPT_KEY, $this->userId, ChatModerationKindKey::MESSAGE,
            text: 'expired draft', deadlineAt: TimeHelper::nowMs() + 20);
        usleep(30000);
        RtStaleness::mark(HilosUpload::RT_COLLECTION, ['unreachable'], microtime(true));
        if ($byVerdict) {
            $this->decideCheck($this->agent, $check);
        } else {
            $this->agent->onTick();
        }
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, $check->kind));
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        $this->assertMessages([]);
    }

    public static function deadlinePaths(): array
    {
        return [[true], [false]];
    }

    public function testCloseAndUserSwitchRemoveChecksBeforeTheConnectionChanges(): void
    {
        $this->submit('old person');
        $check = $this->messageCheck();
        $this->agent->onSignalConnectionClose(new WebSocketCloseSignalDTO(self::ACCEPT_KEY), 'ws', 'close');
        self::assertNull(Hilos::$rt->connections[self::ACCEPT_KEY]);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, $check->kind));
        $this->decideCheck($this->agent, $check);
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $this->userId);
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->startOutboundModeration('old person', []);
        $this->agent->startMessageModeration(self::ACCEPT_KEY);
        $other = (int)Hilos::$db->users->actions->createWithName('Other person')->id;
        $this->deliverChatSignal($this->agent, HilosSignalConstants::HILOS_SESSION_STATE,
            new SessionStateSignalData('session-token', null, $other, [self::ACCEPT_KEY]));
        self::assertSame($other, Hilos::$rt->connections[self::ACCEPT_KEY]->userId);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, $check->kind));
        $this->assertMessages([]);
    }

    public function testSweepLeavesAnotherRecipientsCheckEvenWithoutItsConnection(): void
    {
        RtTruthSourceRegistry::register(HilosModerationCheck::RT_COLLECTION,
            TruthSourceKeys::set(HilosAgentType::HILOS_USERS_LIBRARY), 'name-fixture',
            TruthSourceOperations::of(TruthSourceOperation::Add, TruthSourceOperation::Remove));
        $previousAgent = ExecutionContext::currentAgentId();
        ExecutionContext::setCurrentAgentId('name-fixture');
        try {
            $check = Hilos::$rt->hilosModerationChecks->actions->open('other-connection', $this->userId,
                ModerationKindKey::PROFILE_NAME, text: 'A name');
            ExecutionContext::setCurrentAgentId($previousAgent);
            $this->agent->onTick();
            self::assertSame($check->requestId,
                Hilos::$rt->hilosModerationChecks->find('other-connection', ModerationKindKey::PROFILE_NAME)->requestId);
        } finally {
            ExecutionContext::setCurrentAgentId('name-fixture');
            Hilos::$rt->hilosModerationChecks->find('other-connection', ModerationKindKey::PROFILE_NAME)?->actions->forget();
            RtTruthSourceRegistry::unregisterAgent('name-fixture');
            ExecutionContext::setCurrentAgentId($previousAgent);
        }
    }

    public function testSweepRemovesMissingAndChangedConnections(): void
    {
        $this->submit('draft');
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->bindUser(null);
        $this->agent->onTick();
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->bindUser($this->userId);
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->startOutboundModeration('draft', []);
        $this->agent->startMessageModeration(self::ACCEPT_KEY);
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->unregister();
        new ChatAgent()->onTick();
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
        $this->assertMessages([]);
    }

    public function testRestartPreservesTheCurrentCheckAndContinuesFromItsImageId(): void
    {
        $this->attachments();
        $this->submit('survives restart', ['first', 'second']);
        $this->decideCheck($this->agent, $this->messageCheck());
        $first = $this->pictureCheck();
        $this->agent->onStop();
        self::assertCount(1, Hilos::$rt->connections);
        self::assertCount(0, Hilos::$rt->userStates);
        self::assertSame($first->requestId, $this->pictureCheck()->requestId);
        $replacement = new ChatAgent();
        $replacement->onStart();
        self::assertCount(1, Hilos::$rt->userStates);
        $this->decideCheck($replacement, $first);
        self::assertSame('second', $this->pictureCheck()->clientUploadId);
        self::assertSame($first->deadlineAt, $this->pictureCheck()->deadlineAt);
        $this->assertMessages([]);
    }

    public function testMainPageRefusesInvalidAndRateLimitedSubmissionsBeforeOpeningChecks(): void
    {
        try {
            $this->submit('missing file', ['missing']);
            self::fail('Missing upload must refuse admission');
        } catch (ValidationException) {
            self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
        }
        Hilos::$rt->userStates[$this->userId]->actions->recordOutboundSubmission();
        try {
            $this->submit('too soon');
            self::fail('The shared rate limit must refuse admission');
        } catch (ValidationException) {
            self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
        }
    }

    public function testBotMessageSignalStillPublishes(): void
    {
        $bot = Hilos::$db->bots->actions->create('Signal Bot', active: true);
        $this->deliverChatSignal($this->agent, ChatSignalConstants::BOT_MESSAGE, new BotMessageSignalData((int)$bot->id, 'bot says hi'));
        $this->assertMessages(['bot says hi']);
        foreach (Hilos::$db->events as $event) {
            if ($event->type === ChatEventType::MESSAGE_SENT->value) {
                self::assertSame((int)$bot->id, $event->eventMessage->authorBotId);
            }
        }
    }

    private function assertEventTypeExists(ChatEventType $type): void
    {
        foreach (Hilos::$db->events as $event) {
            if ($event->type === $type->value) {
                return;
            }
        }

        $this->fail("Expected event type {$type->value} to exist.");
    }

    private function attachments(): void
    {
        $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'doc', 'notes.pdf', 'application/pdf', 'pdf');
        $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'first', 'first.png', 'application/octet-stream', 'png-1');
        Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->actions->inspect(hash('sha256', 'png-1'), 'IMAGE/PNG; charset=binary');
        $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'second', 'second.png', 'image/png', 'png-2');
        $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'third', 'third.gif', 'image/gif', 'gif');
    }

    private function submit(string $text, array $ids = []): void
    {
        ExecutionContext::setCurrentAcceptKey(self::ACCEPT_KEY);
        try {
            new MainPage($this->agent)->onAction(self::ACCEPT_KEY, ChatSignalConstants::MESSAGE, new MessageActionDTO($text, $ids));
            $this->acknowledgeChatUploads($this->messageCheck());
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
        }
    }

    private function messageCheck(): ModerationCheck
    {
        $check = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE);
        self::assertNotNull($check);
        return $check;
    }

    private function pictureCheck(): ModerationCheck
    {
        $check = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE_PICTURE);
        self::assertNotNull($check);
        return $check;
    }

    private function assertMessages(array $expected): void
    {
        $messages = [];
        foreach (Hilos::$db->events as $event) {
            if ($event->type === ChatEventType::MESSAGE_SENT->value) {
                $messages[] = $event->eventMessage->message;
            }
        }
        self::assertSame($expected, $messages);
    }

    private function queuedNamed(string $name): array
    {
        $payloads = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === $name) {
                $payloads[] = $signal->data instanceof AgentSignalData ? $signal->data->data : $signal->data;
            }
        }
        return $payloads;
    }
}
