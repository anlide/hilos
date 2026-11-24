<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Browser\OutboundModerationBrowserPayload;
use Demo\Chat\Constants\AgentType;
use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Core\Daemon\ChatWorkerManager;
use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Core\Router\DTO\BotMessageSignalData;
use Demo\Chat\Hilos;
use Demo\Chat\Moderation\ChatModerationKindKey;
use Demo\Chat\Moderation\ChatModerationRefusal;
use Demo\Chat\Pages\DTO\Main\MessageActionDTO;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\State\Item\Connection as StateConnection;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Generator;
use Hilos\Auth\AccountOperation\AccountMergeChoice;
use Hilos\Auth\Library\AbstractSessionsLibraryAgent;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\SessionToken;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Definition\ModerationFeature;
use Hilos\Core\Feature\Definition\UploadsFeature;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Group\DTO\GroupLeaveAllSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Core\TruthSource\TruthSourceOperations;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseConnectionConfig;
use Hilos\Database\Database;
use Hilos\Database\DatabaseNames;
use Hilos\Database\Entity\Item\Session as SessionEntity;
use Hilos\Database\Exception\SqlRuntime\LockWaitTimeoutException;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\Upload\DTO\UploadHoldSettleSignalData;
use Hilos\Files\Upload\DTO\UploadPublishSignalData;
use Hilos\Files\Upload\UploadHoldOutcome;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Moderation\ModerationReason;
use Hilos\Runtime\ConnectionRosterReconciler;
use Hilos\Runtime\Exception\RtWriteBackpressureException;
use Hilos\Runtime\Ordinary\RtOrdinaryAdmission;
use Hilos\Runtime\Ordinary\RtOrdinaryQueue;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\RtSyncApplicator;
use Hilos\Runtime\Snapshot\RtSnapshotScope;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\State\Item\HilosModerationCheck;
use Hilos\Runtime\State\Item\HilosUpload;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Item\HilosModerationCheck as ModerationCheck;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketLivenessSignalDTO;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncStreamDTO;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Socket\Worker\WorkerDaemonClient;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use WeakReference;

/** Chat's consumer lifecycle, correlation and sequential approval chain over the common queue. */
final class ChatAgentModerationTest extends IntegrationTestCase
{
    use ChatModerationFixture;

    private const string ACCEPT_KEY = 'message-ak';
    private const int SESSION_WRITER_INDEX = 2;

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
        $this->registerModerationConnection(self::ACCEPT_KEY, $this->userId);
        Hilos::$rt->userStates->actions->ensure($this->userId);
        $this->agent = new ChatAgent();
        $this->drainSignals();
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAcceptKey(null);
        RtOrdinaryAdmission::reset();
        RtStaleness::reset();
        $this->forgetChatChecks();
        Hilos::$rt->hilosUploads->actions->clearAllWithFiles();
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();
        $this->forgetModerationSessions();
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

    public function testStopReleasesTheSuspendedModerationSnapshot(): void
    {
        for ($index = 0; $index < RtWorkBudget::DEFAULT_STEPS + 1; $index++) {
            Hilos::$rt->connections->actions->register('unfinished-recovery-' . $index, null);
        }
        $this->agent->onTick();
        $recovery = new ReflectionProperty(ChatAgent::class, 'moderationRecovery')->getValue($this->agent);
        self::assertInstanceOf(Generator::class, $recovery);
        $weak = WeakReference::create($recovery);
        unset($recovery);

        $this->agent->onStop();

        self::assertNull($weak->get());
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
            $this->registerModerationConnection('restart-ak-1', $user->id);
            $this->registerModerationConnection('restart-ak-2', $user->id);
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

    public function testColdModerationLeavesTheSubmitUnacceptedAndTheChatRunning(): void
    {
        $closing = 'closing-before-snapshot';
        Hilos::$rt->connections->actions->register($closing, $this->userId);
        Hilos::$rt->connections[$closing]->actions->startOutboundModeration('pending at close', []);
        $this->agent->startMessageModeration($closing);
        self::assertNotNull(Hilos::$rt->hilosModerationChecks->find($closing, ChatModerationKindKey::MESSAGE));

        $this->withColdModeration(function () use ($closing): void {
            try {
                $this->submit('kept in the composer');
                self::fail('A cold moderation source must refuse before accepting the message');
            } catch (ValidationException $refusal) {
                self::assertSame(ChatModerationRefusal::TEXT_MESSAGE_UNAVAILABLE, $refusal->getMessage());
            }
            self::assertSame(0.0, Hilos::$rt->userStates[$this->userId]?->lastOutboundSubmittedAt);
            self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
                Hilos::$rt->connections[self::ACCEPT_KEY]?->outboundModerationPhase);

            $this->agent->onStart();
            $this->agent->onTick();
            $this->agent->onSignalConnectionClose(new WebSocketCloseSignalDTO($closing), 'ws', 'close');
            self::assertNull(Hilos::$rt->connections[$closing]);

            SourceInterestRegistry::markReady(SourceChange::KIND_RT, HilosModerationCheck::RT_COLLECTION);
            $this->agent->onTick();
            self::assertNull(Hilos::$rt->hilosModerationChecks->find($closing, ChatModerationKindKey::MESSAGE));
            $this->submit('kept in the composer');
            self::assertSame('kept in the composer', $this->messageCheck()->text);
            self::assertCount(1, Hilos::$rt->hilosModerationChecks);
        });
    }

    #[DataProvider('filePublicationConnections')]
    public function testFilePublicationAfterChatRestartsSettlesOnlyALiveConnection(bool $close): void
    {
        $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'doc', 'notes.pdf', 'application/pdf', 'pdf');
        $this->submit('approved document', ['doc']);
        $check = $this->messageCheck();
        $this->decideCheck($this->agent, $check);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
        self::assertCount(1, $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        $file = $this->keepRegistryFile($this->userId, 'notes.pdf', 'pdf');

        $worker = new ChatWorkerManager(1);
        $agentId = $this->agent->getId();
        $start = new AgentStartDTO($agentId, [self::ACCEPT_KEY]);

        $previousAgentId = ExecutionContext::currentAgentId();
        $harness = 'test-agent';
        foreach (array_keys(ChatAgent::OWNS_DB) as $key) {
            TruthSourceRegistry::unregister($key, $harness);
        }
        foreach ([...array_keys(ChatAgent::OWNS_RT), HilosModerationCheck::RT_COLLECTION] as $key) {
            RtTruthSourceRegistry::unregister($key, $harness);
        }
        try {
            $worker->handleDaemonMessage($start);
            $worker->handleDaemonMessage(new AgentStopDTO($agentId));
            $worker->handleDaemonMessage($start);
            if ($close) {
                $worker->handleDaemonMessage(new DaemonAgentMessageDTO($agentId, new SignalDTO(
                    new SignalSource(SignalSource::WEBSOCKET),
                    new SignalType(SignalTypeConstants::CONNECTION_CLOSE),
                    new SignalName('close'),
                    new WebSocketCloseSignalDTO(self::ACCEPT_KEY),
                )));
                self::assertNull(Hilos::$rt->connections[self::ACCEPT_KEY]);
            }
            $verdict = new ModerationVerdictSignalData(
                self::ACCEPT_KEY, $check->kind, $check->requestId, true, ModerationReason::OK,
            );
            $published = new FilesPublishedSignalData(self::ACCEPT_KEY, ['doc'], [$file->id], null, $check->uploadHoldId);
            foreach ([
                [HilosSignalConstants::HILOS_MODERATION_VERDICT, $verdict],
                [ChatSignalConstants::ATTACHMENTS_PUBLISHED, $published],
                [ChatSignalConstants::ATTACHMENTS_PUBLISHED, $published],
            ] as [$name, $payload]) {
                $worker->handleDaemonMessage(new DaemonAgentMessageDTO($agentId, new SignalDTO(
                    new SignalSource(SignalSource::AGENT),
                    new SignalType(SignalTypeConstants::AGENT_SIGNAL),
                    new SignalName($name),
                    new AgentSignalData($payload),
                )));
            }
            if ($close) {
                $this->assertMessages([]);
                self::assertNull(Hilos::$db->eventAttachments->forFileId($file->id));
            } else {
                $this->assertMessages(['approved document']);
                self::assertNotNull(Hilos::$db->eventAttachments->forFileId($file->id));
            }
            self::assertCount(0, Hilos::$rt->hilosModerationChecks);
        } finally {
            ExecutionContext::setCurrentAgentId($previousAgentId);
            TruthSourceRegistry::unregisterAgent($agentId);
            RtTruthSourceRegistry::unregisterAgent($agentId);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
            foreach (array_keys(ChatAgent::OWNS_DB) as $key) {
                TruthSourceRegistry::register($key, TruthSourceKeys::all(), $harness);
            }
            foreach (array_keys(ChatAgent::OWNS_RT) as $key) {
                RtTruthSourceRegistry::register($key, TruthSourceKeys::all(), $harness);
            }
            $this->claimChatChecks();
        }
    }

