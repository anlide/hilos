<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\Command\ProfilePhotoCommands;
use Hilos\Auth\Library\DTO\ProfilePhotoRemoveActionDTO;
use Hilos\Auth\Library\DTO\ProfilePhotoSetActionDTO;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Definition\ModerationFeature;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Feature\Definition\UploadsFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\HilosFiles;
use Hilos\Files\Storage\LocalFilesStorage;
use Hilos\Files\Upload\DTO\UploadPublishSignalData;
use Hilos\Files\Upload\ProfilePhotoUploadTarget;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Moderation\DTO\ModerationCheckSignalData;
use Hilos\Moderation\DTO\ModerationVerdictSignalData;
use Hilos\Moderation\ModerationAgent;
use Hilos\Moderation\ModerationAgentDaemon;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKind;
use Hilos\Moderation\ModerationKindDirectory;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Moderation\ModerationReason;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Item\HilosModerationCheck as StateHilosModerationCheck;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosModerationCheck;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\Agent\AbstractUserAgent;
use Hilos\Users\ProfilePhotoRefusal;
use Hilos\Users\UserNotificationType;

/**
 * The user's photo action through its moderation check, publication and durable row.
 *
 * With moderation the people library opens the photo check in the shared queue under its own set
 * claim - the one its class declares - and the verdict is handed to it as the router would; without
 * moderation the upload goes straight to publication (HIL-1625).
 */
final class ProfilePhotoIntegrationTest extends ProfileIntegrationTestCase
{
    private const int FIRST_FILE_ID = 9101;
    private const int SECOND_FILE_ID = 9102;
    private const string PHOTO_UNAVAILABLE = 'Photos cannot be checked right now.';

    private string $previousAppClass;
    private ?HilosFiles $previousFiles;
    private ?HilosNotifier $previousNotify;

    /** @var list<SignalDTO> Queued frames the previous assertion did not consume */
    private array $pendingSignals = [];

    /** When set, the person's agent refuses the photo write. */
    private bool $putWriteFails = false;

    /** @return RtContext Profile runtime with uploads and pending photo checks */
    protected function createRuntime(): RtContext
    {
        return new ProfilePhotoIntegrationRtContext();
    }

    /**
     * @throws HilosException When the fixture cannot be mounted
     */
    protected function setUp(): void
    {
        parent::setUp();
        self::photoStubs(down: true);
        self::photoStubs(down: false);
        Schema::reset();
        Schema::initialize();

        $this->previousAppClass = Hilos::appClass();
        $this->previousFiles = Hilos::$files;
        $this->previousNotify = Hilos::$notify;
        Hilos::$files = new HilosFiles(new LocalFilesStorage());
        Hilos::$notify = new HilosNotifier();
        ProfilePhotoModerationSettings::$timeoutSec = ModerationSettingsCatalog::DEFAULT_CHECK_TIMEOUT_SEC;
        Hilos::$setting = new ProfilePhotoModerationSettings(ProfilePhotoModerationSettingsCatalog::class);
        ProfilePhotoImmediateTestHilos::initBrowser();
        RtTruthSourceRegistry::registerDaemon(StateHilosUpload::RT_COLLECTION);
        RtTruthSourceRegistry::register(
            StateHilosModerationCheck::RT_COLLECTION,
            TruthSourceKeys::set($this->library->ownedRtSetKey(StateHilosModerationCheck::RT_COLLECTION)),
            $this->library->getId(),
            OwnershipDeclaration::rtSetCollectionsOf(ProfileIntegrationLibrary::class)[StateHilosModerationCheck::RT_COLLECTION],
        );
    }

    /**
     * @throws DatabaseException When dropping fixture tables fails
     */
    protected function tearDown(): void
    {
        RtStaleness::clear(ProfileIntegrationConnections::RT_COLLECTION, [self::ACCEPT_KEY]);
        RtTruthSourceRegistry::unregister(StateHilosModerationCheck::RT_COLLECTION, $this->library->getId());
        RtTruthSourceRegistry::unregisterDaemon(StateHilosUpload::RT_COLLECTION);
        Hilos::$files = $this->previousFiles;
        Hilos::$notify = $this->previousNotify;
        $previous = $this->previousAppClass;
        $previous::initBrowser();
        self::photoStubs(down: true);
        Schema::reset();

        parent::tearDown();
    }

