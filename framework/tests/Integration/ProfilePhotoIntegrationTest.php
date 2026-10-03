<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Auth\Library\Command\ProfilePhotoCommands;
use Hilos\Auth\Library\DTO\ProfilePhotoRemoveActionDTO;
use Hilos\Auth\Library\DTO\ProfilePhotoSetActionDTO;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Feature\Definition\ProfilePhotoFeature;
use Hilos\Core\Feature\Definition\UploadsFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Schema\Schema;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\HilosFiles;
use Hilos\Files\Storage\LocalFilesStorage;
use Hilos\Files\Upload\DTO\UploadPublishSignalData;
use Hilos\Files\Upload\ProfilePhotoUploadTarget;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Notification\DTO\NotificationEmitSignalData;
use Hilos\Notification\HilosNotifier;
use Hilos\Runtime\State\Item\HilosProfilePhotoCheck as StateHilosProfilePhotoCheck;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Users\DTO\ProfilePhotoCheckSignalData;
use Hilos\Users\DTO\ProfilePhotoVerdictSignalData;
use Hilos\Users\ProfilePhotoRefusal;
use Hilos\Users\UserNotificationType;

/** The user's photo action through its RT check, publication and durable row. */
final class ProfilePhotoIntegrationTest extends ProfileIntegrationTestCase
{
    private const int FIRST_FILE_ID = 9101;
    private const int SECOND_FILE_ID = 9102;

    private string $previousAppClass;
    private ?HilosFiles $previousFiles;
    private ?HilosNotifier $previousNotify;