    public function testTheColdChatWorkerImportsThePublicationBeforeTakingTheConnectionsClaim(): void
    {
        $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'doc', 'restored.pdf', 'application/pdf', 'document');
        $this->submit('restored document', ['doc']);
        $check = $this->messageCheck();
        $this->decideCheck($this->agent, $check);
        [$publish] = $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH);
        self::assertInstanceOf(UploadPublishSignalData::class, $publish);

        $agentId = $this->agent->getId();
        $harness = 'test-agent';
        $cut = Hilos::$rt;
        $previousAgent = ExecutionContext::currentAgentId();
        $states = new ReflectionProperty(SourceInterestRegistry::class, 'states');
        $consumers = new ReflectionProperty(SourceInterestRegistry::class, 'byConsumer');
        $reactions = new ReflectionProperty(SourceChangeBus::class, 'reactions');
        $previousStates = $states->getValue();
        $previousConsumers = $consumers->getValue();
        $previousReactions = $reactions->getValue();
        $worker = new ChatWorkerManager(1);
        $client = new class extends WorkerDaemonClient {
            /** @var list<WorkerDTO|array<string, mixed>> Production reports to the master */
            public array $sent = [];

            public function send(WorkerDTO|array $data): void
            {
                $this->sent[] = $data;
            }

            public function isConnected(): bool
            {
                return true;
            }
        };
        new ReflectionProperty(WorkerManager::class, 'daemonClient')->setValue($worker, $client);
        foreach (array_keys(ChatAgent::OWNS_DB) as $key) {
            TruthSourceRegistry::unregister($key, $harness);
        }
        foreach ([...array_keys(ChatAgent::OWNS_RT), HilosModerationCheck::RT_COLLECTION] as $key) {
            RtTruthSourceRegistry::unregister($key, $harness);
        }

        try {
            $replica = new ChatRtContext();
            $replica->mountFeatureRuntime([new ModerationFeature(), new UploadsFeature()]);
            $replica->configure();
            $cold = $previousStates;
            unset($cold[SourceChange::KIND_RT][ChatRtContext::connections]);
            $states->setValue(null, $cold);
            SourceInterestRegistry::readsWhatIsDelivered();
            $replica->declareProcessWideReads();
            Hilos::$rt = $replica;
            foreach ([SourceChange::KIND_DB => ChatAgent::readDbCollections(),
                SourceChange::KIND_RT => [HilosModerationCheck::RT_COLLECTION, ...ChatAgent::READS_RT]] as $kind => $keys) {
                foreach ($keys as $key) {
                    SourceInterestRegistry::register($kind, $key, SourceConsumer::feature($key));
                }
            }
            foreach ([SourceChange::KIND_DB, SourceChange::KIND_RT] as $kind) {
                foreach (SourceInterestRegistry::collections($kind) as $key) {
                    if ($key !== ChatRtContext::connections) {
                        SourceInterestRegistry::markReady($kind, $key);
                    }
                }
            }

            $pass = new ReflectionMethod(WorkerManager::class, 'releaseParkedFrames');
            $worker->handleDaemonMessage(new WorkerRegisteredDTO(1));
            $worker->handleDaemonMessage(new AgentStartDTO($agentId, [self::ACCEPT_KEY]));
            $pass->invoke($worker, microtime(true) + AgentConstants::START_DEADLINE_SECONDS + 0.5);
            self::assertFalse(RtTruthSourceRegistry::allowsOperation(ChatRtContext::connections, $agentId, TruthSourceOperation::Add));
            self::assertSame([], RtTruthSourceRegistry::setKeyByCollectionOf($agentId));
            self::assertFalse(SourceInterestRegistry::isReady(SourceChange::KIND_RT, ChatRtContext::connections));

            $reports = array_values(array_filter($client->sent, static fn ($frame): bool => $frame instanceof WorkerSourceInterestDTO));
            self::assertNotEmpty($reports);
            self::assertContains(ChatRtContext::connections, $reports[0]->rtCollections);
            $parts = [];
            $stream = new RtSyncStreamChannel(static function (RtSyncStreamFrame $frame) use (&$parts): void {
                $parts[] = $frame;
            }, static fn (): null => null);
            Hilos::$rt = $cut;
            $stream->sendSnapshot(ChatRtContext::connections, RtSnapshotScope::whole(), announcement: $reports[0]->announcement);
            Hilos::$rt = $replica;
            foreach ($parts as $part) {
                $worker->handleDaemonMessage(new WorkerRtSyncStreamDTO($part));
            }
            $advance = new ReflectionMethod(WorkerManager::class, 'runRtPass');
            $owes = new ReflectionMethod(WorkerManager::class, 'hasRtPassWork');
            for ($steps = 0; $steps < 16 && $owes->invoke($worker); $steps++) {
                $advance->invoke($worker);
            }
            self::assertTrue(SourceInterestRegistry::isReady(SourceChange::KIND_RT, ChatRtContext::connections));
            self::assertFalse(RtTruthSourceRegistry::allowsOperation(ChatRtContext::connections, $agentId, TruthSourceOperation::Add));
            $pass->invoke($worker, microtime(true));
            self::assertTrue(RtTruthSourceRegistry::allowsOperation(ChatRtContext::connections, $agentId, TruthSourceOperation::Add));
            self::assertCount(1, array_filter($client->sent, static fn ($frame): bool =>
                is_array($frame) && $frame[WorkerDTO::TYPE] === WorkerConstants::MESSAGE_AGENT_STARTED));

            $restored = Hilos::$rt->connections[self::ACCEPT_KEY];
            self::assertSame($publish->uploadHoldId, $restored->outboundPublicationHoldId);
            self::assertSame($check->deadlineAt, $restored->outboundPublicationDeadlineAt);
            self::assertSame($this->userId, $restored->outboundPublicationUserId);
            $manager = new ReflectionProperty(WorkerManager::class, 'agentManager')->getValue($worker);
            ExecutionContext::setCurrentAgentId($agentId);
            new ReflectionMethod(ChatAgent::class, 'sweepMessageModeration')->invoke($manager->getAgent($agentId), $check->deadlineAt);
            self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE, $restored->outboundModerationPhase);
            $this->assertMessages([]);
        } finally {
            Hilos::$rt = $cut;
            ExecutionContext::setCurrentAgentId($previousAgent);
            TruthSourceRegistry::unregisterAgent($agentId);
            RtTruthSourceRegistry::unregisterAgent($agentId);
            SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($agentId));
            $states->setValue(null, $previousStates);
            $consumers->setValue(null, $previousConsumers);
            $reactions->setValue(null, $previousReactions);
            SourceInterestRegistry::readsWhatItMounts();
            foreach (array_keys(ChatAgent::OWNS_DB) as $key) {
                TruthSourceRegistry::register($key, TruthSourceKeys::all(), $harness);
            }
            foreach (array_keys(ChatAgent::OWNS_RT) as $key) {
                RtTruthSourceRegistry::register($key, TruthSourceKeys::all(), $harness);
            }
            $this->claimChatChecks();
        }
    }

    /** @return array<string, array{bool}> Whether the connection closes before publication */
    public static function filePublicationConnections(): array
    {
        return ['still open' => [false], 'closed' => [true]];
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

    /** @param list<string> $ids Draft's attachment ids */
    #[DataProvider('erasingDraftAttachments')]
    public function testErasureBeginningDuringModerationReturnsTheDraftWithoutPublishing(array $ids): void
    {
        if ($ids !== []) {
            $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'doc', 'notes.pdf', 'application/pdf', 'pdf');
        }
        $this->submit('draft', $ids);
        $check = $this->messageCheck();
        $now = TimeHelper::nowMs();
        Database::sqlRun(
            'INSERT INTO `hilos_account_deletion` (`user_id`, `requested_at`, `effective_at`, `started_at`) VALUES (?, ?, ?, ?)',
            [$this->userId, $now, $now + TimeConstants::SECONDS_PER_DAY * TimeConstants::MS_PER_SECOND, $now],
        );

        $this->decideCheck($this->agent, $check);

        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertSame('draft', Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationMessage);
        self::assertSame($ids, Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationAttachments);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE_PICTURE));
        $this->assertMessages([]);
        self::assertSame([], $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
    }

    /** @return array<string, array{list<string>}> Drafts with and without a document */
    public static function erasingDraftAttachments(): array
    {
        return ['text' => [[]], 'text with a document' => [['doc']]];
    }

    /**
     * Delivers the allow callback after a committed merge, before sign-out or DB_SYNC consumption.
     * The loser connection and complete ORM mirror stay local and unchanged. This window is shorter
     * than a stand tick; a browser cannot pause frame handling there, and the new merge has no live
     * sender yet. Handler plus DB coverage proves the guard, not cross-node delivery or failover.
     *
     * @param list<string> $ids Draft's attachment ids
     */
    #[DataProvider('erasingDraftAttachments')]
    public function testMergeCommittedDuringModerationReturnsTheDraftWithoutPublishing(array $ids): void
    {
        if ($ids !== []) {
            $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'doc', 'notes.pdf', 'application/pdf', 'pdf');
        }
        $this->submit('draft', $ids);
        $check = $this->messageCheck();
        $survivorId = (int)Hilos::$db->users->actions->createWithName('Survivor')->id;
        $merges = Hilos::$db->userMerges->getObjectCollection();
        self::assertNotNull($merges);
        $merges->preloadAll();
        self::assertTrue($merges->isAllLoaded());
        self::assertArrayNotHasKey($this->userId, Hilos::$db->userMerges);
        Database::sqlRun(
            'INSERT INTO `hilos_user_merge` (`user_id`, `survivor_user_id`, `merged_at`) VALUES (?, ?, ?)',
            [$this->userId, $survivorId, TimeHelper::nowMs()],
        );

        $this->decideCheck($this->agent, $check);

        self::assertSame($this->userId, Hilos::$rt->connections[self::ACCEPT_KEY]->userId);
        self::assertTrue($merges->isAllLoaded());
        self::assertArrayNotHasKey($this->userId, Hilos::$db->userMerges);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertSame('draft', Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationMessage);
        self::assertSame($ids, Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationAttachments);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE_PICTURE));
        $this->assertMessages([]);
        self::assertSame([], Database::sql('SELECT * FROM `event_message` WHERE `author_user_id` = ?', [$this->userId])->rows());
        self::assertSame([], $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
    }

    /**
     * The preparation reserve is not a committed merge and does not close publication.
     *
     * @param list<string> $ids Draft's attachment ids
     */
    #[DataProvider('erasingDraftAttachments')]
    public function testMergePreparationDuringModerationStillAllowsPublication(array $ids): void
    {
        if ($ids !== []) {
            $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'doc', 'notes.pdf', 'application/pdf', 'pdf');
        }
        $this->submit('draft', $ids);
        $check = $this->messageCheck();
        $survivorId = (int)Hilos::$db->users->actions->createWithName('Survivor')->id;
        TruthSourceRegistry::register(HilosDbContext::accountOperations, TruthSourceKeys::all(), 'test-agent');
        $operation = Hilos::$db->accountOperations->actions->recordMerge(
            $survivorId, $this->userId, new AccountMergeChoice(null, null, null, null),
        );
        self::assertSame('preparing', $operation->stage);
        self::assertFalse(Hilos::$db->userMerges->isMergedInDatabase($this->userId));

        $this->decideCheck($this->agent, $check);

        self::assertSame($this->userId, Hilos::$rt->connections[self::ACCEPT_KEY]->userId);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
        if ($ids === []) {
            self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
                Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
            $this->assertMessages(['draft']);
            self::assertSame([], $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        } else {
            self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
                Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
            $this->assertMessages([]);
            self::assertCount(1, $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        }
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

    public function testAdmissionRollsBackTheDraftAndRateLimitWhenTheFirstCheckCannotOpen(): void
    {
        $connection = Hilos::$rt->connections[self::ACCEPT_KEY];
        $submittedAt = $connection->userState->lastOutboundSubmittedAt;
        RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 2));
        RtOrdinaryAdmission::consumeBy(static fn (): bool => false);
        try {
            try {
                $this->submit('refused first opening');
                self::fail('The check cannot take a third ordinary slot');
            } catch (RtWriteBackpressureException) {
                self::assertSame($submittedAt, $connection->userState->lastOutboundSubmittedAt);
                self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE, $connection->outboundModerationPhase);
                self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
            }
        } finally {
            RtOrdinaryAdmission::consumeBy(null);
            RtOrdinaryAdmission::handOnCommitted();
        }
        RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process()));
        $this->submit('accepted on retry');
        self::assertSame('accepted on retry', $this->messageCheck()->text);
    }

    /** @return iterable<string, array{bool}> Transition out of text or the first picture */
    public static function pictureStages(): iterable
    {
        yield 'text to picture' => [false];
        yield 'picture to picture' => [true];
    }

    #[DataProvider('pictureStages')]
    public function testOneOrdinarySlotKeepsThePreviousStageRecoverable(bool $alreadyPicture): void
    {
        $this->attachments();
        $this->submit('pictures', ['first', 'second']);
        $previous = $this->messageCheck();
        if ($alreadyPicture) {
            $this->decideCheck($this->agent, $previous);
            $previous = $this->pictureCheck();
        }
        $this->drainSignals();
        RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 1));
        RtOrdinaryAdmission::consumeBy(static fn (): bool => false);
        try {
            $this->decideCheck($this->agent, $previous);
            $current = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, $previous->kind);
            self::assertSame($previous->requestId, $current?->requestId);
            self::assertSame($previous->deadlineAt, $current?->deadlineAt);
            self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
                Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        } finally {
            RtOrdinaryAdmission::consumeBy(null);
            RtOrdinaryAdmission::handOnCommitted();
        }
        self::assertSame([], $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE));

        $successor = new ChatAgent();
        $sweep = new ReflectionMethod(ChatAgent::class, 'sweepMessageModeration');
        $sweep->invoke($successor, $previous->deadlineAt - 1);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        $sweep->invoke($successor, $previous->deadlineAt);
        $sweep->invoke($successor, $previous->deadlineAt + TimeConstants::MS_PER_SECOND);
        [$release] = $releases = $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE);
        self::assertCount(1, $releases);
        self::assertSame($previous->uploadHoldId, $release->uploadHoldId);
        self::assertSame(UploadHoldOutcome::RELEASED, $release->outcome);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, $previous->kind));
        $this->assertMessages([]);
        Hilos::$rt->userStates[$this->userId]->actions->endSendPause();
        RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process()));
        $this->submit('next draft');
        self::assertSame('next draft', $this->messageCheck()->text);
    }

    public function testTextApprovalCommitsTheMessageAndClearTogetherOnRetry(): void
    {
        $this->submit('once');
        $check = $this->messageCheck();
        $connection = Hilos::$rt->connections[self::ACCEPT_KEY];
        RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 1));
        RtOrdinaryAdmission::consumeBy(static fn (): bool => false);
        $head = RtOrdinaryAdmission::reserve(1);
        $head->seal([[]]);
        $head->settle();
        try {
            $this->decideCheck($this->agent, $check);
            $this->assertMessages([]);
            self::assertSame($check->requestId, $this->messageCheck()->requestId);
            self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING, $connection->outboundModerationPhase);

            RtOrdinaryAdmission::consumeBy(null);
            RtOrdinaryAdmission::handOnCommitted();
            RtOrdinaryAdmission::consumeBy(static fn (): bool => false);
            $this->decideCheck($this->agent, $check);
            self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE, $connection->outboundModerationPhase);
            $this->decideCheck($this->agent, $check);
            $this->assertMessages(['once']);
        } finally {
            RtOrdinaryAdmission::consumeBy(null);
            RtOrdinaryAdmission::handOnCommitted();
        }
        $this->agent->onTick();
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, $check->kind));
        $this->assertMessages(['once']);
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

    public function testAnOldPictureCheckDoesNotBlockPublishingTheNextDocument(): void
    {
        $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'image', 'old.png', 'image/png', 'picture');
        $this->submit('old picture', ['image']);
        $this->decideCheck($this->agent, $this->messageCheck());
        $oldPicture = $this->pictureCheck();
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->failOutboundModeration(
            ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE, ModerationReason::SERVICE_UNAVAILABLE,
        );
        Hilos::$rt->userStates[$this->userId]->actions->endSendPause();
        $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'doc', 'current.pdf', 'application/pdf', 'document');
        $this->submit('current document', ['doc']);
        $this->decideCheck($this->agent, $this->messageCheck());
        $this->decideCheck($this->agent, $oldPicture, false, ModerationReason::SERVICE_UNAVAILABLE);
        RtStaleness::mark(HilosModerationCheck::RT_COLLECTION, [$oldPicture->getId()], microtime(true));

        [$publish] = $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH);
        self::assertInstanceOf(UploadPublishSignalData::class, $publish);
        self::assertNotSame($oldPicture->uploadHoldId, $publish->uploadHoldId);
        $file = $this->keepRegistryFile($this->userId, 'current.pdf', 'document');
        $answer = new FilesPublishedSignalData(self::ACCEPT_KEY, ['doc'], [$file->id], null, $publish->uploadHoldId);
        $this->deliverChatSignal($this->agent, ChatSignalConstants::ATTACHMENTS_PUBLISHED, $answer);
        $this->deliverChatSignal($this->agent, ChatSignalConstants::ATTACHMENTS_PUBLISHED, $answer);
        $this->assertMessages(['current document']);
        self::assertSame([$file->id], Hilos::$db->eventAttachments->allFileIds());
    }

    public function testRecordedHandoverWithAnOwedCheckDeletionExpiresWithoutPublishingAgain(): void
    {
        $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'doc', 'accepted.pdf', 'application/pdf', 'document');
        $this->submit('accepted document', ['doc']);
        $check = $this->messageCheck();

        RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 1));
        RtOrdinaryAdmission::consumeBy(static fn (): bool => false);
        try {
            $this->decideCheck($this->agent, $check);
            self::assertSame($check->requestId, $this->messageCheck()->requestId);
            self::assertSame($check->uploadHoldId, Hilos::$rt->connections[self::ACCEPT_KEY]->outboundPublicationHoldId);
            self::assertSame([], $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        } finally {
            RtOrdinaryAdmission::consumeBy(null);
            RtOrdinaryAdmission::handOnCommitted();
        }

        $successor = new ChatAgent();
        $sweep = new ReflectionMethod(ChatAgent::class, 'sweepMessageModeration');
        $sweep->invoke($successor, $check->deadlineAt - 1);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, $check->kind));
        self::assertSame($check->uploadHoldId, Hilos::$rt->connections[self::ACCEPT_KEY]->outboundPublicationHoldId);
        self::assertSame([], $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE));
        self::assertSame([], $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        $sweep->invoke($successor, $check->deadlineAt);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        $this->assertMessages([]);
    }

    public function testRestoredExpiryWaitsForTheConnectionToBecomeFresh(): void
    {
        $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'doc', 'thaw.pdf', 'application/pdf', 'document');
        $this->submit('thawed document', ['doc']);
        $check = $this->messageCheck();
        $this->decideCheck($this->agent, $check);
        RtStaleness::mark(ChatRtContext::connections, [self::ACCEPT_KEY], microtime(true));

        $successor = new ChatAgent();
        $sweep = new ReflectionMethod(ChatAgent::class, 'sweepMessageModeration');
        $sweep->invoke($successor, $check->deadlineAt);
        self::assertSame($check->deadlineAt, Hilos::$rt->connections[self::ACCEPT_KEY]->outboundPublicationDeadlineAt);
        RtStaleness::clear(ChatRtContext::connections, [self::ACCEPT_KEY]);
        $sweep->invoke($successor, $check->deadlineAt + TimeConstants::MS_PER_SECOND);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        $this->assertMessages([]);
    }

    /** @return iterable<string, array{bool}> Close during a check or after a partial handover */
    public static function closingModerationPhases(): iterable
    {
        yield 'active check' => [false];
        yield 'accepted hold with leftover check' => [true];
    }

    #[DataProvider('closingModerationPhases')]
    public function testCloseStillUnregistersWhenCheckDeletionRefuses(bool $accepted): void
    {
        if ($accepted) {
            $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'doc', 'partial.pdf', 'application/pdf', 'document');
        }
        $this->submit('closed draft', $accepted ? ['doc'] : []);
        $check = $this->messageCheck();
        if ($accepted) {
            Hilos::$rt->connections[self::ACCEPT_KEY]->actions->awaitOutboundPublication(
                $check->uploadHoldId, $check->deadlineAt, $this->userId,
            );
        }
        $this->drainSignals();
        RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 1));
        $head = RtOrdinaryAdmission::reserve(1);
        $head->seal([[]]);
        try {
            try {
                $this->agent->onSignalConnectionClose(new WebSocketCloseSignalDTO(self::ACCEPT_KEY), 'ws', 'close');
                self::fail('The ordinary check deletion must refuse on the full FIFO');
            } catch (RtWriteBackpressureException) {
                self::assertNull(Hilos::$rt->connections[self::ACCEPT_KEY]);
                self::assertSame($check->requestId, $this->messageCheck()->requestId);
            }
        } finally {
            $head->settle();
        }

        if ($accepted) {
            [$release] = $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE);
            self::assertInstanceOf(UploadHoldSettleSignalData::class, $release);
            self::assertSame($check->uploadHoldId, $release->uploadHoldId);
            self::assertSame(UploadHoldOutcome::RELEASED, $release->outcome);
        }
        $this->agent->onTick();
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, $check->kind));
        $this->assertMessages([]);
    }

    public function testOwedRosterRemovalCannotPublishOrBindTheConnectionToAnotherPerson(): void
    {
        $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'doc', 'closed.pdf', 'application/pdf', 'document');
        $this->submit('closed document', ['doc']);
        $this->decideCheck($this->agent, $this->messageCheck());
        [$publish] = $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH);
        self::assertInstanceOf(UploadPublishSignalData::class, $publish);
        $file = $this->keepRegistryFile($this->userId, 'closed.pdf', 'document');
        $other = (int)Hilos::$db->users->actions->createWithName('Different person')->id;
        $replacement = $this->openModerationSession($other);
        Hilos::$rt->userStates->actions->ensure($other);

        Hilos::$rt->connections->actions->register('another-closed-connection', $this->userId);
        $previousAgent = ExecutionContext::currentAgentId();
        ExecutionContext::setCurrentAgentId('test-agent');
        RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process(), maxCleanupOperations: 1));
        $head = RtOrdinaryAdmission::reserve(1);
        $head->seal([[]]);
        try {
            Hilos::$rt->connections['another-closed-connection']->actions->unregister();
            self::assertSame(1, ConnectionRosterReconciler::reconcile([]));
            self::assertSame(1, RtOrdinaryAdmission::owedRemovals());
            self::assertNotNull(Hilos::$rt->connections[self::ACCEPT_KEY]);
            $this->deliverChatSignal($this->agent, HilosSignalConstants::HILOS_SESSION_STATE,
                new SessionStateSignalData($replacement->token, $replacement->id, $other, [self::ACCEPT_KEY]));
            self::assertSame($this->userId, Hilos::$rt->connections[self::ACCEPT_KEY]->userId);
            $this->deliverChatSignal($this->agent, ChatSignalConstants::ATTACHMENTS_PUBLISHED,
                new FilesPublishedSignalData(self::ACCEPT_KEY, ['doc'], [$file->id], null, $publish->uploadHoldId));
            $this->assertMessages([]);
            self::assertSame([], Hilos::$db->eventAttachments->allFileIds());
        } finally {
            $head->settle();
            ExecutionContext::setCurrentAgentId($previousAgent);
        }
        self::assertNull(Hilos::$rt->connections[self::ACCEPT_KEY]);
    }

    /** @return iterable<string, array{bool}> Refusal with the clear still owed, or after repoint and before bind */
    public static function sessionSwitchPressure(): iterable
    {
        yield 'full ordinary and service lanes' => [true];
        yield 'one ordinary slot for the new user state' => [false];
    }

    #[DataProvider('sessionSwitchPressure')]
    public function testCommittedSessionFrameRevokesAllOldPublicationsBeforeOptionalWrites(bool $serviceFull): void
    {
        $session = $this->sessionOf(self::ACCEPT_KEY);
        $sibling = 'second-session-socket';
        Hilos::$rt->connections->actions->register($sibling, $this->userId, $session->token, $session->id);
        Hilos::$rt->connections->actions->register('already-closed-socket', $this->userId);
        $holds = [self::ACCEPT_KEY => 'primary-hold', $sibling => 'sibling-hold'];
        $deadlineAt = TimeHelper::nowMs() + TimeConstants::MS_PER_SECOND * 60;
        $answers = [];
        foreach ($holds as $key => $holdId) {
            $connection = Hilos::$rt->connections[$key];
            $connection->actions->startOutboundModeration('old author', ['doc']);
            $connection->actions->awaitOutboundPublication($holdId, $deadlineAt, $this->userId);
            $file = $this->keepRegistryFile($this->userId, $key . '.pdf', 'published bytes', bound: false);
            $answers[$key] = new FilesPublishedSignalData($key, ['doc'], [$file->id], null, $holdId);
        }
        $other = (int)Hilos::$db->users->actions->createWithName('Replacement author')->id;
        $replacement = $this->openModerationSession($other);
        $this->drainSignals();
        $frame = new SessionStateSignalData($replacement->token, $replacement->id, $other, array_keys($holds));
        $publish = new ReflectionMethod(AbstractSessionsLibraryAgent::class, 'publishSessionState');
        $library = $this->sessionsLibrary();
        $this->underAgent($library, static function () use ($publish, $library, $frame, $session, $serviceFull): void {
            Database::transaction(DatabaseNames::PRIMARY, static function () use ($publish, $library, $frame, $session, $serviceFull): void {
                if (!$serviceFull) {
                    $session->actions->unbindUser();
                }
                $publish->invoke($library, $frame);
            });
        });
        [$committedFrame] = $this->queuedNamed(HilosSignalConstants::HILOS_SESSION_STATE);
        self::assertEquals($frame->acceptKeys, $committedFrame->acceptKeys);

        $this->withSessionWorker(
            $this->agent,
            [...array_keys($holds), 'already-closed-socket'],
            function (ChatWorkerManager $worker, string $agentId) use (
                $holds, $session, $replacement, $answers, $committedFrame, $serviceFull, $sibling, $other,
            ): void {
                $previous = ExecutionContext::currentAgentId();
                $deliver = fn (string $name, AgentSignalData $signal) => $this->sendWorkerSignal($worker, $agentId, $name, $signal);
                $liveness = fn (bool $probablyOffline) => $this->sendWorkerSignal(
                    $worker, $agentId, SignalTypeConstants::CONNECTION_LIVENESS,
                    new WebSocketLivenessSignalDTO(self::ACCEPT_KEY, $probablyOffline),
                    SignalTypeConstants::CONNECTION_LIVENESS, SignalSource::WEBSOCKET,
                );
                ExecutionContext::setCurrentAgentId($agentId);
                try {
                    RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 1,
                        maxCleanupOperations: $serviceFull ? 1 : 2));
                    RtOrdinaryAdmission::consumeBy(static fn (): bool => false);
                    if ($serviceFull) {
                        $head = RtOrdinaryAdmission::reserve(1);
                        $head->seal([[]]);
                        $head->settle();
                        Hilos::$rt->connections['already-closed-socket']->actions->unregister();
                    }
                } finally {
                    ExecutionContext::setCurrentAgentId($previous);
                }
                $deliver(HilosSignalConstants::HILOS_SESSION_STATE, new AgentSignalData($committedFrame));
                self::assertSame($this->userId, Hilos::$rt->connections[self::ACCEPT_KEY]->userId);
                self::assertSame($session->id, Hilos::$rt->connections[self::ACCEPT_KEY]->sessionId);
                self::assertSame($serviceFull ? $holds[self::ACCEPT_KEY] : null,
                    Hilos::$rt->connections[self::ACCEPT_KEY]->outboundPublicationHoldId);
                if ($serviceFull) {
                    $liveness(true);
                    self::assertFalse(Hilos::$rt->connections[self::ACCEPT_KEY]->probablyOffline);
                }
                $released = [];
                foreach ($this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE) as $release) {
                    self::assertSame(UploadHoldOutcome::RELEASED, $release->outcome);
                    self::assertArrayNotHasKey($release->acceptKey, $released);
                    $released[$release->acceptKey] = $release->uploadHoldId;
                }
                self::assertSame($holds, $released);
                if ($serviceFull) {
                    $deliver(ChatSignalConstants::ATTACHMENTS_PUBLISHED, new AgentSignalData($answers[self::ACCEPT_KEY]));
                    $this->assertMessages([]);
                }
                RtOrdinaryAdmission::consumeBy(null);
                RtOrdinaryAdmission::handOnCommitted();
                if (!$serviceFull) {
                    $deliver(ChatSignalConstants::ATTACHMENTS_PUBLISHED, new AgentSignalData($answers[self::ACCEPT_KEY]));
                }
                $deliver(ChatSignalConstants::ATTACHMENTS_PUBLISHED, new AgentSignalData($answers[$sibling]));
                $this->assertMessages([]);
                self::assertSame([], Hilos::$db->eventAttachments->allFileIds());
                $removals = $this->queuedNamed(HilosSignalConstants::HILOS_FILE_REMOVE);
                self::assertSame([$answers[self::ACCEPT_KEY]->fileIds, $answers[$sibling]->fileIds],
                    array_map(static fn ($removal): array => $removal->fileIds, $removals));

                $tick = new ReflectionMethod(WorkerManager::class, 'tickAgents');
                for ($turn = 0; $turn < 6 && Hilos::$rt->connections[$sibling]->userId !== $other; $turn++) {
                    RtOrdinaryAdmission::consumeBy(static fn (): bool => false);
                    $tick->invoke($worker);
                    RtOrdinaryAdmission::consumeBy(null);
                    RtOrdinaryAdmission::handOnCommitted();
                }
                $liveness(true);
                self::assertTrue(Hilos::$rt->connections[self::ACCEPT_KEY]->probablyOffline);
                $liveness(false);
                self::assertFalse(Hilos::$rt->connections[self::ACCEPT_KEY]->probablyOffline);
                self::assertSame($other, Hilos::$rt->connections[self::ACCEPT_KEY]->userId);
                self::assertSame($other, Hilos::$rt->connections[$sibling]->userId);
                ExecutionContext::setCurrentAgentId($agentId);
                RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process()));
                try {
                    $this->submit('new author draft');
                } finally {
                    ExecutionContext::setCurrentAgentId($previous);
                }
                $oldFrame = new SessionStateSignalData($session->token, $session->id, $this->userId, array_keys($holds));
                $deliver(HilosSignalConstants::HILOS_SESSION_STATE, new AgentSignalData($oldFrame));
                $deliver(HilosSignalConstants::HILOS_SESSION_STATE, new AgentSignalData($committedFrame));
                self::assertSame($other, Hilos::$rt->connections[self::ACCEPT_KEY]->userId);
                self::assertSame($other, Hilos::$rt->connections[$sibling]->userId);
                self::assertSame('new author draft', $this->messageCheck()->text);
                self::assertSame([], $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE));
            },
        );
    }

    public function testDelayedTrackedFrameCannotRegisterItsClosedInitiatorAgain(): void
    {
        $session = $this->sessionOf(self::ACCEPT_KEY);
        $sibling = 'live-sibling';
        Hilos::$rt->connections->actions->register($sibling, $this->userId, $session->token, $session->id);
        $library = $this->sessionsLibrary();
        $publish = new ReflectionMethod(AbstractSessionsLibraryAgent::class, 'publishSessionState');
        $frame = new SessionStateSignalData($session->token, $session->id, $this->userId, [self::ACCEPT_KEY],
            requestId: 'withheld-request', action: 'confirm');
        $this->underAgent($library, static fn () => $publish->invoke($library, $frame));
        [$committedFrame] = $this->queuedNamed(HilosSignalConstants::HILOS_SESSION_STATE);

        $this->withSessionWorker($this->agent, [self::ACCEPT_KEY, $sibling],
            function (ChatWorkerManager $worker, string $agentId) use ($committedFrame, $sibling): void {
                $this->sendWorkerSignal($worker, $agentId, SignalTypeConstants::CONNECTION_CLOSE, new WebSocketCloseSignalDTO(self::ACCEPT_KEY),
                    SignalTypeConstants::CONNECTION_CLOSE, SignalSource::WEBSOCKET);
                self::assertFalse(isset(Hilos::$rt->connections[self::ACCEPT_KEY]));
                $this->drainSignals();

                $this->sendWorkerSignal($worker, $agentId, HilosSignalConstants::HILOS_SESSION_STATE, new AgentSignalData($committedFrame));
                self::assertFalse(isset(Hilos::$rt->connections[self::ACCEPT_KEY]));
                $replies = [];
                while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
                    if ($signal->data instanceof WebSocketSignalData) {
                        $replies[] = [$signal->signalName->getName(), $signal->data->targetAcceptKey];
                    }
                }
                self::assertSame([[ChatSignalConstants::HANDSHAKE_RESPONSE, $sibling]], $replies);
            });
    }

    public static function uploadOwnerEnds(): iterable
    {
        yield 'release' => ['release'];
        yield 'expiry' => ['expiry'];
        yield 'close the initiator during the deferred change' => ['close'];
    }

    #[DataProvider('uploadOwnerEnds')]
    public function testServiceOnlyPressurePreservesBothClearsAndTheOrderedReply(string $ending): void
    {
        $session = $this->sessionOf(self::ACCEPT_KEY);
        $sibling = 'session-sibling';
        $cleanupSlot = 'cleanup-slot';
        Hilos::$rt->connections->actions->register($sibling, $this->userId, $session->token, $session->id);
        Hilos::$rt->connections->actions->register($cleanupSlot, null);
        $this->submit('old text');
        $text = $this->messageCheck();
        $this->completeUpload($sibling, $this->userId, 'doc', 'old.pdf', 'application/pdf', 'document');
        $upload = Hilos::$rt->hilosUploads->find($sibling, 'doc');
        $deadlineAt = TimeHelper::nowMs() + TimeConstants::MS_PER_SECOND * 60;
        $holdId = 'old-publication';
        $upload->actions->hold($holdId, $deadlineAt);
        Hilos::$rt->connections[$sibling]->actions->startOutboundModeration('old file', ['doc']);
        Hilos::$rt->connections[$sibling]->actions->awaitOutboundPublication($holdId, $deadlineAt, $this->userId);
        $file = $this->keepRegistryFile($this->userId, 'old.pdf', 'document', bound: false);
        $other = (int)Hilos::$db->users->actions->createWithName('New composer')->id;
        $this->drainSignals();
        $frame = new SessionStateSignalData($session->token, $session->id, $other, [self::ACCEPT_KEY],
            rotationTicket: 'rotation-ticket', requestId: 'new-identity-request', action: 'confirm');
        $library = $this->sessionsLibrary();
        $publish = new ReflectionMethod(AbstractSessionsLibraryAgent::class, 'publishSessionState');
        $this->underAgent($library, static function () use ($library, $publish, $session, $other, $frame): void {
            Database::transaction(DatabaseNames::PRIMARY, static fn () => $session->actions->bindUser($other));
            $publish->invoke($library, $frame);
        });
        [$committedFrame] = $this->queuedNamed(HilosSignalConstants::HILOS_SESSION_STATE);
        $this->withSessionWorker($this->agent, [self::ACCEPT_KEY, $sibling, $cleanupSlot],
            function (ChatWorkerManager $worker, string $agentId) use (
                $cleanupSlot, $sibling, $ending, $committedFrame, $text, $upload, $holdId, $file, $other,
            ): void {
                $previous = ExecutionContext::currentAgentId();
                ExecutionContext::setCurrentAgentId($agentId);
                try {
                    RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 2, maxCleanupOperations: 1));
                    RtOrdinaryAdmission::consumeBy(static fn (): bool => false);
                    Hilos::$rt->connections[$cleanupSlot]->actions->unregister();
                } finally {
                    ExecutionContext::setCurrentAgentId($previous);
                }
                $this->sendWorkerSignal($worker, $agentId, HilosSignalConstants::HILOS_SESSION_STATE, new AgentSignalData($committedFrame));
                [$release] = $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE);
                self::assertSame($holdId, $release->uploadHoldId);
                self::assertSame($sibling, $release->acceptKey);
                if ($ending === 'expiry') {
                    RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(
                        HilosUpload::RT_COLLECTION, $upload->getId(), [HilosUpload::uploadHoldDeadlineAt => TimeHelper::nowMs() - 1],
                    ), skipSelfBroadcastCheck: false);
                    $this->withUploadsOwner(static fn (UploadsAgent $owner) => $owner->onTick());
                } else {
                    $this->applyUploadSettlement($release);
                }
                self::assertNull($upload->uploadHoldDeadlineAt);
                $updates = $this->queuedNamed(SignalConstants::RT_SYNC_UPDATED);
                $update = null;
                foreach ($updates as $emitted) {
                    if ($emitted->collectionKey === HilosUpload::RT_COLLECTION && $emitted->stateId === $upload->getId()) {
                        $update = $emitted;
                    }
                }
                self::assertNotNull($update);
                $this->sendWorkerSignal($worker, $agentId, SignalConstants::RT_SYNC_UPDATED, $update,
                    SignalTypeConstants::RT_SYNC_UPDATED, SignalSource::RT);
                self::assertSame($holdId, Hilos::$rt->connections[$sibling]->outboundPublicationHoldId);
                self::assertSame('old file', Hilos::$rt->connections[$sibling]->outboundModerationMessage);
                $this->sendWorkerSignal($worker, $agentId, HilosSignalConstants::HILOS_MODERATION_VERDICT, new AgentSignalData(
                    new ModerationVerdictSignalData(self::ACCEPT_KEY, $text->kind, $text->requestId, true, ModerationReason::OK),
                ));
                $this->sendWorkerSignal($worker, $agentId, ChatSignalConstants::ATTACHMENTS_PUBLISHED, new AgentSignalData(
                    new FilesPublishedSignalData($sibling, ['doc'], [$file->id], null, $holdId),
                ));
                if ($ending === 'close') {
                    $this->sendWorkerSignal($worker, $agentId, SignalTypeConstants::CONNECTION_CLOSE, new WebSocketCloseSignalDTO(self::ACCEPT_KEY),
                        SignalTypeConstants::CONNECTION_CLOSE, SignalSource::WEBSOCKET);
                }
                $this->assertMessages([]);
                self::assertSame([], Hilos::$db->eventAttachments->allFileIds());
                self::assertCount(1, $this->queuedNamed(HilosSignalConstants::HILOS_FILE_REMOVE));

                RtOrdinaryAdmission::consumeBy(null);
                RtOrdinaryAdmission::handOnCommitted();
                $tick = new ReflectionMethod(WorkerManager::class, 'tickAgents');
                $tick->invoke($worker);
                $tick->invoke($worker);
                $this->assertMessages([]);
                self::assertSame($other, Hilos::$rt->connections[$sibling]->userId);
                self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
                    Hilos::$rt->connections[$sibling]->outboundModerationPhase);
                self::assertNull(Hilos::$rt->connections[$sibling]->outboundPublicationHoldId);
                self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, $text->kind));
                if ($ending === 'close') {
                    self::assertNull(Hilos::$rt->connections[self::ACCEPT_KEY]);
                } else {
                    self::assertSame($other, Hilos::$rt->connections[self::ACCEPT_KEY]->userId);
                    self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
                        Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
                }
                $this->assertSessionEffects($sibling, $ending === 'close');
            });
    }

    private function assertSessionEffects(string $sibling, bool $initiatorClosed): void
    {
        $effects = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $name = $signal->signalName->getName();
            if ($signal->data instanceof GroupLeaveAllSignalData) {
                $effects[] = [$name, ...$signal->data->acceptKeys];
            } elseif ($signal->data instanceof WebSocketSignalData) {
                $effects[] = [$name, $signal->data->targetAcceptKey];
                if ($name === SignalConstants::ACTION_SUCCESS) {
                    self::assertSame('new-identity-request', $signal->data->data->requestId);
                }
            } elseif ($name === SignalConstants::PAGE_ACCESS_REASSESS_USER) {
                $effects[] = [$name];
            }
        }
        $siblingEffects = [
            [SignalTypeConstants::GROUP_LEAVE_ALL, $sibling],
            [ChatSignalConstants::HANDSHAKE_RESPONSE, $sibling],
        ];
        self::assertSame($initiatorClosed ? [
            ...$siblingEffects,
            [SignalConstants::PAGE_ACCESS_REASSESS_USER],
        ] : [
            [SignalTypeConstants::GROUP_LEAVE_ALL, self::ACCEPT_KEY],
            [ChatSignalConstants::HANDSHAKE_RESPONSE, self::ACCEPT_KEY],
            ...$siblingEffects,
            [HilosSignalConstants::HILOS_SESSION_ROTATE, self::ACCEPT_KEY],
            [SignalConstants::PAGE_ACCESS_REASSESS_USER],
            [SignalConstants::ACTION_SUCCESS, self::ACCEPT_KEY],
        ], $effects);
    }

    public function testAnExactReplyCannotUseTheSocketMirrorAfterTheSessionUnbindCommits(): void
    {
        $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'doc', 'old.pdf', 'application/pdf', 'document');
        $this->submit('old author', ['doc']);
        $check = $this->messageCheck();
        $this->decideCheck($this->agent, $check);
        self::assertCount(1, $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        $file = $this->keepRegistryFile($this->userId, 'old.pdf', 'document', bound: false);
        $session = $this->sessionOf(self::ACCEPT_KEY);
        Database::transaction(DatabaseNames::PRIMARY, static fn () => $session->actions->unbindUser());
        self::assertSame($this->userId, Hilos::$rt->connections[self::ACCEPT_KEY]->userId);
        $this->deliverChatSignal($this->agent, ChatSignalConstants::ATTACHMENTS_PUBLISHED,
            new FilesPublishedSignalData(self::ACCEPT_KEY, ['doc'], [$file->id], null, $check->uploadHoldId));
        $this->assertMessages([]);
        self::assertSame([], Hilos::$db->eventAttachments->allFileIds());
        self::assertNull(Hilos::$rt->connections[self::ACCEPT_KEY]->outboundPublicationHoldId);
        [$release] = $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE);
        self::assertSame($check->uploadHoldId, $release->uploadHoldId);
        self::assertSame(UploadHoldOutcome::RELEASED, $release->outcome);
    }

    public function testTextVerdictCannotUseTheSocketMirrorAfterTheSessionUnbindCommits(): void
    {
        $this->submit('old author');
        $check = $this->messageCheck();
        $session = $this->sessionOf(self::ACCEPT_KEY);
        Database::transaction(DatabaseNames::PRIMARY, static fn () => $session->actions->unbindUser());

        self::assertSame($this->userId, Hilos::$rt->connections[self::ACCEPT_KEY]->userId);
        $this->decideCheck($this->agent, $check);
        $this->assertMessages([]);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
    }

    #[DataProvider('moderatedSubmissionKinds')]
    public function testModeratedMessageRefusesAnExclusiveSessionRowLock(bool $attachment): void
    {
        [$check, $reply] = $this->prepareModeratedWrite('locked author', $attachment);
        $session = $this->sessionOf(self::ACCEPT_KEY);
        $configurations = $this->configureSessionWriter();
        try {
            Database::sql('START TRANSACTION', index: self::SESSION_WRITER_INDEX);
            try {
                $locked = Database::sql('SELECT `id` FROM ' . SessionEntity::getSqlTableName() . ' WHERE `id` = ? FOR UPDATE NOWAIT',
                    [$session->id], index: self::SESSION_WRITER_INDEX)->firstRow();
                self::assertSame($session->id, (int)$locked['id']);
                $this->withSessionWorker($this->agent, [self::ACCEPT_KEY], fn (ChatWorkerManager $worker, string $agentId) =>
                    $this->deliverModeratedResult($worker, $agentId, $check, $reply));
                $this->assertMessages([]);
                self::assertSame([], Hilos::$db->eventAttachments->allFileIds());
                self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
                    Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
            } finally {
                Database::sql('ROLLBACK', index: self::SESSION_WRITER_INDEX);
            }
        } finally {
            $this->releaseSessionWriter($configurations);
        }
    }

    #[DataProvider('moderatedSubmissionKinds')]
    public function testMessageCommitHasPriorityOverAConcurrentSessionUnbind(bool $attachment): void
    {
        [$check, $reply] = $this->prepareModeratedWrite('still the author', $attachment);
        $session = $this->sessionOf(self::ACCEPT_KEY);
        $sessionId = $session->id;
        $library = $this->sessionsLibrary();
        $this->underAgent($library, static fn () => $session->actions->rotateTokenAndBindUser(SessionToken::mint(), $session->userId));
        self::assertTrue($session->token !== Hilos::$rt->connections[self::ACCEPT_KEY]->sessionToken);
        $configurations = $this->configureSessionWriter();
        $probe = new class ($sessionId, self::SESSION_WRITER_INDEX) implements SourceMirrorSubscriberInterface {
            public bool $listening = true;
            public bool $busy = false;

            public function __construct(private readonly int $sessionId, private readonly int $writerIndex)
            {
            }

            /**
             * @param SourceChange $change Event insert before the transaction releases its locks
             * @param SourceChangeProvenance $provenance Source of that write
             */
            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if (!$this->listening || $change->sourceKey !== ChatDbContext::eventMessages) {
                    return;
                }
                $this->listening = false;
                try {
                    Database::sql('SET STATEMENT innodb_lock_wait_timeout=0 FOR UPDATE ' . SessionEntity::getSqlTableName()
                        . ' SET `user_id` = NULL WHERE `id` = ?', [$this->sessionId], index: $this->writerIndex);
                } catch (LockWaitTimeoutException) {
                    $this->busy = true;
                }
            }
        };
        SourceChangeBus::subscribe($probe);
        try {
            $this->withSessionWorker($this->agent, [self::ACCEPT_KEY], fn (ChatWorkerManager $worker, string $agentId) =>
                $this->deliverModeratedResult($worker, $agentId, $check, $reply));
            $this->assertMessages(['still the author']);
            self::assertTrue($probe->busy, 'The peer must lose to the live Chat transaction');
            self::assertSame($reply?->fileIds ?? [], Hilos::$db->eventAttachments->allFileIds());
            $this->underAgent($library, static fn () =>
                Database::transaction(DatabaseNames::PRIMARY, static fn () => $session->actions->unbindUser()));
            self::assertTrue(Hilos::$db->sessions->isStoredGuest($sessionId));
        } finally {
            $probe->listening = false;
            $this->releaseSessionWriter($configurations);
        }
    }

    public static function moderatedSubmissionKinds(): iterable
    {
        yield 'text verdict' => [false];
        yield 'published files' => [true];
    }

    #[DataProvider('moderatedSubmissionKinds')]
    public function testDeletedSessionRefusesTheExactModeratedResult(bool $attachment): void
    {
        [$check, $reply] = $this->prepareModeratedWrite('deleted session', $attachment);
        $session = $this->sessionOf(self::ACCEPT_KEY);
        $this->underAgent($this->sessionsLibrary(), static fn () => $session->actions->delete());
        $this->withSessionWorker($this->agent, [self::ACCEPT_KEY], function (ChatWorkerManager $worker, string $agentId) use ($check, $reply): void {
            $this->deliverModeratedResult($worker, $agentId, $check, $reply);
            if ($reply !== null) {
                $removals = $this->queuedNamed(HilosSignalConstants::HILOS_FILE_REMOVE);
                self::assertCount(1, $removals);
                self::assertSame($reply->fileIds, $removals[0]->fileIds);
            }
        });

        $this->assertMessages([]);
        self::assertSame([], Hilos::$db->eventAttachments->allFileIds());
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
    }

    /**
     * @param string $message Draft text
     * @param bool $attachment Whether to advance to the file registry reply
     * @return array{ModerationCheck, ?FilesPublishedSignalData} Exact publisher input
     */
    private function prepareModeratedWrite(string $message, bool $attachment): array
    {
        if ($attachment) {
            $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'doc', 'locked.pdf', 'application/pdf', 'document');
        }
        $this->submit($message, $attachment ? ['doc'] : []);
        $check = $this->messageCheck();
        if (!$attachment) {
            return [$check, null];
        }
        $this->decideCheck($this->agent, $check);
        self::assertCount(1, $this->queuedNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        $file = $this->keepRegistryFile($this->userId, 'locked.pdf', 'document', bound: false);

        return [$check, new FilesPublishedSignalData(self::ACCEPT_KEY, ['doc'], [$file->id], null, $check->uploadHoldId)];
    }

    /**
     * @return array<int, DatabaseConnectionConfig> Existing configurations to restore after the peer write
     */
    private function configureSessionWriter(): array
    {
        $configurationsProperty = new ReflectionProperty(Database::class, 'configurations');
        $configurations = $configurationsProperty->getValue();
        self::assertArrayNotHasKey(self::SESSION_WRITER_INDEX, $configurations);
        $primary = Database::getConnectionConfig(0);
        Database::configure(self::SESSION_WRITER_INDEX, $primary->host, $primary->user, $primary->password,
            $primary->database, $primary->port, $primary->charset, $primary->socket);

        return $configurations;
    }

    /** @param array<int, DatabaseConnectionConfig> $configurations Existing configurations before the peer write */
    private function releaseSessionWriter(array $configurations): void
    {
        try {
            Database::close(self::SESSION_WRITER_INDEX);
        } finally {
            new ReflectionProperty(Database::class, 'configurations')->setValue(null, $configurations);
        }
    }

    private function deliverModeratedResult(
        ChatWorkerManager $worker,
        string $agentId,
        ModerationCheck $check,
        ?FilesPublishedSignalData $reply,
    ): void {
        if ($reply === null) {
            $this->sendWorkerSignal($worker, $agentId, HilosSignalConstants::HILOS_MODERATION_VERDICT, new AgentSignalData(
                new ModerationVerdictSignalData(self::ACCEPT_KEY, $check->kind, $check->requestId, true, ModerationReason::OK),
            ));
        } else {
            $this->sendWorkerSignal($worker, $agentId, ChatSignalConstants::ATTACHMENTS_PUBLISHED, new AgentSignalData($reply));
        }
    }

    public function testExpiryLetsIndependentPhaseAndDeleteRefusalsYieldToFollowingChecks(): void
    {
        // Idle connections precede every live obligation in the imported collection.
        for ($i = 0; $i < 100; $i++) {
            Hilos::$rt->connections->actions->register('idle-' . $i, $this->userId);
        }

        $keys = [self::ACCEPT_KEY, 'second-active-connection'];
        Hilos::$rt->connections->actions->register($keys[1], $this->userId);
        foreach ($keys as $key) {
            Hilos::$rt->connections[$key]->actions->startOutboundModeration('draft', []);
            $this->agent->startMessageModeration($key);
        }
        $now = $this->messageCheck()->deadlineAt + TimeConstants::MS_PER_SECOND;
        $sweep = new ReflectionMethod(ChatAgent::class, 'sweepMessageModeration');
        RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 1));
        $free = 0;
        RtOrdinaryAdmission::consumeBy(static function () use (&$free): bool {
            return $free-- > 0;
        });
        $head = RtOrdinaryAdmission::reserve(1);
        $head->seal([[]]);
        $head->settle();
        try {
            $sweep->invoke($this->agent, $now);
            foreach ($keys as $key) {
                self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
                    Hilos::$rt->connections[$key]->outboundModerationPhase);
            }
            $terminal = [];
            for ($step = 1; $step <= 12; $step++) {
                $free = 1;
                RtOrdinaryAdmission::handOnCommitted();
                $sweep->invoke($this->agent, $now + $step * TimeConstants::MS_PER_SECOND);
                foreach ($keys as $key) {
                    $connection = Hilos::$rt->connections[$key];
                    if ($connection->outboundModerationPhase === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE) {
                        if (!isset($terminal[$key])) {
                            // Give the already recorded terminal phase a distinguishable timestamp.
                            $terminal[$key] = 1000 + count($terminal);
                            RtSyncApplicator::applyUpdated(new RtSyncUpdatedSignalData(
                                ChatRtContext::connections, $key, [StateConnection::outboundModerationUpdatedAt => $terminal[$key]],
                            ), false);
                        }
                        self::assertSame($terminal[$key], $connection->outboundModerationUpdatedAt);
                    }
                }
            }
            self::assertCount(2, $terminal);
            self::assertCount(0, Hilos::$rt->hilosModerationChecks);
        } finally {
            RtOrdinaryAdmission::consumeBy(null);
            RtOrdinaryAdmission::handOnCommitted();
        }
    }

    public function testAnExpiryTickOnlyVisitsAPortionOfKnownPublications(): void
    {
        $deadlineAt = TimeHelper::nowMs() + TimeConstants::MS_PER_SECOND;
        for ($i = 0; $i < 80; $i++) {
            Hilos::$rt->connections->actions->register('idle-before-publication-' . $i, $this->userId);
        }

        $keys = [];
        for ($i = 0; $i < 33; $i++) {
            $key = 'pending-publication-' . $i;
            $keys[] = $key;
            Hilos::$rt->connections->actions->register($key, $this->userId);
            Hilos::$rt->connections[$key]->actions->startOutboundModeration('restored draft', ['document']);
            Hilos::$rt->connections[$key]->actions->awaitOutboundPublication('hold-' . $i, $deadlineAt, $this->userId);
        }
        $this->agent->onStart();
        $recover = new ReflectionMethod(ChatAgent::class, 'recoverModerationWork');
        for ($portion = 0; $portion < 80 + count($keys); $portion++) {
            $recover->invoke($this->agent, $deadlineAt);
        }
        $sweep = new ReflectionMethod(ChatAgent::class, 'sweepMessageModeration');
        $sweep->invoke($this->agent, $deadlineAt);
        $stillChecking = static fn (string $key): bool => Hilos::$rt->connections[$key]->outboundModerationPhase
            === ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING;
        self::assertCount(17, array_filter($keys, $stillChecking));

        $sweep->invoke($this->agent, $deadlineAt);
        self::assertCount(1, array_filter($keys, $stillChecking));
        $sweep->invoke($this->agent, $deadlineAt);
        self::assertSame([], array_filter($keys, $stillChecking));
    }

    public function testRecoveryAfterAStartRefusalKeepsItsTailAcrossMissingRowsAndAReadFailure(): void
    {
        $deadlineAt = TimeHelper::nowMs() + TimeConstants::MS_PER_SECOND;
        $idle = [];
        for ($i = 0; $i < 90; $i++) {
            $idle[] = $key = 'idle-in-recovery-' . $i;
            Hilos::$rt->connections->actions->register($key, $this->userId);
        }
        $keys = ['recovery-tail', 'already-due'];
        foreach ($keys as $key) {
            Hilos::$rt->connections->actions->register($key, $this->userId);
            $connection = Hilos::$rt->connections[$key];
            $connection->actions->startOutboundModeration('restored document', ['doc']);
            $connection->actions->awaitOutboundPublication($key, $deadlineAt, $this->userId);
        }
        $wrongView = Hilos::$rt->userStates[$this->userId];
        Hilos::$rt->userStates->actions->clear();
        $successor = new ChatAgent();
        RtOrdinaryAdmission::bindSourceQueue(new RtOrdinaryQueue(RtRowArena::process(), maxOperations: 1));
        $head = RtOrdinaryAdmission::reserve(1);
        $head->seal([[]]);
        try {
            $successor->onStart();
            self::fail('Startup cannot restore the user state while the FIFO is full');
        } catch (RtWriteBackpressureException) {
            self::assertCount(0, Hilos::$rt->userStates);
        } finally {
            $head->settle();
        }

        new ReflectionMethod(ChatAgent::class, 'watchPublication')->invoke(
            $successor, Hilos::$rt->connections[$keys[1]], $deadlineAt,
        );
        $items = new ReflectionProperty(RtCollection::class, 'items');
        $connections = Hilos::$rt->connections;
        $liveItems = $items->getValue($connections);
        $items->setValue($connections, [self::ACCEPT_KEY => $wrongView] + $liveItems);
        $sweep = new ReflectionMethod(ChatAgent::class, 'sweepMessageModeration');
        try {
            $sweep->invoke($successor, $deadlineAt);
            self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
                $connections[$keys[1]]->outboundModerationPhase);
            self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
                $connections[$keys[0]]->outboundModerationPhase);
        } finally {
            $items->setValue($connections, $liveItems);
        }

        $sweep->invoke($successor, $deadlineAt);
        foreach ($idle as $removed) {
            $connections[$removed]->actions->unregister();
        }
        $sweep->invoke($successor, $deadlineAt);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
            $connections[$keys[0]]->outboundModerationPhase);
        for ($tick = 0; $tick < count($idle); $tick++) {
            $sweep->invoke($successor, $deadlineAt);
        }
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            $connections[$keys[0]]->outboundModerationPhase);
    }

    public function testCloseAndUserSwitchRemoveChecksBeforeTheConnectionChanges(): void
    {
        $this->submit('old person');
        $check = $this->messageCheck();
        $this->agent->onSignalConnectionClose(new WebSocketCloseSignalDTO(self::ACCEPT_KEY), 'ws', 'close');
        self::assertNull(Hilos::$rt->connections[self::ACCEPT_KEY]);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, $check->kind));
        $this->decideCheck($this->agent, $check);
        $this->registerModerationConnection(self::ACCEPT_KEY, $this->userId);
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->startOutboundModeration('old person', []);
        $this->agent->startMessageModeration(self::ACCEPT_KEY);
        $other = (int)Hilos::$db->users->actions->createWithName('Other person')->id;
        $replacement = $this->openModerationSession($other);
        $this->deliverChatSignal($this->agent, HilosSignalConstants::HILOS_SESSION_STATE,
            new SessionStateSignalData($replacement->token, $replacement->id, $other, [self::ACCEPT_KEY]));
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

    public function testMainPageRefusesTheFormerAuthorBeforeOpeningACheck(): void
    {
        $session = $this->sessionOf(self::ACCEPT_KEY);
        $this->underAgent($this->sessionsLibrary(), static fn () => $session->actions->unbindUser());
        $previousSubmit = Hilos::$rt->userStates[$this->userId]->lastOutboundSubmittedAt;
        try {
            $this->submit('old author');
            self::fail('An old connection author must not open a check');
        } catch (ValidationException) {
            self::assertSame($previousSubmit, Hilos::$rt->userStates[$this->userId]->lastOutboundSubmittedAt);
            self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
            self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
                Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
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

    /** @param callable(): void $scenario Worker scenario that controls the moderation snapshot publication */
    private function withColdModeration(callable $scenario): void
    {
        $states = new ReflectionProperty(SourceInterestRegistry::class, 'states');
        $previousStates = $states->getValue();
        $consumer = SourceConsumer::feature('chat-moderation-test');
        $states->setValue(null, []);
        SourceInterestRegistry::readsWhatIsDelivered();
        try {
            foreach (Hilos::$rt->collectionKeys() as $key) {
                SourceInterestRegistry::register(SourceChange::KIND_RT, $key, $consumer);
                if ($key !== HilosModerationCheck::RT_COLLECTION) {
                    SourceInterestRegistry::markReady(SourceChange::KIND_RT, $key);
                }
            }
            foreach (Hilos::$db->getObjectCollectionClasses() as $collection) {
                SourceInterestRegistry::register(SourceChange::KIND_DB, $collection::COLLECTION_KEY, $consumer);
                SourceInterestRegistry::markReady(SourceChange::KIND_DB, $collection::COLLECTION_KEY);
            }
            $scenario();
        } finally {
            SourceInterestRegistry::readsWhatItMounts();
            SourceInterestRegistry::releaseConsumer($consumer);
            $states->setValue(null, $previousStates);
        }
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
