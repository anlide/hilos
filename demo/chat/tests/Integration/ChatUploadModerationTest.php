<?php

declare(strict_types=1);

namespace Demo\Chat\Tests\Integration;

use Closure;
use Demo\Chat\Agents\ChatAgent;
use Demo\Chat\Agents\Hilos\FilesLibraryAgent;
use Demo\Chat\Constants\ChatSignalConstants;
use Demo\Chat\Constants\ConnectionRuntimeConstants;
use Demo\Chat\Files\ChatAttachmentUploadTarget;
use Demo\Chat\Hilos;
use Demo\Chat\Moderation\ChatModerationKindKey;
use Demo\Chat\Pages\DTO\Main\MessageActionDTO;
use Demo\Chat\Pages\MainPage;
use Demo\Chat\Runtime\View\Context\ChatRtContext;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Core\Sync\DTO\RtSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Files\DTO\FilePublishSignalData;
use Hilos\Files\DTO\FileRemoveSignalData;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\Upload\DTO\UploadCancelActionDTO;
use Hilos\Files\Upload\DTO\UploadInitActionDTO;
use Hilos\Files\Upload\DTO\UploadHoldSettleSignalData;
use Hilos\Files\Upload\DTO\UploadPublishSignalData;
use Hilos\Files\Upload\ProfilePhotoUploadTarget;
use Hilos\Files\Upload\UploadHoldOutcome;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Fs\FsPath;
use Hilos\LLM\Contract\AsyncChatLLMInterface;
use Hilos\LLM\DTO\ChatGenerateOptions;
use Hilos\LLM\DTO\Message;
use Hilos\LLM\Exception\LLMException;
use Hilos\LLM\Routing\LlmProfile;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Moderation\ModerationAgent;
use Hilos\Runtime\State\Item\HilosUpload;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\View\Item\HilosModerationCheck;
use Hilos\Socket\WebSocket\DTO\WebSocketCloseSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\TimeHelper;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Real upload actions, chunks, RT ownership, moderation engine and Chat consumer. Only the
 * async model is doubled: its answer arrives after the attempted browser mutation.
 */
final class ChatUploadModerationTest extends IntegrationTestCase
{
    use ChatModerationFixture;

    private const string ACCEPT_KEY = 'immutable-upload-ak';