    /** @var list<SignalDTO> Queued frames the previous assertion did not consume */
    private array $pendingSignals = [];

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
        ProfilePhotoImmediateTestHilos::initBrowser();
        RtTruthSourceRegistry::registerDaemon(StateHilosUpload::RT_COLLECTION);
        RtTruthSourceRegistry::registerDaemon(StateHilosProfilePhotoCheck::RT_COLLECTION);
    }

    /**
     * @throws DatabaseException When dropping fixture tables fails
     */
    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(StateHilosProfilePhotoCheck::RT_COLLECTION);
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
        self::assertSame(self::FIRST_FILE_ID, Hilos::$db->userPhotos[self::USER_ID]?->fileId);
        self::assertCount(1, $this->signalsNamed(HilosSignalConstants::HILOS_FILE_BIND));
        $restate = $this->signalsNamed(HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE);
        self::assertCount(1, $restate);
        self::assertInstanceOf(AgentSignalData::class, $restate[0]->data);
        $this->holder->onSignalAgent($restate[0]->data, 'test', HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE);
        self::assertCount(2, $this->signalsNamed(HilosSignalConstants::HILOS_SESSION_STATE));

        $this->seedFile(self::SECOND_FILE_ID);
        $this->deliverPublished('u2', self::SECOND_FILE_ID);
        self::assertSame(self::SECOND_FILE_ID, Hilos::$db->userPhotos[self::USER_ID]?->fileId);
        self::assertCount(1, $this->signalsNamed(HilosSignalConstants::HILOS_FILE_REMOVE));
        self::assertCount(1, $this->signalsNamed(HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE));

        $this->submit(HilosSignalConstants::PROFILE_PHOTO_REMOVE, new ProfilePhotoRemoveActionDTO());
        self::assertNull(Hilos::$db->userPhotos[self::USER_ID]);
        self::assertCount(1, $this->signalsNamed(HilosSignalConstants::HILOS_FILE_REMOVE));
        self::assertCount(1, $this->signalsNamed(HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE));
    }

    /** A check blocks a second submit and an approval alone releases the upload to publication. */
    public function testApprovalWaitsForTheMatchingVerdict(): void
    {
        ProfilePhotoCheckedTestHilos::initBrowser();
        $this->seedCompleteUpload('u1');
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u1'));
        self::assertSame('u1', Hilos::$rt->hilosProfilePhotoChecks[self::ACCEPT_KEY]?->clientUploadId);
        self::assertNull(Hilos::$db->userPhotos[self::USER_ID]);
        self::assertCount(1, $this->signalsNamed(HilosSignalConstants::HILOS_PROFILE_PHOTO_CHECK));

        try {
            $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u2'));
            self::fail('A second check on this connection must be refused');
        } catch (ValidationException $refusal) {
            self::assertSame(ProfilePhotoRefusal::STILL_CHECKING, $refusal->getMessage());
        }
        $this->library->onSignalAgent(
            new AgentSignalData(new ProfilePhotoVerdictSignalData(self::ACCEPT_KEY, 'other', true, 'ok')),
            'test',
            HilosSignalConstants::HILOS_PROFILE_PHOTO_VERDICT,
        );
        self::assertNotNull(Hilos::$rt->hilosProfilePhotoChecks[self::ACCEPT_KEY]);
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));

        $this->library->onSignalAgent(
            new AgentSignalData(new ProfilePhotoVerdictSignalData(self::ACCEPT_KEY, 'u1', true, 'ok')),
            'test',
            HilosSignalConstants::HILOS_PROFILE_PHOTO_VERDICT,
        );
        self::assertNull(Hilos::$rt->hilosProfilePhotoChecks[self::ACCEPT_KEY]);
        self::assertCount(1, $this->signalsNamed(HilosSignalConstants::HILOS_UPLOAD_PUBLISH));
    }

    /** Content rejection notifies; an unavailable checker only answers the submitting tab. */
    public function testRejectionAndUnavailableChecker(): void
    {
        ProfilePhotoCheckedTestHilos::initBrowser();
        $this->seedCompleteUpload('u1');
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u1'));
        $this->drainSignals();
        $this->library->onSignalAgent(
            new AgentSignalData(new ProfilePhotoVerdictSignalData(self::ACCEPT_KEY, 'u1', false, 'nudity')),
            'test',
            HilosSignalConstants::HILOS_PROFILE_PHOTO_VERDICT,
        );
        self::assertNull(Hilos::$db->userPhotos[self::USER_ID]);
        $notices = $this->signalsNamed(HilosSignalConstants::HILOS_NOTIFICATION_EMIT);
        self::assertCount(1, $notices);
        self::assertInstanceOf(AgentSignalData::class, $notices[0]->data);
        self::assertInstanceOf(NotificationEmitSignalData::class, $notices[0]->data->data);
        self::assertSame(UserNotificationType::PHOTO_REJECTED, $notices[0]->data->data->type);
        $errors = $this->signalsNamed(SignalConstants::ACTION_ERROR);
        self::assertCount(1, $errors);
        self::assertInstanceOf(WebSocketSignalData::class, $errors[0]->data);
        self::assertSame(ProfilePhotoRefusal::forReason('nudity'), $errors[0]->data->data->reason);

        $this->seedCompleteUpload('u2');
        $this->submit(HilosSignalConstants::PROFILE_PHOTO_SET, new ProfilePhotoSetActionDTO('u2'));
        $this->drainSignals();
        $this->library->onSignalAgent(
            new AgentSignalData(new ProfilePhotoVerdictSignalData(self::ACCEPT_KEY, 'u2', false, 'service_unavailable')),
            'test',
            HilosSignalConstants::HILOS_PROFILE_PHOTO_VERDICT,
        );
        self::assertSame([], $this->signalsNamed(HilosSignalConstants::HILOS_NOTIFICATION_EMIT));
        $unavailable = $this->signalsNamed(SignalConstants::ACTION_ERROR);
        self::assertCount(1, $unavailable);
        self::assertSame(ProfilePhotoRefusal::forReason('service_unavailable'), $unavailable[0]->data->data->reason);
    }

    /** A check on a connection that has gone is removed without publishing. */
    public function testClosedConnectionDropsItsCheck(): void
    {
        Hilos::$rt->hilosProfilePhotoChecks->actions->open('gone', self::USER_ID, 'u1');
        new ProfilePhotoCommands($this->library)->sweepClosedConnections();

        self::assertNull(Hilos::$rt->hilosProfilePhotoChecks['gone']);
    }

    /**
     * @param string $clientUploadId Browser upload id
     */
    private function seedCompleteUpload(string $clientUploadId): void
    {
        $upload = Hilos::$rt->hilosUploads->actions->open(
            self::ACCEPT_KEY,
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

/** Profile runtime extended with uploads and the pending photo check. */
final class ProfilePhotoIntegrationRtContext extends ProfileIntegrationRtContext
{
    public function configure(): void
    {
        parent::configure();
        new UploadsFeature()->mount($this);
        new ProfilePhotoFeature()->mount($this);
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

/** Photo feature with a project checker. */
final class ProfilePhotoCheckedTestHilos extends ProfilePhotoImmediateTestHilos
{
    public const ?string PROFILE_PHOTO_CHECKER = 'fixture_photo_checker';
}
