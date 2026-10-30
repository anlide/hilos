<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Moderation\ChatModerationKindKey;
use Demo\Chat\Pages\DTO\Main\MessageActionDTO;
use Demo\Chat\Pages\MainPage;
use Hilos\Runtime\State\Item\HilosUpload;
use Hilos\Runtime\RtStaleness;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Demo\Chat\Database\View\Item\EventAttachment;
use Demo\Chat\Files\ChatAttachmentUploadTarget;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Database;
use Hilos\Database\Exception\Transaction\AnnouncementFailedException;
use Hilos\Database\Object\Item\File as ObjectFile;
use Hilos\Files\DTO\FileBindSignalData;
use Hilos\Files\DTO\FileRemoveSignalData;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\HilosFiles;
use Hilos\Files\Upload\DTO\UploadPublishSignalData;
use Hilos\HilosException;
use Hilos\Moderation\ModerationReason;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Integration coverage for a message carrying files: publication on approval, the answer of the
 * files registry, and what the feed reads of an attachment (HIL-144).
 *
 * The uploads themselves are the framework's and are not driven here: the chat agent names them by
 * their client ids, asks the registry to publish them once moderation approves, and writes the
 * message with links to the published rows when the answer comes back.
 */
final class EventAttachmentsTest extends IntegrationTestCase
{
    use ChatModerationFixture;

    private const string TEST_AGENT_ID = 'test-agent';

    private const string ACCEPT_KEY = 'attach-ak';

    private const string TEXT = 'with files';

    private const string FAILURE_TRIGGER = 'chat_publication_write_failure';

    /** @var list<int> Registry rows the case created, removed once it ends */
    private array $fileIds = [];

    private int $userId;