    /** A published photo replaces the former row and sends bind, removal and restate frames. */
    public function testImmediatePublicationAndRemoval(): void
    {
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u1'));
        $published = $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH);
        self::assertCount(1, $published);
        self::assertInstanceOf(AgentSignalData::class, $published[0]->data);
        self::assertInstanceOf(UploadPublishSignalData::class, $published[0]->data->data);
        self::assertSame(['u1'], $published[0]->data->data->clientUploadIds);

        $this->seedFile(self::FIRST_FILE_ID);
        $this->deliverPublished('u1', self::FIRST_FILE_ID);
        $this->settleProfileFlows();
        self::assertSame(self::FIRST_FILE_ID, Hilos::$db->userPhotos[self::USER_ID]->fileId);
        self::assertCount(1, $this->signalsNamed(HilosSignalConstants::HILOS_FILE_BIND));
        $restate = $this->signalsNamed(HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE);
        self::assertCount(1, $restate);
        self::assertInstanceOf(AgentSignalData::class, $restate[0]->data);
        $this->holder->onSignalAgent($restate[0]->data, 'test', HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE);
        self::assertCount(2, $this->signalsNamed(HilosSignalConstants::HILOS_SESSION_STATE));

        $this->seedFile(self::SECOND_FILE_ID);
        $this->deliverPublished('u2', self::SECOND_FILE_ID);
        $this->settleProfileFlows();
        self::assertSame(self::SECOND_FILE_ID, Hilos::$db->userPhotos[self::USER_ID]->fileId);
        self::assertCount(1, $this->signalsNamed(HilosSignalConstants::HILOS_FILE_REMOVE));
        self::assertCount(1, $this->signalsNamed(HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE));

