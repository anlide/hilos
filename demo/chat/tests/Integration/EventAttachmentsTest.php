<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Core\Router\ChatSignalRouter;
use Demo\Chat\Core\Router\DTO\ModerationResultSignalData;
use Demo\Chat\Database\View\Item\EventAttachment;
use Demo\Chat\Files\ChatAttachmentUploadTarget;
use Demo\Chat\Hilos;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Object\Item\File as ObjectFile;
use Hilos\Files\DTO\FileBindSignalData;
use Hilos\Files\DTO\FileRemoveSignalData;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\HilosFiles;
use Hilos\Files\Upload\DTO\UploadPublishSignalData;
use Hilos\HilosException;
use Hilos\TruthSource\RtTruthSourceRegistry;

/**
 * Integration coverage for a message carrying files: publication on approval, the answer of the
 * files registry, and what the feed reads of an attachment (HIL-144).
 *
 * The uploads themselves are the framework's and are not driven here: the page names them by
 * their client ids, asks the registry to publish them once moderation approves, and writes the
 * message with links to the published rows when the answer comes back.
 */
final class EventAttachmentsTest extends IntegrationTestCase
{
    private const string TEST_AGENT_ID = 'test-agent';

    private const string ACCEPT_KEY = 'attach-ak';

    private const string TEXT = 'with files';

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
        Hilos::initSignalRouter(new ChatSignalRouter());
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), self::TEST_AGENT_ID);
        Hilos::$rt->connections->actions->clear();
        Hilos::$db->events->actions->deleteAll();

        $this->userId = (int)Hilos::$db->users->actions->createWithName('Attaching')->id;
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $this->userId);
        Hilos::$rt->userStates->actions->ensure($this->userId)->actions->recordOutboundSubmission();
        Hilos::$rt->connections[self::ACCEPT_KEY]?->actions->startOutboundModeration(self::TEXT, ['u1', 'u2']);
        $this->drainSignals();
    }

    protected function tearDown(): void
    {
        ExecutionContext::setCurrentAcceptKey(null);
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();
        foreach ($this->fileIds as $fileId) {
            $file = Hilos::$db->files[$fileId];
            if ($file !== null) {
                $storedName = $file->storedName;
                $file->actions->delete();
                Hilos::$fs?->files[$storedName]->unlink();
            }
        }
        parent::tearDown();
    }

    public function testApprovalAsksTheRegistryToPublishTheMessagesUploads(): void
    {
        $this->deliver(
            ChatSignalConstants::MODERATION_RESULT,
            new ModerationResultSignalData(self::ACCEPT_KEY, $this->userId, self::TEXT, true, ''),
        );

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

        $this->deliverPublished(new FilesPublishedSignalData(self::ACCEPT_KEY, ['u1', 'u2'], [$orphan], null));

        $remove = $this->onlyQueued(HilosSignalConstants::HILOS_FILE_REMOVE);
        self::assertInstanceOf(FileRemoveSignalData::class, $remove);
        self::assertSame([$orphan], $remove->fileIds);
        self::assertSame(0, $this->messagesWritten());
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
     * @throws HilosException When the page fails to handle it
     */
    private function deliverPublished(FilesPublishedSignalData $answer): void
    {
        $this->deliver(ChatSignalConstants::ATTACHMENTS_PUBLISHED, $answer);
    }

    /**
     * Hands one agent frame to the main page of the sending connection, as the page router does.
     *
     * @param string $name Agent signal name
     * @param ModerationResultSignalData|FilesPublishedSignalData $payload Frame payload
     * @throws HilosException When the page fails to handle it
     */
    private function deliver(string $name, ModerationResultSignalData|FilesPublishedSignalData $payload): void
    {
        ExecutionContext::setCurrentAcceptKey(self::ACCEPT_KEY);
        try {
            new MainPage(new ChatAgent())->onSignalAgent(new AgentSignalData($payload), '', $name);
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
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