    /**
     * Gives the case a sender whose message with two files is under moderation.
     *
     * @throws HilosException When the runtime rows cannot be written
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->claimChatChecks();
        Hilos::initSignalRouter(new ChatSignalRouter());
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        $this->userId = (int)Hilos::$db->users->actions->createWithName('Attaching')->id;
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $this->userId);
        Hilos::$rt->userStates->actions->ensure($this->userId)->actions->recordOutboundSubmission();
        $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'u1', 'first.txt', 'text/plain', 'first bytes');
        $this->completeUpload(self::ACCEPT_KEY, $this->userId, 'u2', 'second.txt', 'text/plain', 'second bytes');
        Hilos::$rt->connections[self::ACCEPT_KEY]?->actions->startOutboundModeration(self::TEXT, ['u1', 'u2']);
        new ChatAgent()->startMessageModeration(self::ACCEPT_KEY);
        $check = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE);
        self::assertNotNull($check);
        $this->acknowledgeChatUploads($check);
        $this->drainSignals();
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAcceptKey(null);
        RtStaleness::reset();
        Database::sqlRun('DROP TRIGGER IF EXISTS `' . self::FAILURE_TRIGGER . '`');
        $this->forgetChatChecks();
        Hilos::$rt->hilosUploads->actions->clearAllWithFiles();
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();
        foreach ($this->fileIds as $fileId) {
            if (isset(Hilos::$db->files[$fileId])) {
                $storedName = Hilos::$db->files[$fileId]->storedName;
                Hilos::$db->files[$fileId]->actions->delete();
                Hilos::$fs?->files[$storedName]->unlink();
            }
        }
        parent::tearDown();
    }

    public function testApprovalAsksTheRegistryToPublishTheMessagesUploads(): void
    {
        $this->approveMessage();

        $publish = $this->onlyQueued(HilosSignalConstants::HILOS_UPLOAD_PUBLISH);
        self::assertInstanceOf(UploadPublishSignalData::class, $publish);
        self::assertSame([
            UploadPublishSignalData::acceptKey => self::ACCEPT_KEY,
            UploadPublishSignalData::target => ChatAttachmentUploadTarget::NAME,
            UploadPublishSignalData::clientUploadIds => ['u1', 'u2'],
            UploadPublishSignalData::replySignal => ChatSignalConstants::ATTACHMENTS_PUBLISHED,
        ], $publish->toArray());
        self::assertSame(
            ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
            Hilos::$rt->connections[self::ACCEPT_KEY]?->outboundModerationPhase,
            'The message waits for the registry before it is written',
        );
        self::assertSame(0, $this->messagesWritten());
    }

    public function testThePublishedFilesRideTheMessageInOrderAndAreBound(): void
    {
        $first = $this->registryFile('first.txt');
        $second = $this->registryFile('second.txt');

        $this->deliverPublished(new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$second, $first], null));

        self::assertSame(
            ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
            Hilos::$rt->connections[self::ACCEPT_KEY]?->outboundModerationPhase,
        );
        self::assertSame([], Hilos::$rt->connections[self::ACCEPT_KEY]?->outboundModerationAttachments);
        $message = null;
        foreach (Hilos::$db->eventMessages->byAuthor($this->userId) as $candidate) {
            $message = $candidate->message === self::TEXT ? $candidate : $message;
        }
        self::assertNotNull($message, 'The approved message is written');
        $linked = [];
        foreach ($message->attachments as $attachment) {
            $linked[] = $attachment->fileId;
        }
        self::assertSame([$second, $first], $linked);
        self::assertSame(1, $this->messagesWritten());
        $bind = $this->onlyQueued(HilosSignalConstants::HILOS_FILE_BIND);
        self::assertInstanceOf(FileBindSignalData::class, $bind);
        self::assertSame([$second, $first], $bind->fileIds);
    }

    public function testARefusedPublicationReturnsTheMessageWithTheRegistrysSentence(): void
    {
        $this->deliverPublished(
            FilesPublishedSignalData::refused(self::ACCEPT_KEY, ['u1', 'u2'], 'This file is gone; upload it again'),
        );

        self::assertSame(
            ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]?->outboundModerationPhase,
        );
        self::assertSame('This file is gone; upload it again', Hilos::$rt->connections[self::ACCEPT_KEY]?->outboundModerationReason);
        self::assertSame(['u1', 'u2'], Hilos::$rt->connections[self::ACCEPT_KEY]?->outboundModerationAttachments);
        self::assertSame(0, $this->messagesWritten());
    }

    /**
     * Files published for a message nobody waits for are removed at once, not left for a day.
     *
     * @throws HilosException When the case cannot be arranged
     */
    public function testAnAnswerNobodyWaitsForRemovesTheFilesItPublished(): void
    {
        $orphan = $this->registryFile('orphan.txt');
        Hilos::$rt->connections[self::ACCEPT_KEY]?->actions->clearOutboundModeration();

        $this->deliverPublished(new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1'], [$orphan], null));

        $remove = $this->onlyQueued(HilosSignalConstants::HILOS_FILE_REMOVE);
        self::assertInstanceOf(FileRemoveSignalData::class, $remove);
        self::assertSame([$orphan], $remove->fileIds);
        self::assertSame(0, $this->messagesWritten());
    }

    public function testAnAnswerCannotPublishWhileAModerationCheckIsStillActive(): void
    {
        $file = $this->registryFile('too-early.txt');
        $second = $this->registryFile('too-early-2.txt');
        $check = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE);
        self::assertNotNull($check);
        $this->deliverChatSignal(new ChatAgent(), ChatSignalConstants::ATTACHMENTS_PUBLISHED,
            new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$file, $second], null));
        self::assertSame([$file, $second], $this->onlyQueued(HilosSignalConstants::HILOS_FILE_REMOVE)->fileIds);
        self::assertSame($check->requestId,
            Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE)->requestId);
        self::assertSame(0, $this->messagesWritten());
    }

    public function testReorderedUploadIdsAreAnOrphanAnswer(): void
    {
        $file = $this->registryFile('wrong-order.txt');
        $second = $this->registryFile('wrong-order-2.txt');
        $this->deliverPublished(new FilesPublishedSignalData(self::ACCEPT_KEY, ['u2', 'u1'], [$file, $second], null));
        self::assertSame([$file, $second], $this->onlyQueued(HilosSignalConstants::HILOS_FILE_REMOVE)->fileIds);
        self::assertSame(0, $this->messagesWritten());
    }

    public function testActualAnswerKeyIsUsedEvenWhenTheAmbientConnectionIsDifferent(): void
    {
        $first = $this->registryFile('one.txt');
        $second = $this->registryFile('two.txt');
        Hilos::$rt->connections->actions->register('another-ak', $this->userId);
        Hilos::$rt->connections['another-ak']->actions->startOutboundModeration('unrelated draft', ['u1', 'u2']);
        ExecutionContext::setCurrentAcceptKey('another-ak');
        $this->deliverPublished(new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$first, $second], null));
        self::assertSame(1, $this->messagesWritten());
        self::assertSame('unrelated draft', Hilos::$rt->connections['another-ak']->outboundModerationMessage);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
            Hilos::$rt->connections['another-ak']->outboundModerationPhase);
        self::assertSame([$first, $second], $this->onlyQueued(HilosSignalConstants::HILOS_FILE_BIND)->fileIds);
        $this->deliverChatSignal(new ChatAgent(), ChatSignalConstants::ATTACHMENTS_PUBLISHED,
            new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$first, $second], null));
        self::assertSame(1, $this->messagesWritten());
    }

    public function testAChangedPersonCannotReceiveThePreviousPersonsPublishedFiles(): void
    {
        $first = $this->registryFile('old-owner-1.txt');
        $second = $this->registryFile('old-owner-2.txt');
        $this->approveMessage();
        $other = (int)Hilos::$db->users->actions->createWithName('Different owner')->id;
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->bindUser($other);
        $before = Hilos::$rt->connections[self::ACCEPT_KEY]->toArray();
        $this->deliverPublished(new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$first, $second], null));
        self::assertSame([$first, $second], $this->onlyQueued(HilosSignalConstants::HILOS_FILE_REMOVE)->fileIds);
        self::assertSame(0, $this->messagesWritten());
        self::assertSame($before, Hilos::$rt->connections[self::ACCEPT_KEY]->toArray());
    }

    public function testAClosedConnectionLeavesOnlyOrphanFilesToRemove(): void
    {
        $first = $this->registryFile('closed-1.txt');
        $second = $this->registryFile('closed-2.txt');
        $this->approveMessage();
        new ChatAgent()->onSignalConnectionClose(new WebSocketCloseSignalDTO(self::ACCEPT_KEY), 'ws', 'close');
        $this->deliverPublished(new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$first, $second], null));
        self::assertSame([$first, $second], $this->onlyQueued(HilosSignalConstants::HILOS_FILE_REMOVE)->fileIds);
        self::assertSame(0, $this->messagesWritten());
    }

    public function testStaleUploadsEndPublicationAndThawAllowsARealRetry(): void
    {
        $first = $this->registryFile('stale-1.txt');
        $second = $this->registryFile('stale-2.txt');
        $this->approveMessage();
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
        Hilos::$rt->connections->actions->register('another-ak', $this->userId);
        $this->completeUpload('another-ak', $this->userId, 'unreachable', 'other.txt', 'text/plain', 'other');
        RtStaleness::mark(HilosUpload::RT_COLLECTION, [HilosUpload::keyFor('another-ak', 'unreachable')], microtime(true));
        self::assertNotNull(Hilos::$rt->hilosUploads->find('another-ak', 'unreachable')->staleSince());
        self::assertNull(Hilos::$rt->connections[self::ACCEPT_KEY]->staleSince());
        $this->deliverPublished(new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$first, $second], null));
        $this->assertRetainedUnavailableDraft();
        self::assertSame([$first, $second], $this->onlyQueued(HilosSignalConstants::HILOS_FILE_REMOVE)->fileIds);
        RtStaleness::reset();
        new ChatAgent()->onTick();
        $this->assertRetainedUnavailableDraft();
        $this->retryRetainedDraft();
    }

    public function testAStaleConnectionCannotBeMutatedByAPublicationAnswer(): void
    {
        $first = $this->registryFile('stale-connection-1.txt');
        $second = $this->registryFile('stale-connection-2.txt');
        $this->approveMessage();
        RtStaleness::mark(ChatRtContext::connections, [self::ACCEPT_KEY], microtime(true));
        $before = Hilos::$rt->connections[self::ACCEPT_KEY]->toArray();
        $this->deliverChatSignal(new ChatAgent(), ChatSignalConstants::ATTACHMENTS_PUBLISHED,
            new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$first, $second], null));
        self::assertSame($before, Hilos::$rt->connections[self::ACCEPT_KEY]->toArray());
        self::assertSame(0, $this->messagesWritten());
        $this->assertNoFileMutationQueued();
    }

    public static function refusedWrites(): iterable
    {
        yield 'first event insert' => ['event'];
        yield 'message after event' => ['event_message'];
        yield 'second attachment after the first link' => ['event_attachment'];
    }

    #[DataProvider('refusedWrites')]
    public function testAWriteFailureRollsBackTheWholeMessageAndAllowsRetry(string $table): void
    {
        $first = $this->registryFile('write-failure-1.txt');
        $second = $this->registryFile('write-failure-2.txt');
        $submittedAt = Hilos::$rt->connections[self::ACCEPT_KEY]->userState->lastOutboundSubmittedAt;
        $refusal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication insert refused';";
        if ($table === 'event_attachment') {
            $refusal = 'IF NEW.file_id = ' . $second . ' THEN ' . $refusal . ' END IF;';
        }
        Database::sqlRun('CREATE TRIGGER `' . self::FAILURE_TRIGGER . '` BEFORE INSERT ON `' . $table . '` '
            . 'FOR EACH ROW BEGIN ' . $refusal . ' END');
        try {
            $this->deliverPublished(new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$first, $second], null));
            $this->assertRetainedUnavailableDraft();
            self::assertSame($submittedAt, Hilos::$rt->connections[self::ACCEPT_KEY]->userState->lastOutboundSubmittedAt);
            foreach (['event', 'event_message', 'event_attachment'] as $writtenTable) {
                self::assertSame(0, (int)Database::sql('SELECT COUNT(*) AS n FROM `' . $writtenTable . '`')->rows()[0]['n'],
                    'No half-message stands after a write failure');
            }
            self::assertSame([$first, $second], $this->onlyQueued(HilosSignalConstants::HILOS_FILE_REMOVE)->fileIds);
        } finally {
            Database::sqlRun('DROP TRIGGER IF EXISTS `' . self::FAILURE_TRIGGER . '`');
        }
        $this->retryRetainedDraft();
    }

    public function testARolledBackMessageDoesNotNotifyItsMentionedReader(): void
    {
        Hilos::$db->users->actions->createWithName('Publication reader');
        $this->forgetChatChecks();
        $text = 'with files @Publication reader';
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->startOutboundModeration($text, ['u1', 'u2']);
        new ChatAgent()->startMessageModeration(self::ACCEPT_KEY);
        $first = $this->registryFile('mention-1.txt');
        $second = $this->registryFile('mention-2.txt');
        Database::sqlRun('CREATE TRIGGER `' . self::FAILURE_TRIGGER . '` BEFORE INSERT ON `event_attachment` '
            . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'publication insert refused'");
        try {
            $this->deliverPublished(new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$first, $second], null));
        } finally {
            Database::sqlRun('DROP TRIGGER IF EXISTS `' . self::FAILURE_TRIGGER . '`');
        }
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertSame($text, Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationMessage);
        self::assertSame([], Hilos::$db->eventAttachments->allFileIds());
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            self::assertNotContains($signal->signalName->getName(),
                [HilosSignalConstants::HILOS_NOTIFICATION_EMIT, HilosSignalConstants::HILOS_FILE_BIND]);
        }
    }

    public function testACleanupFailureStillReleasesTheCurrentComposer(): void
    {
        $first = $this->registryFile('cleanup-failure.txt');
        $second = $this->registryFile('cleanup-failure-2.txt');
        $this->approveMessage();
        RtStaleness::mark(HilosUpload::RT_COLLECTION, ['unreachable'], microtime(true));
        $door = Hilos::$files;
        Hilos::$files = new class ($door->storage) extends HilosFiles {
            /** @param list<int> $fileIds Orphans whose remove frame cannot be queued */
            public function remove(array $fileIds): void
            {
                throw new RuntimeException('remove transport unavailable');
            }
        };
        try {
            $this->deliverPublished(new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$first, $second], null));
            $this->assertRetainedUnavailableDraft();
        } finally {
            Hilos::$files = $door;
        }
    }

    public function testABindFailureAfterCommitCannotTurnTheSavedMessageIntoARetry(): void
    {
        $first = $this->registryFile('bind-failure-1.txt');
        $second = $this->registryFile('bind-failure-2.txt');
        $door = Hilos::$files;
        Hilos::$files = new class ($door->storage) extends HilosFiles {
            /** @param list<int> $fileIds Files already linked to the committed message */
            public function markBound(array $fileIds): void
            {
                throw new RuntimeException('bind transport unavailable');
            }
        };
        try {
            $this->deliverPublished(new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$first, $second], null));
            self::fail('The failed bind request must remain observable');
        } catch (RuntimeException $failure) {
            self::assertSame('bind transport unavailable', $failure->getMessage());
        } finally {
            Hilos::$files = $door;
        }
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertSame([$first, $second], Hilos::$db->eventAttachments->allFileIds());
        $this->drainSignals();
        $this->deliverChatSignal(new ChatAgent(), ChatSignalConstants::ATTACHMENTS_PUBLISHED,
            new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$first, $second], null));
        self::assertSame(1, $this->messagesWritten());
        $this->assertNoFileMutationQueued();
    }

    public static function announcementContexts(): iterable
    {
        yield 'worker handler' => [true];
        yield 'caller outside a handler' => [false];
    }

    #[DataProvider('announcementContexts')]
    public function testAFailedCommitAnnouncementKeepsTheSavedMessageAndBindsItsFiles(bool $insideHandler): void
    {
        $first = $this->registryFile('announcement-1.txt');
        $second = $this->registryFile('announcement-2.txt');
        $reaction = new class implements SourceChangeSubscriberInterface {
            public bool $listening = true;

            /**
             * @param SourceChange $change Published source fact
             * @param SourceChangeProvenance $provenance Source of the write
             */
            public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
            {
                if ($this->listening && $change->sourceKey === 'events') {
                    $this->listening = false;
                    throw new RuntimeException('event announcement unavailable');
                }
            }
        };
        SourceChangeBus::subscribe($reaction);
        if (!$insideHandler) {
            self::assertSame([], Database::handlerEnd());
        }
        try {
            try {
                $this->deliverPublished(new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$first, $second], null));
                self::assertTrue($insideHandler, 'Outside a handler the committed announcement failure reaches the caller');
                $owed = Database::handlerEnd();
                self::assertCount(1, $owed);
                self::assertInstanceOf(AnnouncementFailedException::class, $owed[0]);
            } catch (AnnouncementFailedException $failure) {
                self::assertFalse($insideHandler);
                self::assertStringContainsString('stood', $failure->getMessage());
            }
        } finally {
            $reaction->listening = false;
            Database::handlerStart();
        }
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertSame([$first, $second], Hilos::$db->eventAttachments->allFileIds());
        self::assertSame([$first, $second], $this->onlyQueued(HilosSignalConstants::HILOS_FILE_BIND)->fileIds);
        $this->deliverChatSignal(new ChatAgent(), ChatSignalConstants::ATTACHMENTS_PUBLISHED,
            new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$first, $second], null));
        self::assertSame(1, $this->messagesWritten());
        $this->assertNoFileMutationQueued();
    }

    /**
     * The feed reads the name and the type from the registry row and gets both addresses built.
     *
     * @throws HilosException When the case cannot be arranged
     */
    public function testAnAttachmentCarriesWhatTheFeedDraws(): void
    {
        $fileId = $this->registryFile('photo.txt');
        $event = Hilos::$db->events->actions->addMessage('look', userId: $this->userId, fileIds: [$fileId]);

        $attachment = $event->eventMessage?->attachments->first();
        self::assertInstanceOf(EventAttachment::class, $attachment);
        self::assertSame($fileId, $attachment->file?->id);
        self::assertSame($attachment->id, Hilos::$db->eventAttachments->forFileId($fileId)?->id);
        $row = $attachment->toArray();
        self::assertSame('photo.txt', $row[ObjectFile::filename]);
        self::assertSame('text/plain', $row[ObjectFile::mimeType]);
        self::assertSame(HilosFiles::downloadPath($fileId), $row[EventAttachment::url]);
        self::assertSame(
            HilosFiles::downloadPath($fileId, ChatAttachmentUploadTarget::THUMB_VARIANT),
            $row[EventAttachment::thumbUrl],
        );
        self::assertStringContainsString('variant=' . ChatAttachmentUploadTarget::THUMB_VARIANT, $row[EventAttachment::thumbUrl]);
    }

    private function assertRetainedUnavailableDraft(): void
    {
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertSame(self::TEXT, Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationMessage);
        self::assertSame(['u1', 'u2'], Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationAttachments);
        self::assertSame(ModerationReason::SERVICE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationReason);
        self::assertNull(Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPicture);
        self::assertSame(0, $this->messagesWritten());
    }

    private function retryRetainedDraft(): void
    {
        ExecutionContext::setCurrentAcceptKey(self::ACCEPT_KEY);
        try {
            try {
                new MainPage(new ChatAgent())->onAction(self::ACCEPT_KEY, ChatSignalConstants::MESSAGE,
                    new MessageActionDTO(self::TEXT, ['u1', 'u2']));
                self::fail('An unsuccessful publication must preserve the submit lockout');
            } catch (ValidationException $refusal) {
                self::assertSame('Message rate limit is active', $refusal->getMessage());
            }
            foreach (['u1', 'u2'] as $uploadId) {
                $this->completeUpload(self::ACCEPT_KEY, $this->userId, $uploadId, $uploadId . '.txt', 'text/plain', 'retry bytes');
            }
            // Exercise the real admission timer instead of rewriting the retained rate-limit state.
            $remaining = Hilos::$rt->connections[self::ACCEPT_KEY]->userState->lastOutboundSubmittedAt + 10 - microtime(true);
            if ($remaining > 0) {
                usleep((int)ceil($remaining * 1_000_000));
            }
            new MainPage(new ChatAgent())->onAction(self::ACCEPT_KEY, ChatSignalConstants::MESSAGE,
                new MessageActionDTO(self::TEXT, ['u1', 'u2']));
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
        }
        $check = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE);
        self::assertNotNull($check);
        $this->acknowledgeChatUploads($check);
        // An old reply arriving during the retry must not end its active check.
        $old = $this->fileIds[0];
        $this->deliverChatSignal(new ChatAgent(), ChatSignalConstants::ATTACHMENTS_PUBLISHED,
            new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$old, $this->fileIds[1]], null));
        self::assertSame($check->requestId,
            Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE)->requestId);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        $this->drainSignals();
        $first = $this->registryFile('retry-1.txt');
        $second = $this->registryFile('retry-2.txt');
        $this->deliverPublished(new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$second, $first], null));
        self::assertSame(1, $this->messagesWritten());
        self::assertSame([$second, $first], Hilos::$db->eventAttachments->allFileIds());
        self::assertSame([$second, $first], $this->onlyQueued(HilosSignalConstants::HILOS_FILE_BIND)->fileIds);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        $this->deliverChatSignal(new ChatAgent(), ChatSignalConstants::ATTACHMENTS_PUBLISHED,
            new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$second, $first], null));
        self::assertSame(1, $this->messagesWritten());
        $this->assertNoFileMutationQueued();
    }

    private function assertNoFileMutationQueued(): void
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            self::assertNotContains($signal->signalName->getName(),
                [HilosSignalConstants::HILOS_FILE_REMOVE, HilosSignalConstants::HILOS_FILE_BIND]);
        }
    }

    /**
     * @param string $filename Name the sender gave the file
     * @return int Id of a bound registry row the case removes when it ends
     * @throws HilosException When the file or the row cannot be written
     */
    private function registryFile(string $filename): int
    {
        $fileId = (int)$this->keepRegistryFile($this->userId, $filename, 'bytes of ' . $filename)->id;
        $this->fileIds[] = $fileId;

        return $fileId;
    }

    /**
     * @param FilesPublishedSignalData $answer Answer of the files registry
     * @throws HilosException When the agent fails to handle it
     */
    private function deliverPublished(FilesPublishedSignalData $answer): void
    {
        $this->approveMessage();
        // Publication has consumed the uploads before the registry answers.
        foreach (['u1', 'u2'] as $uploadId) {
            $upload = Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, $uploadId);
            if ($upload !== null) {
                $tmpIndex = $upload->tmpIndex;
                $upload->actions->handOver();
                if ($tmpIndex !== null) {
                    Hilos::$fs->getTmp()[$tmpIndex]->unlink();
                }
            }
        }
        $this->deliverChatSignal(new ChatAgent(), ChatSignalConstants::ATTACHMENTS_PUBLISHED, $answer);
    }

    private function approveMessage(): void
    {
        $check = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE);
        if ($check !== null) {
            $this->decideCheck(new ChatAgent(), $check);
        }
    }

    /**
     * Drains the queue and returns the payload of the one frame of a name on it.
     *
     * @param string $name Agent signal name
     * @return mixed Inner payload of that frame
     */
    private function onlyQueued(string $name): mixed
    {
        $found = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal instanceof SignalDTO && $signal->signalName->getName() === $name) {
                self::assertInstanceOf(AgentSignalData::class, $signal->data);
                $found[] = $signal->data->data;
            }
        }
        self::assertCount(1, $found, "Exactly one {$name} frame is queued");

        return $found[0];
    }

    /**
     * @return int Messages carrying the case's text
     */
    private function messagesWritten(): int
    {
        $count = 0;
        foreach (Hilos::$db->eventMessages->byAuthor($this->userId) as $message) {
            $count += $message->message === self::TEXT ? 1 : 0;
        }

        return $count;
    }
}