        $this->submit(HilosSignalConstants::PROFILE_PHOTO_REMOVE, new ProfilePhotoRemoveActionDTO());
        $this->settleProfileFlows();
        self::assertArrayNotHasKey(self::USER_ID, Hilos::$db->userPhotos);
        self::assertCount(1, $this->signalsNamed(HilosSignalConstants::HILOS_FILE_REMOVE));
        self::assertCount(1, $this->signalsNamed(HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE));
    }

    /**
     * The check holds the photo back: a second submit is refused, a verdict naming another attempt
     * or another kind changes nothing, and the verdict of this attempt releases the upload once.
     *
     * @throws HilosException When the action or a frame fails
     */
    public function testApprovalWaitsForTheVerdictOfThisAttempt(): void
    {
        ProfilePhotoModeratedTestHilos::initBrowser();
        $this->seedCompleteUpload('u1');
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u1'));
        $check = $this->photoCheck();
        self::assertSame('u1', $check->clientUploadId);
        self::assertSame(self::USER_ID, $check->userId);
        self::assertSame(HilosAgentType::HILOS_USERS_LIBRARY, $check->verdictTo);
        self::assertArrayNotHasKey(self::USER_ID, Hilos::$db->userPhotos);
        $this->assertCheckingFrames([true]);

        try {
            $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u2'));
            self::fail('A second check on this connection must be refused');
        } catch (ValidationException $refusal) {
            self::assertSame(ProfilePhotoRefusal::STILL_CHECKING, $refusal->getMessage());
        }
        $this->deliverVerdict(ModerationKindKey::PROFILE_PHOTO, 'another-attempt', true, ModerationReason::OK);
        $this->deliverVerdict(ModerationKindKey::PROFILE_NAME, $check->requestId, true, ModerationReason::OK);
        self::assertSame($check->requestId, $this->photoCheck()->requestId);
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        $this->assertCheckingFrames([]);

        $this->deliverVerdict(ModerationKindKey::PROFILE_PHOTO, $check->requestId, true, ModerationReason::OK);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationKindKey::PROFILE_PHOTO));
        $this->assertCheckingFrames([false]);
        $published = $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH);
        self::assertCount(1, $published);
        self::assertInstanceOf(AgentSignalData::class, $published[0]->data);
        self::assertInstanceOf(UploadPublishSignalData::class, $published[0]->data->data);
        self::assertSame(['u1'], $published[0]->data->data->clientUploadIds);

        $this->deliverVerdict(ModerationKindKey::PROFILE_PHOTO, $check->requestId, true, ModerationReason::OK);
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        $this->assertCheckingFrames([]);
    }

    /**
     * A refusal about the photo notifies the person; an unavailable moderator only answers the tab.
     *
     * @throws HilosException When the action or a frame fails
     */
    public function testRefusalAndUnavailableVerdicts(): void
    {
        ProfilePhotoModeratedTestHilos::initBrowser();
        $this->seedCompleteUpload('u1');
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u1'));
        $this->drainPending();
        $this->deliverVerdict(ModerationKindKey::PROFILE_PHOTO, $this->photoCheck()->requestId, false, 'nudity');
        self::assertArrayNotHasKey(self::USER_ID, Hilos::$db->userPhotos);
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        $this->assertCheckingFrames([false]);
        $notices = $this->signalsNamed(HilosSignalConstants::HILOS_NOTIFICATION_EMIT);
        self::assertCount(1, $notices);
        self::assertInstanceOf(AgentSignalData::class, $notices[0]->data);
        self::assertInstanceOf(NotificationEmitSignalData::class, $notices[0]->data->data);
        self::assertSame(UserNotificationType::PHOTO_REJECTED, $notices[0]->data->data->type);
        $this->assertSetRefused(ProfilePhotoRefusal::forReason('nudity'));

        $this->seedCompleteUpload('u2');
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u2'));
        $this->drainPending();
        $this->deliverVerdict(
            ModerationKindKey::PROFILE_PHOTO,
            $this->photoCheck()->requestId,
            false,
            ModerationReason::SERVICE_UNAVAILABLE,
        );
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        $this->assertCheckingFrames([false]);
        $this->assertSetRefused(self::PHOTO_UNAVAILABLE);
    }

    /**
     * With no moderator to answer, the deadline ends the wait unavailable, and a verdict arriving
     * after it changes nothing.
     *
     * @throws HilosException When the action or a frame fails
     */
    public function testTheDeadlineEndsAWaitNoModeratorAnswers(): void
    {
        ProfilePhotoModeratedTestHilos::initBrowser();
        ProfilePhotoModerationSettings::$timeoutSec = 0;
        $this->seedCompleteUpload('u1');
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u1'));
        $requestId = $this->photoCheck()->requestId;
        $this->drainPending();

        new ProfilePhotoCommands($this->library)->sweepChecks();
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationKindKey::PROFILE_PHOTO));
        $this->assertCheckingFrames([false]);
        $this->assertSetRefused(self::PHOTO_UNAVAILABLE);
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));

        $this->deliverVerdict(ModerationKindKey::PROFILE_PHOTO, $requestId, true, ModerationReason::OK);
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        $this->assertCheckingFrames([]);
    }

    /**
     * The verdict of an earlier attempt on the same upload, arriving after the upload was submitted
     * again, does not publish it: only the verdict of the attempt in force does.
     *
     * @throws HilosException When the action or a frame fails
     */
    public function testALateVerdictOfAnEarlierAttemptDoesNotPublish(): void
    {
        ProfilePhotoModeratedTestHilos::initBrowser();
        ProfilePhotoModerationSettings::$timeoutSec = 0;
        $this->seedCompleteUpload('u1');
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u1'));
        $earlier = $this->photoCheck()->requestId;
        new ProfilePhotoCommands($this->library)->sweepChecks();
        $this->drainPending();

        ProfilePhotoModerationSettings::$timeoutSec = ModerationSettingsCatalog::DEFAULT_CHECK_TIMEOUT_SEC;
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u1'));
        $current = $this->photoCheck()->requestId;
        self::assertNotSame($earlier, $current);
        $this->drainPending();

        $this->deliverVerdict(ModerationKindKey::PROFILE_PHOTO, $earlier, true, ModerationReason::OK);
        self::assertSame($current, $this->photoCheck()->requestId);
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        $this->assertCheckingFrames([]);

        $this->deliverVerdict(ModerationKindKey::PROFILE_PHOTO, $current, true, ModerationReason::OK);
        self::assertCount(1, $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        $this->assertCheckingFrames([false]);
    }

    /**
     * A connection that closed, or no longer carries the person who asked, loses its check without
     * a publication and without a word: on the verdict, and on the sweep.
     *
     * @throws HilosException When the action or a frame fails
     */
    public function testAClosedOrSignedOutConnectionDropsItsCheckWithoutPublishing(): void
    {
        ProfilePhotoModeratedTestHilos::initBrowser();
        $this->seedCompleteUpload('u1');
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u1'));
        $requestId = $this->photoCheck()->requestId;
        $this->seedCompleteUpload('u2', self::OTHER_ACCEPT_KEY);
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u2'), self::OTHER_ACCEPT_KEY);
        $this->drainPending();

        $connections = Hilos::$rt->connectionsSource() ?? self::fail('The fixture mounts its connections');
        $connections->remove(self::ACCEPT_KEY);
        $this->deliverVerdict(ModerationKindKey::PROFILE_PHOTO, $requestId, true, ModerationReason::OK);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationKindKey::PROFILE_PHOTO));

        $connections->remove(self::OTHER_ACCEPT_KEY);
        $connections->add(ProfileIntegrationConnection::create(self::OTHER_ACCEPT_KEY, null, self::OTHER_SESSION_TOKEN));
        new ProfilePhotoCommands($this->library)->sweepChecks();
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::OTHER_ACCEPT_KEY, ModerationKindKey::PROFILE_PHOTO));

        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        $this->assertCheckingFrames([]);
        self::assertSame([], $this->signalsNamed(SignalConstants::ACTION_ERROR));
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
    }

    /**
     * While this process holds a frozen copy of the connection, a verdict and the sweep decide
     * nothing before the deadline: the check stays, and nothing is published, refused, notified
     * or told to the tab. The same verdict settles it once the copy is current again.
     *
     * @throws HilosException When the action or a frame fails
     */
    public function testAFrozenConnectionCopyDecidesNothingBeforeTheDeadline(): void
    {
        ProfilePhotoModeratedTestHilos::initBrowser();
        $this->seedCompleteUpload('u1');
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u1'));
        $requestId = $this->photoCheck()->requestId;
        $this->drainPending();
        $this->freezeConnectionCopy();

        $this->deliverVerdict(ModerationKindKey::PROFILE_PHOTO, $requestId, true, ModerationReason::OK);
        $this->assertCheckUnsettled($requestId);
        $this->deliverVerdict(ModerationKindKey::PROFILE_PHOTO, $requestId, false, ProfilePhotoRefusal::REASON_NUDITY);
        $this->assertCheckUnsettled($requestId);
        new ProfilePhotoCommands($this->library)->sweepChecks();
        $this->assertCheckUnsettled($requestId);

        RtStaleness::clear(ProfileIntegrationConnections::RT_COLLECTION, [self::ACCEPT_KEY]);
        $this->deliverVerdict(ModerationKindKey::PROFILE_PHOTO, $requestId, true, ModerationReason::OK);
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationKindKey::PROFILE_PHOTO));
        $this->assertCheckingFrames([false]);
        self::assertCount(1, $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
    }

    /**
     * A frozen copy of the connection does not hold the wait past its deadline: the verdict and the
     * sweep each end it unavailable, in the words of a moderator that could not judge, and with no
     * notice of a refusal.
     *
     * @throws HilosException When the action or a frame fails
     */
    public function testTheDeadlineEndsTheWaitOnAFrozenConnectionCopyUnavailable(): void
    {
        ProfilePhotoModeratedTestHilos::initBrowser();
        ProfilePhotoModerationSettings::$timeoutSec = 0;
        $this->freezeConnectionCopy();

        $this->seedCompleteUpload('u1');
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u1'));
        $requestId = $this->photoCheck()->requestId;
        $this->drainPending();
        $this->deliverVerdict(ModerationKindKey::PROFILE_PHOTO, $requestId, true, ModerationReason::OK);
        $this->assertWaitEndedUnavailable();

        $this->seedCompleteUpload('u2');
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u2'));
        $this->drainPending();
        new ProfilePhotoCommands($this->library)->sweepChecks();
        $this->assertWaitEndedUnavailable();
    }

    /**
     * Removing the photo ends the photo check of this connection and leaves a check of another kind.
     *
     * @throws HilosException When the action or a frame fails
     */
    public function testRemovingThePhotoEndsThePhotoCheckAlone(): void
    {
        ProfilePhotoModeratedTestHilos::initBrowser();
        Hilos::$rt->hilosModerationChecks->actions->open(self::ACCEPT_KEY, self::USER_ID, ModerationKindKey::PROFILE_NAME, 'New name');
        $this->seedCompleteUpload('u1');
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u1'));
        $requestId = $this->photoCheck()->requestId;
        $this->drainPending();

        $this->submit(HilosSignalConstants::PROFILE_PHOTO_REMOVE, new ProfilePhotoRemoveActionDTO());
        $this->settleProfileFlows();
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationKindKey::PROFILE_PHOTO));
        self::assertNotNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationKindKey::PROFILE_NAME));
        $this->assertCheckingFrames([false]);

        $this->deliverVerdict(ModerationKindKey::PROFILE_PHOTO, $requestId, true, ModerationReason::OK);
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
    }

    /**
     * The library holds the checks of its own set alone: it cannot open a check whose verdict goes to
     * another agent, and a verdict of such a kind is not applied to the photo.
     *
     * @throws HilosException When the action or a frame fails
     */
    public function testAKindOfAnotherSetIsNeitherOpenedNorAppliedAsThePhoto(): void
    {
        ProfilePhotoModeratedTestHilos::initBrowser();
        try {
            Hilos::$rt->hilosModerationChecks->actions->open(self::ACCEPT_KEY, self::USER_ID, ProfilePhotoModerationKinds::NOTE, 'A note');
            self::fail('A check of another set must be refused to the people library');
        } catch (RtTruthSourceWriteNotAllowedException) {
            self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ProfilePhotoModerationKinds::NOTE));
        }

        $this->seedCompleteUpload('u1');
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u1'));
        $requestId = $this->photoCheck()->requestId;
        $this->drainPending();
        $this->deliverVerdict(ProfilePhotoModerationKinds::NOTE, $requestId, true, ModerationReason::OK);
        self::assertSame($requestId, $this->photoCheck()->requestId);
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
        $this->assertCheckingFrames([]);
    }

    /**
     * Removing a photo the person does not have acknowledges the action and refreshes nothing.
     *
     * @throws HilosException When the action or a frame fails
     */
    public function testRemovalWithoutAPhotoAcksAndDoesNotRestate(): void
    {
        $this->library->beginActionDispatch('photo-remove-none');
        try {
            $reply = $this->library->onAgentAction(
                self::ACCEPT_KEY,
                HilosSignalConstants::PROFILE_PHOTO_REMOVE,
                new ProfilePhotoRemoveActionDTO(),
            );
            self::assertNull($reply);
            self::assertTrue($this->library->actionReplyDeferred());
        } finally {
            $this->library->endActionDispatch();
        }

        $this->settleProfileFlows();
        self::assertArrayNotHasKey(self::USER_ID, Hilos::$db->userPhotos);
        $acks = $this->signalsNamed(SignalConstants::ACTION_SUCCESS);
        self::assertCount(1, $acks);
        self::assertInstanceOf(WebSocketSignalData::class, $acks[0]->data);
        self::assertInstanceOf(PageActionSuccessSignalData::class, $acks[0]->data->data);
        self::assertSame(HilosSignalConstants::PROFILE_PHOTO_REMOVE, $acks[0]->data->data->action);
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE));
    }

    /**
     * A photo the person's agent cannot write tells the tab and refreshes nothing.
     *
     * @throws HilosException When the file cannot be seeded or a frame fails
     */
    public function testAFailingAgentWriteFailsTheSetAndDoesNotRestate(): void
    {
        $this->putWriteFails = true;
        $this->seedFile(self::FIRST_FILE_ID);
        $this->deliverPublished('u1', self::FIRST_FILE_ID);
        $this->settleProfileFlows();

        self::assertArrayNotHasKey(self::USER_ID, Hilos::$db->userPhotos);
        $errors = $this->signalsNamed(SignalConstants::ACTION_ERROR);
        self::assertCount(1, $errors);
        self::assertInstanceOf(WebSocketSignalData::class, $errors[0]->data);
        self::assertInstanceOf(PageActionErrorSignalData::class, $errors[0]->data->data);
        self::assertSame(HilosSignalConstants::PROFILE_PHOTO_SET, $errors[0]->data->data->action);
        self::assertSame('The photo could not be kept', $errors[0]->data->data->reason);
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE));
    }

    /**
     * @throws HilosException When the file cannot be seeded or a frame fails
     */
    public function testAMissingFileOrOwnerFailsPublicationWithoutAskingThePerson(): void
    {
        $this->seedFile(self::SECOND_FILE_ID);
        Database::sqlRun('UPDATE `hilos_file` SET `owner_user_id` = NULL WHERE `id` = ?', [self::SECOND_FILE_ID]);
        self::assertArrayNotHasKey(self::FIRST_FILE_ID, Hilos::$db->files);
        self::assertArrayHasKey(self::SECOND_FILE_ID, Hilos::$db->files);
        self::assertNull(Hilos::$db->files[self::SECOND_FILE_ID]->ownerUserId);
        $this->drainSignals();

        foreach ([self::FIRST_FILE_ID, self::SECOND_FILE_ID] as $fileId) {
            $this->deliverPublished('unowned-photo', $fileId);

            self::assertArrayNotHasKey(self::USER_ID, Hilos::$db->userPhotos);
            $errors = $this->signalsNamed(SignalConstants::ACTION_ERROR);
            self::assertCount(1, $errors);
            self::assertInstanceOf(WebSocketSignalData::class, $errors[0]->data);
            self::assertInstanceOf(PageActionErrorSignalData::class, $errors[0]->data->data);
            self::assertSame(HilosSignalConstants::PROFILE_PHOTO_SET, $errors[0]->data->data->action);
            self::assertSame('Cannot keep the file', $errors[0]->data->data->reason);
            self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_USER_PHOTO_PUT));
            self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_FILE_BIND));
            self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE));
        }
    }

    /**
     * A removal already asked, then a new photo, leaves the new file on the row.
     *
     * @throws HilosException When a file cannot be seeded or a frame fails
     */
    public function testRemoveThenPutLeavesTheNewFile(): void
    {
        $this->seedFile(self::FIRST_FILE_ID);
        $this->deliverPublished('u1', self::FIRST_FILE_ID);
        $this->settleProfileFlows();
        $this->drainSignals();
        self::assertSame(self::FIRST_FILE_ID, Hilos::$db->userPhotos[self::USER_ID]->fileId);

        $this->seedFile(self::SECOND_FILE_ID);
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_REMOVE, new ProfilePhotoRemoveActionDTO());
        $this->deliverPublished('u2', self::SECOND_FILE_ID);
        $this->settleProfileFlows();

        self::assertSame(self::SECOND_FILE_ID, Hilos::$db->userPhotos[self::USER_ID]->fileId);
    }

    /**
     * The photo row answers the public address of its avatar variant, the one others are shown.
     *
     * @throws HilosException When the file cannot be seeded or a frame fails
     */
    public function testThePhotoRowAnswersTheAvatarVariantAddress(): void
    {
        $this->seedFile(self::FIRST_FILE_ID);
        $this->deliverPublished('u1', self::FIRST_FILE_ID);
        $this->settleProfileFlows();

        $photo = Hilos::$db->userPhotos[self::USER_ID]->photo;
        self::assertSame(HilosFiles::downloadPath(self::FIRST_FILE_ID, ProfilePhotoUploadTarget::VARIANT), $photo);
        self::assertStringContainsString('variant=' . ProfilePhotoUploadTarget::VARIANT, $photo);
    }

    /**
     * @param string $userId Person id as the agent address carries it
     * @return AbstractUserAgent The person's agent, refusing the photo write when this case asks it to
     */
    protected function newPersonAgent(string $userId): AbstractUserAgent
    {
        if ($this->putWriteFails) {
            return new ProfilePhotoPutFailsAgent($userId);
        }

        return new IntegrationUserAgent($userId);
    }

    /**
     * @param string $clientUploadId Browser upload id
     * @param string $acceptKey Connection the upload arrived on
     */
    private function seedCompleteUpload(string $clientUploadId, string $acceptKey = self::ACCEPT_KEY): void
    {
        $upload = Hilos::$rt->hilosUploads->actions->open(
            $acceptKey,
            $clientUploadId,
            ProfilePhotoUploadTarget::NAME,
            self::USER_ID,
            'photo.jpg',
            'image/jpeg',
            4,
            'tmp-photo',
        );
        $upload->actions->complete();
    }

    /** @param int $fileId Registry file id */
    private function seedFile(int $fileId): void
    {
        Database::sqlRun(
            'INSERT INTO `hilos_file` (`id`, `stored_name`, `filename`, `mime_type`, `size`, `content_hash`, '
            . '`owner_user_id`, `visibility`, `bound`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$fileId, "photo-{$fileId}.jpg", 'photo.jpg', 'image/jpeg', 4, str_repeat('a', 64), self::USER_ID, 'public', 0],
        );
    }

    /**
     * @param string $clientUploadId Browser upload id
     * @param int $fileId Published registry file id
     */
    private function deliverPublished(string $clientUploadId, int $fileId): void
    {
        $this->library->onSignalAgent(
            new AgentSignalData(new FilesPublishedSignalData(self::ACCEPT_KEY, [$clientUploadId], [$fileId], null)),
            'test',
            HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED,
        );
    }

    /** Marks this process's copy of the connection frozen, as a dropped link to its owner leaves it. */
    private function freezeConnectionCopy(): void
    {
        RtStaleness::mark(ProfileIntegrationConnections::RT_COLLECTION, [self::ACCEPT_KEY], microtime(true));
    }

    /**
     * Asserts the photo check still stands as it was and nobody was told or sent anything.
     *
     * @param string $requestId Attempt the connection still waits on
     */
    private function assertCheckUnsettled(string $requestId): void
    {
        self::assertSame($requestId, $this->photoCheck()->requestId);
        $this->assertCheckingFrames([]);
        self::assertSame([], $this->signalsNamed(SignalConstants::ACTION_ERROR));
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
    }

    /** Asserts the photo check ended unavailable: the tab told it no longer waits and why, nobody notified, nothing published. */
    private function assertWaitEndedUnavailable(): void
    {
        self::assertNull(Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationKindKey::PROFILE_PHOTO));
        $this->assertCheckingFrames([false]);
        $this->assertSetRefused(self::PHOTO_UNAVAILABLE);
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
    }

    /** @return HilosModerationCheck The photo check this connection waits on */
    private function photoCheck(): HilosModerationCheck
    {
        return Hilos::$rt->hilosModerationChecks->find(self::ACCEPT_KEY, ModerationKindKey::PROFILE_PHOTO)
            ?? self::fail('The connection waits on no photo check');
    }

    /**
     * Hands the people library a verdict the way the router delivers it to a kind's recipient.
     *
     * @param string $kind Kind the verdict names
     * @param string $requestId Attempt the verdict answers
     * @param bool $allow Whether the content passed
     * @param string $reason Reason code
     */
    private function deliverVerdict(string $kind, string $requestId, bool $allow, string $reason): void
    {
        $this->library->onSignalAgent(
            new AgentSignalData(new ModerationVerdictSignalData(self::ACCEPT_KEY, $kind, $requestId, $allow, $reason)),
            'test',
            HilosSignalConstants::HILOS_MODERATION_VERDICT,
        );
    }

    /**
     * Asserts the check frames queued since the last look, in order, all of the photo kind.
     *
     * @param list<bool> $checking The flag each frame carries
     */
    private function assertCheckingFrames(array $checking): void
    {
        $frames = $this->signalsNamed(HilosSignalConstants::HILOS_MODERATION_CHECK);
        self::assertSame($checking, array_map(static function (SignalDTO $frame): bool {
            self::assertInstanceOf(WebSocketSignalData::class, $frame->data);
            self::assertInstanceOf(ModerationCheckSignalData::class, $frame->data->data);
            self::assertSame(ModerationKindKey::PROFILE_PHOTO, $frame->data->data->kind);

            return $frame->data->data->checking;
        }, $frames));
    }

    /**
     * Asserts the one photo action failure queued since the last look.
     *
     * @param string $reason The words the tab is told
     */
    private function assertSetRefused(string $reason): void
    {
        $errors = $this->signalsNamed(SignalConstants::ACTION_ERROR);
        self::assertCount(1, $errors);
        self::assertInstanceOf(WebSocketSignalData::class, $errors[0]->data);
        self::assertInstanceOf(PageActionErrorSignalData::class, $errors[0]->data->data);
        self::assertSame(HilosSignalConstants::PROFILE_PHOTO_SET, $errors[0]->data->data->action);
        self::assertSame($reason, $errors[0]->data->data->reason);
    }

    /** Forgets every frame queued so far, so the next look sees only what follows. */
    private function drainPending(): void
    {
        $this->drainSignals();
        $this->pendingSignals = [];
    }

    /**
     * @param string $name Signal name to take from the queue
     * @return list<SignalDTO> Matching signals
     * @throws HilosException When a profile-flow signal cannot be delivered
     */
    private function signalsNamed(string $name): array
    {
        $this->pendingSignals = [...$this->pendingSignals, ...$this->drainSignals()];
        $matched = array_values(array_filter(
            $this->pendingSignals,
            static fn (SignalDTO $signal): bool => $signal->signalName->getName() === $name,
        ));
        $this->pendingSignals = array_values(array_filter(
            $this->pendingSignals,
            static fn (SignalDTO $signal): bool => $signal->signalName->getName() !== $name,
        ));

        return $matched;
    }

    /**
     * @param bool $down Drop the photo tables instead of creating them
     * @throws DatabaseException When a stub statement fails
     */
    private static function photoStubs(bool $down): void
    {
        $tables = $down ? ['hilos_user_photo', 'hilos_file'] : ['hilos_file', 'hilos_user_photo'];
        // external-boundary: the create stub has no suffix in its file name
        $suffix = $down ? '_down' : '';
        foreach ($tables as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }
}