    private UploadsAgent $uploads;
    private ChatAgent $chat;
    private ?FilesLibraryAgent $files = null;
    private int $userId;
    /** @var list<int> Published files removed after the message links go */
    private array $fileIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Hilos::initBrowser();
        $this->claimChatChecks();
        RtTruthSourceRegistry::register(ChatRtContext::connections, TruthSourceKeys::all(), 'test-agent');
        Hilos::$rt->connections->actions->clear();
        $this->forgetChatChecks();
        $this->userId = (int)Hilos::$db->users->actions->createWithName('Immutable sender')->id;
        Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $this->userId);
        Hilos::$rt->userStates->actions->ensure($this->userId);
        $this->chat = new ChatAgent();
        $this->uploads = new UploadsAgent();
        OwnershipDeclaration::claimAll($this->uploads);
        $this->underAgent($this->uploads, fn () => $this->uploads->onStart());
        $this->drainSignals();
    }

    protected function tearDown(): void
    {
        RtStaleness::reset();
        $this->underAgent($this->uploads, fn () => $this->uploads->onStop());
        RtTruthSourceRegistry::unregisterAgent($this->uploads->getId());
        $this->forgetChatChecks();
        Hilos::$rt->connections->actions->clear();
        Hilos::$rt->userStates->actions->clear();
        Hilos::$db->events->actions->deleteAll();
        if ($this->files !== null) {
            $this->underAgent($this->files, fn () => $this->files->onSignalAgent(
                new AgentSignalData(new FileRemoveSignalData($this->fileIds)), '', HilosSignalConstants::HILOS_FILE_REMOVE));
            TruthSourceRegistry::unregisterAgent($this->files->getId());
        }
        parent::tearDown();
    }

    public static function protectedMutations(): iterable
    {
        foreach (['text', 'image', 'earlier-image', 'handover'] as $stage) {
            foreach (['init', 'cancel', 'other-target'] as $mutation) {
                yield "$stage $mutation" => [$stage, $mutation];
            }
        }
    }

    #[DataProvider('protectedMutations')]
    public function testBrowserCannotReplaceAnyHeldUploadBeforeHandover(string $stage, string $mutation): void
    {
        $this->receive('first');
        $this->receive('second', self::pictureBytes(2));
        $this->submit(['first', 'second']);
        $text = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE);
        self::assertNotNull($text);
        self::assertCount(2, $text->uploadSnapshots);
        self::assertSame($text->requestId, $text->uploadHoldId);
        if ($stage !== 'text') {
            $this->decideCheck($this->chat, $text);
        }
        if ($stage === 'earlier-image' || $stage === 'handover') {
            $this->decideCheck($this->chat, $this->pictureCheck());
        }
        if ($stage === 'handover') {
            $this->decideCheck($this->chat, $this->pictureCheck());
        }
        $upload = Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first');
        self::assertNotNull($upload);
        $snapshot = $upload->toArray();
        $bytes = FsPath::read(Hilos::$fs->getTmp()[$upload->tmpIndex]->getPath());
        $check = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY,
            $stage === 'text' ? ChatModerationKindKey::MESSAGE : ChatModerationKindKey::MESSAGE_PICTURE);
        $attempt = $check?->toArray();
        $this->refused(fn () => $this->mutate($mutation));
        $this->underAgent($this->uploads, fn () => $this->uploads->onSignalFrameBinary(
            new WebSocketFrameBinarySignalDTO(self::ACCEPT_KEY, chr(strlen('first')) . 'first' . self::pictureBytes(2)), '', ''));
        self::assertSame($snapshot, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->toArray());
        self::assertSame($bytes, FsPath::read(Hilos::$fs->getTmp()[$upload->tmpIndex]->getPath()));
        self::assertSame($attempt, Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY,
            $stage === 'text' ? ChatModerationKindKey::MESSAGE : ChatModerationKindKey::MESSAGE_PICTURE)?->toArray(),
            'A refused mutation must not replace or extend the check');
        self::assertSame(['first', 'second'], Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationAttachments);
        if ($stage === 'text') {
            $this->decideCheck($this->chat, $text);
        }
        while (($picture = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE_PICTURE)) !== null) {
            self::assertSame($text->deadlineAt, $picture->deadlineAt);
            self::assertNotSame($text->requestId, $picture->requestId);
            self::assertSame($text->uploadHoldId, $picture->uploadHoldId);
            self::assertSame($text->uploadSnapshots, $picture->uploadSnapshots);
            self::assertSame([], $picture->clientUploadIds);
            $this->decideCheck($this->chat, $picture);
        }
        $this->handOverOriginals($bytes);
    }

    public function testAsyncImageAnswerCannotApproveAReplacementAndRestartKeepsTheGuard(): void
    {
        $this->receive('first');
        $this->submit(['first']);
        $this->decideCheck($this->chat,
            Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
        $model = new ChatUploadModerationModel();
        $moderator = $this->moderator($model);
        $this->underAgent($moderator, fn () => $moderator->onTick());
        self::assertTrue($model->isBusy());
        self::assertNotEmpty($model->messages[1]->images);
        self::assertStringContainsString('first.png', $model->messages[1]->content);
        $held = Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->toArray();
        $image = $this->pictureCheck()->toArray();
        $this->chat->onStop();
        $this->chat = new ChatAgent();
        $this->chat->onStart();
        self::assertSame($held, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->toArray());
        self::assertSame($image, $this->pictureCheck()->toArray());
        $this->refused(fn () => $this->mutate('cancel'));
        $this->refused(fn () => $this->mutate('init'));
        $model->answer();
        $this->underAgent($moderator, fn () => $moderator->onTick());
        $verdict = $this->take(HilosSignalConstants::HILOS_MODERATION_VERDICT);
        self::assertInstanceOf(ModerationVerdictSignalData::class, $verdict);
        self::assertTrue($verdict->allow);
        $this->deliverChatSignal($this->chat, HilosSignalConstants::HILOS_MODERATION_VERDICT, $verdict);
        $this->handOverOriginals(self::pictureBytes());
        $moderator->onStop();
    }

    public static function releasedAttempts(): iterable
    {
        foreach (['rejected', 'unavailable', 'unreadable', 'close', 'user-switch', 'handover-close', 'handover-user-switch'] as $outcome) {
            yield $outcome => [$outcome];
        }
    }

    #[DataProvider('releasedAttempts')]
    public function testEndedAttemptAllowsCancelAndANewUpload(string $outcome): void
    {
        $this->receive('first');
        $this->submit(['first']);
        if (str_starts_with($outcome, 'handover-')) {
            $this->decideCheck($this->chat,
                Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
            $this->decideCheck($this->chat, $this->pictureCheck());
            $approval = $this->take(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE);
            self::assertSame(UploadHoldOutcome::APPROVED, $approval->outcome);
            $this->applyUploadSettlement($approval);
            $outcome = substr($outcome, strlen('handover-'));
        }
        if ($outcome === 'close') {
            $this->chat->onSignalConnectionClose(new WebSocketCloseSignalDTO(self::ACCEPT_KEY), '', '');
            Hilos::$rt->connections->actions->register(self::ACCEPT_KEY, $this->userId);
        } elseif ($outcome === 'user-switch') {
            $other = (int)Hilos::$db->users->actions->createWithName('Next sender')->id;
            $this->chat->onSignalAgent(new AgentSignalData(new SessionStateSignalData(
                sessionToken: 'replacement', sessionId: null, userId: $other, acceptKeys: [self::ACCEPT_KEY])),
                '', HilosSignalConstants::HILOS_SESSION_STATE);
        } elseif ($outcome === 'unreadable') {
            $this->decideCheck($this->chat,
                Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
            $this->decideCheck($this->chat, $this->pictureCheck(), false, 'unreadable');
        } else {
            $this->decideCheck($this->chat,
                Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE),
                false, $outcome === 'unavailable' ? 'service_unavailable' : 'spam');
        }
        $release = $this->take(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE);
        self::assertInstanceOf(UploadHoldSettleSignalData::class, $release);
        self::assertSame(UploadHoldOutcome::RELEASED, $release->outcome);
        $this->applyUploadSettlement($release);
        self::assertSame($release->uploadHoldId, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->uploadHoldId);
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->uploadHoldDeadlineAt);
        self::assertFalse(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->uploadHoldApproved);
        $this->mutate('cancel');
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first'));
        $this->receive('first', self::pictureBytes(2));
        self::assertSame(UploadPhase::COMPLETE, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->phase);
        Hilos::$rt->userStates->actions->clear();
        Hilos::$rt->userStates->actions->ensure(Hilos::$rt->connections[self::ACCEPT_KEY]->userId);
        $this->submit(['first']);
        self::assertNotNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
    }

    public function testUnrelatedUploadsStayMutableWhileComposerChecks(): void
    {
        $this->receive('first');
        $this->receive('unsubmitted', self::pictureBytes(2));
        $this->submit(['first']);
        $this->underAgent($this->uploads, fn () => $this->uploads->onAgentAction(self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_UPLOAD_CANCEL, new UploadCancelActionDTO('unsubmitted')));
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'unsubmitted'));
        $this->init('profile', ProfilePhotoUploadTarget::NAME, 'image/jpeg', 10);
        $this->underAgent($this->uploads, fn () => $this->uploads->onAgentAction(self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_UPLOAD_CANCEL, new UploadCancelActionDTO('profile')));
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'profile'));
    }

    public function testModelAndConsumerCannotAdvanceBeforeOwnerAdmission(): void
    {
        $this->receive('first');
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->startOutboundModeration('held', ['first']);
        $this->chat->startMessageModeration(self::ACCEPT_KEY);
        $text = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE);
        self::assertNotNull($text);
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->uploadHoldId);
        $model = new ChatUploadModerationModel();
        $moderator = $this->moderator($model);
        $this->underAgent($moderator, fn () => $moderator->onTick());
        self::assertFalse($model->isBusy());
        $this->decideCheck($this->chat, $text);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE_PICTURE));
        $release = $this->take(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE);
        self::assertSame(UploadHoldOutcome::RELEASED, $release->outcome);
        $this->assertNoPublicationOrNotification();
        $moderator->onStop();
    }

    public function testOrdinaryProfilePhotoHandoverNeedsNoChatHold(): void
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(1, 1));
        $jpeg = ob_get_clean();
        $this->init('profile', ProfilePhotoUploadTarget::NAME, 'image/jpeg', strlen($jpeg));
        $this->underAgent($this->uploads, fn () => $this->uploads->onSignalFrameBinary(
            new WebSocketFrameBinarySignalDTO(self::ACCEPT_KEY, chr(strlen('profile')) . 'profile' . $jpeg), '', ''));
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'profile')->uploadHoldId);
        self::assertFalse(new ProfilePhotoUploadTarget()->requiresUploadHoldForPublication());
        $completeBytes = FsPath::read(Hilos::$fs->getTmp()[Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'profile')->tmpIndex]->getPath());
        $this->underAgent($this->uploads, fn () => $this->uploads->onSignalAgent(
            new AgentSignalData(new UploadPublishSignalData(self::ACCEPT_KEY, ProfilePhotoUploadTarget::NAME,
                ['profile'], ChatSignalConstants::ATTACHMENTS_PUBLISHED)), '', HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        $published = $this->take(HilosSignalConstants::HILOS_FILE_PUBLISH);
        self::assertInstanceOf(FilePublishSignalData::class, $published);
        self::assertSame(['profile'], $published->clientUploadIds);
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'profile'));
        self::assertSame($completeBytes, FsPath::read(Hilos::$fs->getTmp()[$published->files[0]->tmpIndex]->getPath()));
        Hilos::$fs->getTmp()[$published->files[0]->tmpIndex]->unlink();
    }

    public function testSameBytesRecreatedBeforeAdmissionDoNotRefreshTheSnapshot(): void
    {
        $this->receive('first');
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->startOutboundModeration('held', ['first']);
        $this->chat->startMessageModeration(self::ACCEPT_KEY);
        $text = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE);
        self::assertNotNull($text);
        $original = Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->toArray();
        $this->underAgent($this->uploads, function (): void {
            Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->actions->forgetWithFile();
            $tmpIndex = Hilos::$fs->getTmp()->create();
            Hilos::$fs->getTmp()[$tmpIndex]->append(self::pictureBytes());
            Hilos::$rt->hilosUploads->actions->open(self::ACCEPT_KEY, 'first', ChatAttachmentUploadTarget::NAME,
                $this->userId, 'replacement.png', 'image/png', strlen(self::pictureBytes()), $tmpIndex)
                ->actions->inspect(hash('sha256', self::pictureBytes()), 'image/png');
            Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->actions->complete();
        });
        self::assertSame($original[HilosUpload::contentHash], Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->contentHash);
        self::assertNotSame($original[HilosUpload::tmpIndex], Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->tmpIndex);
        $this->acknowledgeChatUploads($text);
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->uploadHoldId);
        $this->decideCheck($this->chat, $text);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertSame($original[HilosUpload::tmpIndex], iterator_to_array($text->uploadSnapshots)[0]->tmpIndex);
        self::assertSame(UploadHoldOutcome::RELEASED, $this->take(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE)->outcome);
        $this->assertNoPublicationOrNotification();
    }

    public function testReleaseTombstoneAllowsAFreshTokenAndLateReleaseLeavesItHeld(): void
    {
        $this->receive('first');
        $this->submit(['first']);
        $text = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE);
        $this->decideCheck($this->chat, $text, false, 'service_unavailable');
        $release = $this->take(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE);
        self::assertSame(UploadHoldOutcome::RELEASED, $release->outcome);
        $this->applyUploadSettlement($release);
        $this->acknowledgeChatUploads($text);
        self::assertSame($text->uploadHoldId, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->uploadHoldId);
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->uploadHoldDeadlineAt);
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->startOutboundModeration('retry', ['first']);
        $this->chat->startMessageModeration(self::ACCEPT_KEY);
        $retry = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE);
        self::assertNotNull($retry);
        self::assertNotSame($text->uploadHoldId, $retry->uploadHoldId);
        $this->acknowledgeChatUploads($retry);
        $this->applyUploadSettlement($release);
        $this->chat->onSignalRtSyncUpdated(new RtSyncUpdatedSignalData(
            HilosUpload::RT_COLLECTION, HilosUpload::keyFor(self::ACCEPT_KEY, 'first'),
            [HilosUpload::uploadHoldId => $text->uploadHoldId, HilosUpload::uploadHoldDeadlineAt => null]), '', '');
        self::assertSame($retry->uploadHoldId, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->uploadHoldId);
        self::assertSame($retry->deadlineAt, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->uploadHoldDeadlineAt);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        $this->decideCheck($this->chat, $retry);
        $this->decideCheck($this->chat, $this->pictureCheck());
        $this->handOverOriginals(self::pictureBytes());
    }

    public static function expiryStages(): iterable
    {
        yield 'active check' => [false];
        yield 'waiting for handover' => [true];
    }

    #[DataProvider('expiryStages')]
    public function testOwnerExpiryEndsTheCurrentWaitWithoutRecomputingItsDeadline(bool $handover): void
    {
        $this->receive('first');
        Hilos::$rt->connections[self::ACCEPT_KEY]->actions->startOutboundModeration('held', ['first']);
        $text = Hilos::$rt->hilosModerationChecks->actions->open(self::ACCEPT_KEY, $this->userId,
            ChatModerationKindKey::MESSAGE, text: 'held', clientUploadIds: ['first'],
            deadlineAt: TimeHelper::nowMs() + 1000,
            uploadSnapshots: Hilos::$rt->hilosUploads->snapshotForConnection(self::ACCEPT_KEY, ['first']));
        $this->acknowledgeChatUploads($text);
        if ($handover) {
            $this->decideCheck($this->chat, $text);
            $this->decideCheck($this->chat, $this->pictureCheck());
            $this->applyUploadSettlement($this->take(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE));
        }
        // The real owner's bounded sweep runs once a second, independently of the fixed deadline.
        usleep(1_100_000);
        $this->underAgent($this->uploads, fn () => $this->uploads->onTick());
        self::assertSame($text->uploadHoldId, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->uploadHoldId);
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->uploadHoldDeadlineAt);
        self::assertFalse(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->uploadHoldApproved);
        $this->chat->onSignalRtSyncUpdated(new RtSyncUpdatedSignalData(
            HilosUpload::RT_COLLECTION, HilosUpload::keyFor(self::ACCEPT_KEY, 'first'),
            [HilosUpload::uploadHoldDeadlineAt => null]), '', '');
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
        self::assertSame(UploadHoldOutcome::RELEASED, $this->take(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE)->outcome);
        $this->assertNoPublicationOrNotification();
    }

    public function testUploadsRestartSourceDeletionEndsAnActiveChain(): void
    {
        $this->receive('first');
        $this->submit(['first']);
        $this->underAgent($this->uploads, fn () => $this->uploads->onStop());
        $this->underAgent($this->uploads, fn () => $this->uploads->onStart());
        $this->chat->onSignalRtSyncDeleted(new RtSyncDeletedSignalData(
            HilosUpload::RT_COLLECTION, HilosUpload::keyFor(self::ACCEPT_KEY, 'first')), '', '');
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
    }

    public function testOwnerCleanupCannotLetAHeldIdBeReintroducedThroughAnotherTarget(): void
    {
        $this->receive('first');
        $this->receive('second', self::pictureBytes(2));
        $this->submit(['first', 'second']);
        $this->decideCheck($this->chat,
            Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE));
        $this->decideCheck($this->chat, $this->pictureCheck());
        $second = $this->pictureCheck();
        $this->underAgent($this->uploads, fn () => $this->uploads->onStop());
        $this->uploads = new UploadsAgent();
        $this->underAgent($this->uploads, fn () => $this->uploads->onStart());
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first'));
        $this->refused(fn () => $this->mutate('init'), 'This attachment cannot be changed while its message is being checked');
        ob_start();
        imagejpeg(imagecreatetruecolor(1, 1));
        $jpeg = ob_get_clean();
        $this->init('first', ProfilePhotoUploadTarget::NAME, 'image/jpeg', strlen($jpeg));
        $this->underAgent($this->uploads, fn () => $this->uploads->onSignalFrameBinary(
            new WebSocketFrameBinarySignalDTO(self::ACCEPT_KEY, chr(strlen('first')) . 'first' . $jpeg), '', ''));
        self::assertSame(UploadPhase::COMPLETE, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->phase);
        $snapshot = Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->toArray();
        $this->refused(fn () => $this->mutate('init'), 'This attachment cannot be changed while its message is being checked');
        self::assertSame($snapshot, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first')->toArray());
        $this->decideCheck($this->chat, $second);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_UNAVAILABLE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        $this->mutate('cancel');
        $this->receive('first', self::pictureBytes(3));
    }

    public function testStaleComposerRefusesDestructiveMutationUntilItsSourceReturns(): void
    {
        $this->receive('first');
        RtStaleness::mark(ChatRtContext::connections, [self::ACCEPT_KEY], microtime(true));
        $this->refused(fn () => $this->mutate('cancel'), 'This attachment cannot be changed while its message is being checked');
        self::assertNotNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first'));
        RtStaleness::reset();
        $this->mutate('cancel');
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first'));
    }

    private static function pictureBytes(int $width = 1): string
    {
        $image = imagecreatetruecolor($width, 1);
        ob_start();
        imagepng($image);
        return ob_get_clean();
    }

    private function moderator(ChatUploadModerationModel $model): ModerationAgent
    {
        return new class ($model) extends ModerationAgent {
            public function __construct(private readonly AsyncChatLLMInterface $model)
            {
            }

            protected function createChatClient(LlmProfile $profile): AsyncChatLLMInterface
            {
                return $this->model;
            }
        };
    }

    private function receive(string $id, ?string $bytes = null): void
    {
        $bytes ??= self::pictureBytes();
        $this->init($id, ChatAttachmentUploadTarget::NAME, 'image/png', strlen($bytes));
        $this->underAgent($this->uploads, fn () => $this->uploads->onSignalFrameBinary(
            new WebSocketFrameBinarySignalDTO(self::ACCEPT_KEY, chr(strlen($id)) . $id . $bytes), '', ''));
        self::assertSame(UploadPhase::COMPLETE, Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, $id)->phase);
    }

    private function init(string $id, string $target, string $mime, int $size): void
    {
        $this->underAgent($this->uploads, fn () => $this->uploads->onAgentAction(self::ACCEPT_KEY,
            HilosSignalConstants::HILOS_UPLOAD_INIT, new UploadInitActionDTO($target, $id, "$id.png", $mime, $size)));
    }

    private function mutate(string $mutation): void
    {
        if ($mutation === 'cancel') {
            $this->underAgent($this->uploads, fn () => $this->uploads->onAgentAction(self::ACCEPT_KEY,
                HilosSignalConstants::HILOS_UPLOAD_CANCEL, new UploadCancelActionDTO('first')));
        } else {
            $this->init('first', $mutation === 'other-target' ? ProfilePhotoUploadTarget::NAME : ChatAttachmentUploadTarget::NAME,
                $mutation === 'other-target' ? 'image/jpeg' : 'image/png', strlen(self::pictureBytes(2)));
        }
    }

    private function refused(Closure $mutation, string $reason = 'This file is being checked; wait for the result'): void
    {
        try {
            $mutation();
            self::fail('A held upload must survive browser cancel and replacement');
        } catch (ValidationException $refusal) {
            self::assertSame($reason, $refusal->getMessage());
        }
    }

    private function submit(array $ids): void
    {
        ExecutionContext::setCurrentAcceptKey(self::ACCEPT_KEY);
        try {
            new MainPage($this->chat)->onAction(self::ACCEPT_KEY, ChatSignalConstants::MESSAGE, new MessageActionDTO('held', $ids));
            $check = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE);
            self::assertNotNull($check);
            $this->acknowledgeChatUploads($check);
        } finally {
            ExecutionContext::setCurrentAcceptKey(null);
        }
    }

    private function pictureCheck(): HilosModerationCheck
    {
        $check = Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ChatModerationKindKey::MESSAGE_PICTURE);
        self::assertNotNull($check);
        return $check;
    }

    private function handOverOriginals(string $firstBytes): void
    {
        $approval = $this->take(HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE);
        self::assertInstanceOf(UploadHoldSettleSignalData::class, $approval);
        self::assertSame(UploadHoldOutcome::APPROVED, $approval->outcome);
        $this->applyUploadSettlement($approval);
        $request = $this->take(HilosSignalConstants::HILOS_UPLOAD_PUBLISH);
        self::assertInstanceOf(UploadPublishSignalData::class, $request);
        $this->underAgent($this->uploads, fn () => $this->uploads->onSignalAgent(
            new AgentSignalData($request), SignalSource::AGENT, HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        $published = $this->take(HilosSignalConstants::HILOS_FILE_PUBLISH);
        self::assertInstanceOf(FilePublishSignalData::class, $published);
        self::assertSame($request->clientUploadIds, $published->clientUploadIds);
        self::assertSame($firstBytes, FsPath::read(Hilos::$fs->getTmp()[$published->files[0]->tmpIndex]->getPath()));
        self::assertNull(Hilos::$rt->hilosUploads->find(self::ACCEPT_KEY, 'first'));
        foreach ($request->clientUploadIds as $uploadId) {
            $this->chat->onSignalRtSyncDeleted(new RtSyncDeletedSignalData(
                HilosUpload::RT_COLLECTION, HilosUpload::keyFor(self::ACCEPT_KEY, $uploadId)), '', '');
        }
        $this->chat->onTick();
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_CHECKING,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase, 'Normal handover keeps the registry reply admissible');
        TruthSourceRegistry::unregister(HilosDbContext::files, 'test-agent');
        $this->files = new FilesLibraryAgent();
        OwnershipDeclaration::claimAll($this->files);
        $this->underAgent($this->files, fn () => $this->files->onSignalAgent(
            new AgentSignalData($published), '', HilosSignalConstants::HILOS_FILE_PUBLISH));
        $answer = $this->take(ChatSignalConstants::ATTACHMENTS_PUBLISHED);
        self::assertInstanceOf(FilesPublishedSignalData::class, $answer);
        self::assertNull($answer->error);
        $this->fileIds = $answer->fileIds;
        self::assertSame($firstBytes, Hilos::$files->storage->read(Hilos::$db->files[$answer->fileIds[0]]->storedName));
        $this->deliverChatSignal($this->chat, ChatSignalConstants::ATTACHMENTS_PUBLISHED, $answer);
        self::assertSame(ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_NONE,
            Hilos::$rt->connections[self::ACCEPT_KEY]->outboundModerationPhase);
        self::assertSame($answer->fileIds, Hilos::$db->eventAttachments->allFileIds());
        $bind = $this->take(HilosSignalConstants::HILOS_FILE_BIND);
        $this->underAgent($this->files, fn () => $this->files->onSignalAgent(
            new AgentSignalData($bind), '', HilosSignalConstants::HILOS_FILE_BIND));
        $this->receive('first', self::pictureBytes(3));
    }

    private function take(string $name): mixed
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === $name) {
                return $signal->data instanceof AgentSignalData ? $signal->data->data : $signal->data;
            }
        }
        self::fail("Expected $name");
    }

    private function assertNoPublicationOrNotification(): void
    {
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            self::assertNotContains($signal->signalName->getName(), [
                HilosSignalConstants::HILOS_UPLOAD_PUBLISH,
                HilosSignalConstants::HILOS_FILE_PUBLISH,
                HilosSignalConstants::HILOS_NOTIFICATION_EMIT,
            ]);
        }
    }
}

final class ChatUploadModerationModel implements AsyncChatLLMInterface
{
    /** @var list<Message> Messages of the current request */
    public array $messages = [];
    private bool $busy = false;
    private ?string $result = null;

    public function startGenerate(array $messages, ChatGenerateOptions $options): void
    {
        $this->messages = $messages;
        $this->busy = true;
    }

    public function tick(float $currentTimeMs): void
    {
    }

    public function hasResult(): bool
    {
        return $this->result !== null;
    }

    public function consumeResult(): string
    {
        $answer = $this->result ?? throw new LLMException('No answer');
        $this->result = null;
        return $answer;
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function reset(): void
    {
        $this->busy = false;
        $this->result = null;
    }

    public function answer(): void
    {
        $this->busy = false;
        $this->result = '{"allow":true,"reason":"ok"}';
    }
}