/** Profile runtime extended with uploads and the moderation checks. */
final class ProfilePhotoIntegrationRtContext extends ProfileIntegrationRtContext
{
    public function configure(): void
    {
        parent::configure();
        new UploadsFeature()->mount($this);
        new ModerationFeature()->mount($this);
    }
}

/** The profile fixture's settings with the moderation settings folded in, as a moderating project folds them. */
final class ProfilePhotoModerationSettingsCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Fixture settings catalog
     */
    public static function getCatalog(): array
    {
        return array_replace(ProfileIntegrationSettingsCatalog::getCatalog(), ModerationSettingsCatalog::getCatalog());
    }
}

/** Settings whose check timeout the case scripts, as an administrator's saved row would. */
final class ProfilePhotoModerationSettings extends SettingsAccessor
{
    /** Seconds a whole check may take, as the case has set them; zero makes every new check due at once. */
    public static int $timeoutSec = ModerationSettingsCatalog::DEFAULT_CHECK_TIMEOUT_SEC;

    /**
     * @param string $key Setting key
     * @return mixed The scripted timeout, or the stored value of any other key
     */
    public function effectiveValueFor(string $key): mixed
    {
        return $key === ModerationSettingsCatalog::CHECK_TIMEOUT_SEC ? self::$timeoutSec : parent::effectiveValueFor($key);
    }
}

/** The framework kinds, and one kind whose verdict goes to an agent other than the people library. */
final class ProfilePhotoModerationKinds extends ModerationKindDirectory
{
    public const string NOTE = 'fixture_note';

    /** @return list<ModerationKind> Framework kinds and the note */
    protected static function kinds(): array
    {
        return [
            ...parent::kinds(),
            new ModerationKind(self::NOTE, 'Note', ModerationInput::TEXT, ['spam'], ['Block spam.'], 'fixture_note_agent'),
        ];
    }
}

/** Photo feature with publication immediately after the upload completes. */
class ProfilePhotoImmediateTestHilos extends Hilos
{
    protected const array FEATURES = [
        HilosFeature::AUTH,
        HilosFeature::FILES,
        HilosFeature::UPLOADS,
        HilosFeature::IMAGES,
        HilosFeature::PROFILE_PHOTO,
    ];

    /** @return HilosDbContext Framework collections used by this fixture */
    protected static function createDb(): HilosDbContext
    {
        return new HilosSessionTestDbContext();
    }
}

/** Photo feature checked by the framework moderator, which the project runs as a moderating project does. */
final class ProfilePhotoModeratedTestHilos extends ProfilePhotoImmediateTestHilos
{
    protected const array FEATURES = [
        HilosFeature::AUTH,
        HilosFeature::FILES,
        HilosFeature::UPLOADS,
        HilosFeature::IMAGES,
        HilosFeature::PROFILE_PHOTO,
        HilosFeature::MODERATION,
    ];

    public const array AGENTS = [
        ModerationAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ModerationAgent::class,
            AgentRegistryKey::DAEMON => ModerationAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
    ];

    protected const string MODERATION_KIND_DIRECTORY = ProfilePhotoModerationKinds::class;
}

/** Person's agent whose photo write fails, so no row is kept and no session is refreshed. */
final class ProfilePhotoPutFailsAgent extends AbstractUserAgent
{
    /**
     * Refuses the photo write, so the case can see the tab told and no refresh sent.
     *
     * @param int $fileId Registry file the photo would have been set from
     * @return ?int Never returns
     * @throws ValidationException Always: the write is the failure under test
     */
    protected function putPhoto(int $fileId): ?int
    {
        throw new ValidationException('The photo could not be kept');
    }
}
