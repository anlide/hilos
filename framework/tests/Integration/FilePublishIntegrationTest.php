<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\Exception\FeatureNotDeclaredException;
use Hilos\Core\Feature\Definition\UploadsFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Files\DTO\FilePublishSignalData;
use Hilos\Files\DTO\FilesPublishedSignalData;
use Hilos\Files\FilesSettingsCatalog;
use Hilos\Files\FileVisibility;
use Hilos\Files\HilosFiles;
use Hilos\Files\Library\AbstractFilesLibraryAgent;
use Hilos\Files\Storage\LocalFilesStorage;
use Hilos\Files\Upload\AbstractUploadTarget;
use Hilos\Files\Upload\Check\DuplicateContentCheck;
use Hilos\Files\Upload\DTO\UploadInitActionDTO;
use Hilos\Files\Upload\DTO\UploadPublishSignalData;
use Hilos\Files\Upload\DTO\UploadStateSignalData;
use Hilos\Files\Upload\UploadCheckInterface;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Fs\Context\FsContext;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosUpload;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use ReflectionProperty;

/**
 * A publication end to end: the uploads agent hands complete uploads over, the files library keeps
 * and registers them, and the asker hears the answer under its own name (HIL-136).
 *
 * Both agents are driven directly, as their own cases drive them: the request is handed to the
 * uploads agent's onSignalAgent(), the frame it queues for the library is taken off the queue and
 * handed to the library's, and the answer is read off the queue. The registry is the real
 * hilos_file table, the tmp and files directories are real directories, and each agent writes
 * under its own identity, so a write by the wrong one is refused as it would be in production.
 */
final class FilePublishIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Framework tables the cases raise, in dependency order. */
    private const array TABLES = ['hilos_setting', 'hilos_file'];

    private const string GUEST = 'ak-publish-guest';

    private const string SIGNED_IN = FilePublishTestKeys::SIGNED_IN;

    private const int SIGNED_IN_USER = FilePublishTestKeys::SIGNED_IN_USER;

    /** A second signed-in person, on a connection of their own. */
    private const string OTHER = FilePublishTestKeys::OTHER;

    /** Name the asker gives its answer. */
    private const string REPLY = 'gallery_published';

    /** A 1x1 PNG: the smallest content libmagic names image/png. */
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private ?DbContext $previousDb = null;

    private ?SettingsAccessor $previousSetting = null;

    private ?FsContext $previousFs = null;

    private ?HilosFiles $previousFiles = null;

    private ?RtContext $previousRt = null;

    private ?SignalRouter $previousSignalRouter = null;

    private ?BrowserContext $previousBrowser = null;

    private string $previousAppClass = Hilos::class;

    private string $tmpPath = '';

    private string $filesPath = '';

    private UploadsAgent $uploads;

    private FilePublishTestLibrary $library;

    /** @var list<array{0: ?string, 1: string}> Connection and upload id of every gone frame seen */
    private array $goneFrames = [];

    /** Whether the library was handed a frame by the last publication. */
    private bool $libraryAsked = false;

    /**
     * @throws DatabaseException When a stub statement or the schema reset fails
     * @throws HilosException When a context cannot be configured or an agent cannot start
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);
        Schema::reset();
        Schema::initialize();

        $this->previousDb = Hilos::$db;
        $this->previousSetting = Hilos::$setting;
        $this->previousFs = Hilos::$fs;
        $this->previousFiles = Hilos::$files;
        $this->previousRt = Hilos::$rt;
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousBrowser = Hilos::$browser;
        $this->previousAppClass = Hilos::appClass();

        Hilos::$db = new FilePublishTestDbContext();
        Hilos::$db->configure();
        Hilos::$setting = new SettingsAccessor(FilesSettingsCatalog::class);
        $base = sys_get_temp_dir() . '/hilos-publish-' . bin2hex(random_bytes(6));
        $this->tmpPath = $base . '-tmp';
        $this->filesPath = $base . '-files';
        Hilos::$fs = new FilePublishTestFsContext($this->tmpPath, $this->filesPath);
        Hilos::$fs->configure();
        Hilos::$files = new HilosFiles(new LocalFilesStorage());
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new FilePublishTestBrowserContext();
        self::bindAppClass(FilePublishTestHilos::class);
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());

        $rt = new FilePublishTestRtContext();
        $rt->mountFeatureRuntime([new UploadsFeature()]);
        $rt->configure();
        $rt->bindStateCollectionNames();
        Hilos::$rt = $rt;
        foreach ([self::GUEST, self::SIGNED_IN, self::OTHER] as $acceptKey) {
            Hilos::$rt->connectionsSource()?->add(FilePublishTestConnection::create($acceptKey, null));
        }

        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_FILES_LIBRARY);
        $this->library = new FilePublishTestLibrary();
        OwnershipDeclaration::claimAll($this->library);
        $this->library->onStart();

        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_UPLOADS);
        $this->uploads = new UploadsAgent();
        OwnershipDeclaration::claimAll($this->uploads);
        $this->uploads->onStart();
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregister(StateHilosUpload::RT_COLLECTION, HilosAgentType::HILOS_UPLOADS);
        TruthSourceRegistry::unregisterAgent(HilosAgentType::HILOS_FILES_LIBRARY);
        ExecutionContext::clear();
        SourceChangeBus::reset();

        foreach ([$this->tmpPath, $this->filesPath] as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        self::bindAppClass($this->previousAppClass);
        Hilos::$browser = $this->previousBrowser;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$rt = $this->previousRt;
        Hilos::$files = $this->previousFiles;
        Hilos::$fs = $this->previousFs;
        Hilos::$setting = $this->previousSetting;
        Hilos::$db = $this->previousDb;
        self::runStubs(down: true);
        Schema::reset();

        parent::tearDown();
    }

    public function testACompleteUploadBecomesAnUnboundRowWithItsFileMovedIntoTheStorage(): void
    {
        $this->complete(self::SIGNED_IN, 'u1', 'hello');
        $tmp = $this->tmpFile(self::SIGNED_IN, 'u1');

        $answer = $this->publish(self::SIGNED_IN, ['u1'], visibility: FileVisibility::OWNER);

        self::assertNull($answer->error);
        self::assertSame(self::SIGNED_IN, $answer->getAcceptKey());
        self::assertSame(['u1'], $answer->clientUploadIds);
        self::assertCount(1, $answer->fileIds);
        $row = self::row($answer->fileIds[0]);
        self::assertSame(0, (int)$row['bound']);
        self::assertSame('u1.txt', $row['filename']);
        self::assertSame('text/plain', $row['mime_type']);
        self::assertSame(5, (int)$row['size']);
        self::assertSame(hash('sha256', 'hello'), $row['content_hash']);
        self::assertSame(self::SIGNED_IN_USER, (int)$row['owner_user_id']);
        self::assertSame(FileVisibility::OWNER->value, $row['visibility']);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\.txt\z/', (string)$row['stored_name']);
        self::assertSame('hello', file_get_contents($this->filesPath . '/' . $row['stored_name']));
        self::assertFileDoesNotExist($tmp);
        self::assertNull(Hilos::$rt->hilosUploads->find(self::SIGNED_IN, 'u1'));
        self::assertSame([[self::SIGNED_IN, 'u1']], $this->goneFrames);
    }

    public function testATargetThatReadsTheContentRegistersTheTypeItRead(): void
    {
        $png = (string)base64_decode(self::PNG_BASE64, true);
        $this->complete(self::SIGNED_IN, 'u1', $png, target: FilePublishTestHilos::SNIFFING, mimeType: 'application/octet-stream');

        $answer = $this->publish(self::SIGNED_IN, ['u1'], target: FilePublishTestHilos::SNIFFING);

        self::assertNull($answer->error);
        $row = self::row($answer->fileIds[0]);
        self::assertSame('image/png', $row['mime_type']);
        self::assertStringEndsWith('.png', (string)$row['stored_name']);
        self::assertSame(hash('sha256', $png), $row['content_hash']);
    }

    public function testSeveralUploadsArePublishedTogetherInTheOrderTheyAreNamed(): void
    {
        $this->complete(self::SIGNED_IN, 'u1', 'first');
        $this->complete(self::SIGNED_IN, 'u2', 'second');

        $answer = $this->publish(self::SIGNED_IN, ['u2', 'u1']);

        self::assertNull($answer->error);
        self::assertSame(['u2', 'u1'], $answer->clientUploadIds);
        self::assertCount(2, $answer->fileIds);
        self::assertSame('u2.txt', self::row($answer->fileIds[0])['filename']);
        self::assertSame('u1.txt', self::row($answer->fileIds[1])['filename']);
        self::assertSame([[self::SIGNED_IN, 'u2'], [self::SIGNED_IN, 'u1']], $this->goneFrames);
    }

    public function testAnUploadThatIsNotThereIsRefusedAsGone(): void
    {
        $this->complete(self::SIGNED_IN, 'u1', 'kept');

        $this->assertRefusedAndUntouched('This file is gone; upload it again', self::SIGNED_IN, ['u1', 'nope'], ['u1']);
    }

    public function testAFailedUploadIsRefusedAsGone(): void
    {
        $this->declare(self::SIGNED_IN, 'u1', size: 2);
        $this->chunk(self::SIGNED_IN, 'u1', 'too long');
        self::assertSame(UploadPhase::FAILED, Hilos::$rt->hilosUploads->find(self::SIGNED_IN, 'u1')?->phase);

        $this->assertRefusedAndUntouched('This file is gone; upload it again', self::SIGNED_IN, ['u1'], []);
    }

    public function testAnUploadStillArrivingIsRefused(): void
    {
        $this->complete(self::SIGNED_IN, 'u1', 'done');
        $this->declare(self::SIGNED_IN, 'u2', size: 4);
        $this->chunk(self::SIGNED_IN, 'u2', 'ha');

        $this->assertRefusedAndUntouched('This file has not finished uploading', self::SIGNED_IN, ['u1', 'u2'], ['u1', 'u2']);
        self::assertSame(UploadPhase::UPLOADING, Hilos::$rt->hilosUploads->find(self::SIGNED_IN, 'u2')?->phase);
    }

    public function testAnUploadOfAnotherTargetIsRefused(): void
    {
        $this->complete(self::SIGNED_IN, 'u1', 'plain');

        $this->assertRefusedAndUntouched(
            'This file was uploaded for something else',
            self::SIGNED_IN,
            ['u1'],
            ['u1'],
            target: FilePublishTestHilos::SNIFFING,
        );
    }

    public function testAGuestUploadIsRefused(): void
    {
        $this->complete(self::GUEST, 'u1', 'anonymous');

        $this->assertRefusedAndUntouched('Sign in to keep this file', self::GUEST, ['u1'], ['u1']);
    }

    public function testAProjectThatKeepsNoFilesIsRefused(): void
    {
        $this->complete(self::SIGNED_IN, 'u1', 'nowhere');
        self::bindAppClass(FilePublishUploadsOnlyTestHilos::class);

        $this->assertRefusedAndUntouched('Files are not kept here', self::SIGNED_IN, ['u1'], ['u1']);
    }

    public function testAFailureOnTheSecondFileUndoesTheWholeRequest(): void
    {
        $this->complete(self::SIGNED_IN, 'u1', 'first');
        $this->complete(self::SIGNED_IN, 'u2', 'second');
        $this->complete(self::SIGNED_IN, 'u3', 'third');
        $tmp = [
            $this->tmpFile(self::SIGNED_IN, 'u1'),
            $this->tmpFile(self::SIGNED_IN, 'u2'),
            $this->tmpFile(self::SIGNED_IN, 'u3'),
        ];
        unlink($tmp[1]);

        $answer = $this->publish(self::SIGNED_IN, ['u1', 'u2', 'u3']);

        self::assertSame('Cannot keep the file', $answer->error);
        self::assertSame([], $answer->fileIds);
        self::assertSame(0, self::rowCount());
        self::assertSame([], glob($this->filesPath . '/*'));
        self::assertSame([], glob($this->tmpPath . '/*'), 'No temporary file is left behind');
        self::assertCount(1, $this->library->errors);
        self::assertStringStartsWith('Cannot publish upload u2 of ' . self::SIGNED_IN . ': ', $this->library->errors[0]);
    }

    public function testWithoutALimitTheStorageTakesAnything(): void
    {
        $this->complete(self::SIGNED_IN, 'u1', str_repeat('x', 1000));
        $this->publish(self::SIGNED_IN, ['u1']);

        $this->declare(self::SIGNED_IN, 'u2', size: 1000);

        self::assertNotNull(Hilos::$rt->hilosUploads->find(self::SIGNED_IN, 'u2'));
    }

    public function testTheLimitCountsTheRegistryAndEveryUploadHoldingAFile(): void
    {
        Hilos::$setting = new SettingsAccessor(FilePublishLimitedSettingsCatalog::class);
        $this->complete(self::SIGNED_IN, 'u1', str_repeat('a', 8));
        $this->publish(self::SIGNED_IN, ['u1']);
        $this->complete(self::OTHER, 'u1', str_repeat('b', 5));
        $this->declare(self::GUEST, 'u1', size: 2);
        $this->declare(self::GUEST, 'u2', size: 3);
        $this->chunk(self::GUEST, 'u2', 'too long');
        self::assertSame(UploadPhase::FAILED, Hilos::$rt->hilosUploads->find(self::GUEST, 'u2')?->phase, 'A failed upload holds nothing');

        $this->declare(self::SIGNED_IN, 'u2', size: FilePublishLimitedSettingsCatalog::LIMIT - 8 - 5 - 2);

        try {
            $this->declare(self::SIGNED_IN, 'u3', size: 1);
            self::fail('One byte past the limit is refused');
        } catch (ValidationException $refused) {
            self::assertSame('Storage limit would be exceeded', $refused->getMessage());
        }
        self::assertNull(Hilos::$rt->hilosUploads->find(self::SIGNED_IN, 'u3'));
    }

    public function testTheSamePersonCannotUploadAFileTheyAlreadyPublished(): void
    {
        $this->complete(self::SIGNED_IN, 'u1', 'same bytes');
        $this->publish(self::SIGNED_IN, ['u1']);

        $this->declare(self::SIGNED_IN, 'u2', FilePublishTestHilos::DEDUP, size: strlen('same bytes'));
        $this->chunk(self::SIGNED_IN, 'u2', 'same bytes');

        $this->assertFailedAsDuplicate(self::SIGNED_IN, 'u2');
    }

    public function testTheSamePersonCannotUploadWhatAnotherOfTheirUploadsHolds(): void
    {
        $this->complete(self::SIGNED_IN, 'u1', 'same bytes');

        $this->declare(self::SIGNED_IN, 'u2', FilePublishTestHilos::DEDUP, size: strlen('same bytes'));
        $this->chunk(self::SIGNED_IN, 'u2', 'same bytes');

        $this->assertFailedAsDuplicate(self::SIGNED_IN, 'u2');
    }

    public function testAnotherPersonAndAGuestMayUploadTheSameFile(): void
    {
        $this->complete(self::SIGNED_IN, 'u1', 'same bytes');
        $this->publish(self::SIGNED_IN, ['u1']);
        $this->complete(self::SIGNED_IN, 'u2', 'same bytes');

        $this->complete(self::OTHER, 'u1', 'same bytes', FilePublishTestHilos::DEDUP);
        $this->complete(self::GUEST, 'u1', 'same bytes', FilePublishTestHilos::DEDUP);
    }

    public function testTheDuplicateCheckIsOnlyForAProjectThatKeepsFiles(): void
    {
        self::bindAppClass(FilePublishUploadsOnlyTestHilos::class);

        $this->expectException(FeatureNotDeclaredException::class);
        $this->expectExceptionMessage('HilosFeature::FILES');

        new UploadsAgent()->onStart();
    }

    /**
     * @param string $acceptKey Connection
     * @param string $clientUploadId Upload id
     */
    private function assertFailedAsDuplicate(string $acceptKey, string $clientUploadId): void
    {
        $upload = Hilos::$rt->hilosUploads->find($acceptKey, $clientUploadId);
        self::assertInstanceOf(HilosUpload::class, $upload);
        self::assertSame(UploadPhase::FAILED, $upload->phase);
        self::assertSame(DuplicateContentCheck::CODE, $upload->errorCode);
        self::assertSame('This file is already uploaded', $upload->errorMessage);
    }

    /**
     * Asks for a publication and asserts it is refused before anything moved.
     *
     * @param string $message Sentence the answer is expected to carry
     * @param string $acceptKey Connection the uploads belong to
     * @param list<string> $clientUploadIds Uploads to publish
     * @param list<string> $untouched Uploads expected to still hold their temporary files
     * @param string $target Target to publish for
     * @throws HilosException When an agent fails to handle a frame
     */
    private function assertRefusedAndUntouched(
        string $message,
        string $acceptKey,
        array $clientUploadIds,
        array $untouched,
        string $target = FilePublishTestHilos::OPEN,
    ): void {
        $tmp = array_map(fn(string $clientUploadId): string => $this->tmpFile($acceptKey, $clientUploadId), $untouched);

        $answer = $this->publish($acceptKey, $clientUploadIds, target: $target);

        self::assertSame($message, $answer->error);
        self::assertSame([], $answer->fileIds);
        self::assertSame($clientUploadIds, $answer->clientUploadIds);
        self::assertFalse($this->libraryAsked, 'Nothing reaches the library');
        self::assertSame([], $this->goneFrames, 'No upload is dropped');
        self::assertSame(0, self::rowCount());
        foreach ($tmp as $path) {
            self::assertFileExists($path);
        }
    }

    /**
     * Declares one upload and sends its whole content in one chunk.
     *
     * @param string $acceptKey Connection sending
     * @param string $clientUploadId Upload id
     * @param string $bytes Whole content
     * @param string $target Target name
     * @param string $mimeType Declared type
     * @throws HilosException When the declaration is refused or a chunk cannot be written
     */
    private function complete(
        string $acceptKey,
        string $clientUploadId,
        string $bytes,
        string $target = FilePublishTestHilos::OPEN,
        string $mimeType = 'text/plain',
    ): void {
        $this->declare($acceptKey, $clientUploadId, $target, $mimeType, strlen($bytes));
        $this->chunk($acceptKey, $clientUploadId, $bytes);
        self::assertSame(UploadPhase::COMPLETE, Hilos::$rt->hilosUploads->find($acceptKey, $clientUploadId)?->phase);
    }

    /**
     * @param string $acceptKey Connection declaring
     * @param string $clientUploadId Upload id
     * @param string $target Target name
     * @param string $mimeType Declared type
     * @param int $size Declared size
     * @throws HilosException When the declaration is refused
     */
    private function declare(
        string $acceptKey,
        string $clientUploadId,
        string $target = FilePublishTestHilos::OPEN,
        string $mimeType = 'text/plain',
        int $size = 1,
    ): void {
        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_UPLOADS);
        $this->uploads->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_UPLOAD_INIT,
            new UploadInitActionDTO($target, $clientUploadId, $clientUploadId . '.txt', $mimeType, $size),
        );
        $this->drain();
    }

    /**
     * @param string $acceptKey Connection sending
     * @param string $clientUploadId Upload the chunk is signed with
     * @param string $bytes Chunk bytes
     * @throws HilosException When the chunk cannot be written
     */
    private function chunk(string $acceptKey, string $clientUploadId, string $bytes): void
    {
        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_UPLOADS);
        $this->uploads->onSignalFrameBinary(
            new WebSocketFrameBinarySignalDTO($acceptKey, chr(strlen($clientUploadId)) . $clientUploadId . $bytes),
            SignalSource::WEBSOCKET,
            '',
        );
        $this->drain();
    }

    /**
     * Hands a publication request to the uploads agent and carries every frame it causes to its
     * destination, until the answer comes back.
     *
     * @param string $acceptKey Connection the uploads belong to
     * @param list<string> $clientUploadIds Uploads to publish
     * @param string $target Target to publish for
     * @param FileVisibility $visibility Who may be given the files
     * @return FilesPublishedSignalData The answer under the asker's name
     * @throws HilosException When an agent fails to handle a frame
     */
    private function publish(
        string $acceptKey,
        array $clientUploadIds,
        string $target = FilePublishTestHilos::OPEN,
        FileVisibility $visibility = FileVisibility::AUTHENTICATED,
    ): FilesPublishedSignalData {
        $this->goneFrames = [];
        $this->libraryAsked = false;

        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_UPLOADS);
        $this->uploads->onSignalAgent(
            new AgentSignalData(new UploadPublishSignalData($acceptKey, $target, $clientUploadIds, $visibility->value, self::REPLY)),
            '',
            HilosSignalConstants::HILOS_UPLOAD_PUBLISH,
        );

        $answer = null;
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            $name = $signal->signalName->getName();
            if ($name === HilosSignalConstants::HILOS_UPLOAD_STATE
                && $signal->data instanceof WebSocketSignalData
                && $signal->data->data instanceof UploadStateSignalData
                && $signal->data->data->phase === null) {
                $this->goneFrames[] = [$signal->data->targetAcceptKey, $signal->data->data->clientUploadId];
            } elseif ($name === HilosSignalConstants::HILOS_FILE_PUBLISH && $signal->data instanceof AgentSignalData) {
                self::assertInstanceOf(FilePublishSignalData::class, $signal->data->data);
                $this->libraryAsked = true;
                ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_FILES_LIBRARY);
                $this->library->onSignalAgent($signal->data, '', HilosSignalConstants::HILOS_FILE_PUBLISH);
                ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_UPLOADS);
            } elseif ($name === self::REPLY && $signal->data instanceof AgentSignalData) {
                self::assertNull($answer, 'The asker is answered once');
                self::assertInstanceOf(FilesPublishedSignalData::class, $signal->data->data);
                $answer = $signal->data->data;
            }
        }

        self::assertNotNull($answer, 'The asker is answered');

        return $answer;
    }

    /**
     * Empties the queue of the frames a declaration or a chunk sent to the browser.
     */
    private function drain(): void
    {
        while (Hilos::$sr?->getNextQueuedSignal() instanceof SignalDTO) {
            continue;
        }
    }

    /**
     * @param string $acceptKey Connection
     * @param string $clientUploadId Upload id
     * @return string Path of the upload's temporary file
     */
    private function tmpFile(string $acceptKey, string $clientUploadId): string
    {
        $upload = Hilos::$rt->hilosUploads->find($acceptKey, $clientUploadId);
        self::assertInstanceOf(HilosUpload::class, $upload);
        self::assertNotNull($upload->tmpIndex);

        return $this->tmpPath . '/' . $upload->tmpIndex;
    }

    /**
     * @param int $id Row id
     * @return array<string, mixed> The registry row
     * @throws DatabaseException When the query fails
     */
    private static function row(int $id): array
    {
        $row = Database::sql('SELECT * FROM `hilos_file` WHERE `id` = ?', [$id])->firstRow();
        self::assertNotNull($row, "Row {$id} is in the registry");

        return $row;
    }

    /**
     * @return int Rows in the registry
     * @throws DatabaseException When the query fails
     */
    private static function rowCount(): int
    {
        return (int)(Database::sql('SELECT COUNT(*) AS `count` FROM `hilos_file`')->firstRow()['count'] ?? -1);
    }

    /**
     * Creates or drops the tables the cases read.
     *
     * @param bool $down Whether to run the teardown half of each stub
     * @throws DatabaseException When a stub statement fails
     */
    private static function runStubs(bool $down): void
    {
        // external-boundary: the neutral element of the name being built - the up file carries no suffix
        $suffix = $down ? '_down' : '';
        $tables = $down ? array_reverse(self::TABLES) : self::TABLES;
        foreach ($tables as $table) {
            $stub = dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql";
            Database::sqlRun((string)file_get_contents($stub));
        }
    }

    /**
     * @param class-string<Hilos> $hilosClass Facade the agents read their features and targets from
     */
    private static function bindAppClass(string $hilosClass): void
    {
        // Reflection sets the facade the process spine would have captured; a case has no spine.
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }
}

/**
 * Facade of a project that keeps files and receives uploads, with two targets.
 */
class FilePublishTestHilos extends Hilos
{
    public const string OPEN = 'open';

    public const string SNIFFING = 'sniffing';

    public const string DEDUP = 'dedup';

    protected const array FEATURES = [HilosFeature::FILES, HilosFeature::UPLOADS];

    public const array UPLOAD_TARGETS = [
        self::OPEN => FilePublishTestOpenTarget::class,
        self::SNIFFING => FilePublishTestSniffingTarget::class,
        self::DEDUP => FilePublishTestDedupTarget::class,
    ];

    /**
     * @return HilosDbContext Never configured, never queried
     */
    protected static function createDb(): HilosDbContext
    {
        return new FilePublishTestDbContext();
    }
}

/**
 * The same project, but one that receives uploads and keeps no files.
 */
final class FilePublishUploadsOnlyTestHilos extends FilePublishTestHilos
{
    protected const array FEATURES = [HilosFeature::UPLOADS];
}

/**
 * Target anyone may upload any type to, up to a kilobyte.
 */
final class FilePublishTestOpenTarget extends AbstractUploadTarget
{
    /**
     * @return int A kilobyte
     */
    public function maxBytes(): int
    {
        return 1024;
    }

    /**
     * @return bool False: guests upload too
     */
    public function requiresSignIn(): bool
    {
        return false;
    }
}

/**
 * Target that reads the type of the content and accepts any.
 */
final class FilePublishTestSniffingTarget extends AbstractUploadTarget
{
    /**
     * @return int A kilobyte
     */
    public function maxBytes(): int
    {
        return 1024;
    }

    /**
     * @return bool False: guests upload too
     */
    public function requiresSignIn(): bool
    {
        return false;
    }

    /**
     * @return bool True: the type is read from the content
     */
    public function sniffsContent(): bool
    {
        return true;
    }
}

/**
 * Target anyone may upload any type to, which refuses a person's second copy of a file.
 */
final class FilePublishTestDedupTarget extends AbstractUploadTarget
{
    /**
     * @return int A kilobyte
     */
    public function maxBytes(): int
    {
        return 1024;
    }

    /**
     * @return bool False: guests upload too
     */
    public function requiresSignIn(): bool
    {
        return false;
    }

    /**
     * @return list<UploadCheckInterface> The ready duplicate check
     * @throws FeatureNotDeclaredException When the project keeps no files
     */
    public function extraChecks(): array
    {
        return [new DuplicateContentCheck()];
    }
}

/**
 * The files settings with a storage limit of a few bytes.
 */
final class FilePublishLimitedSettingsCatalog implements CatalogProviderInterface
{
    /** Bytes the storage of the case may hold. */
    public const int LIMIT = 20;

    /**
     * @return array<string, array<string, mixed>> The files settings, the limit set
     */
    public static function getCatalog(): array
    {
        return array_replace(FilesSettingsCatalog::getCatalog(), [
            FilesSettingsCatalog::MAX_TOTAL_BYTES_KEY => [
                SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
                SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => self::LIMIT,
            ],
        ]);
    }
}

/**
 * Files library whose framework behavior is under test; it keeps the errors it logs.
 */
final class FilePublishTestLibrary extends AbstractFilesLibraryAgent
{
    /** @var list<string> Error lines */
    public array $errors = [];

    /**
     * @param string $message Message the library logged
     */
    protected function logAgentError(string $message): void
    {
        $this->errors[] = $message;
    }
}

/**
 * Browser context that knows two signed-in connections.
 */
final class FilePublishTestBrowserContext extends BrowserContext
{
    /**
     * @param string $acceptKey Connection accept key
     * @return ConnectionIdentity A signed-in person on each of two connections, a guest on any other
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(match ($acceptKey) {
            FilePublishTestKeys::SIGNED_IN => FilePublishTestKeys::SIGNED_IN_USER,
            FilePublishTestKeys::OTHER => FilePublishTestKeys::OTHER_USER,
            default => null,
        });
    }
}

/**
 * The signed-in connections of the case, readable by its browser fixture.
 */
final class FilePublishTestKeys
{
    public const string SIGNED_IN = 'ak-publish-signed-in';

    public const int SIGNED_IN_USER = 42;

    public const string OTHER = 'ak-publish-other';

    public const int OTHER_USER = 43;
}

/**
 * DB context of the case: the framework collections and nothing of a project's.
 */
final class FilePublishTestDbContext extends HilosDbContext
{
}

/**
 * FS context with the tmp and files directories of the case.
 */
final class FilePublishTestFsContext extends FsContext
{
    /**
     * @param string $tmpPath Tmp directory of the case
     * @param string $filesPath Files directory of the case
     */
    public function __construct(
        private readonly string $tmpPath,
        private readonly string $filesPath,
    ) {
    }

    /**
     * Configures both directories.
     */
    public function configure(): void
    {
        $this->setTmpPath($this->tmpPath);
        $this->registerDirectory(FsContext::FILES, $this->filesPath);
    }
}

/**
 * Runtime context holding the feature's uploads and a connections collection.
 */
final class FilePublishTestRtContext extends RtContext
{
    /**
     * Mounts the connections the uploads are held for.
     */
    public function configure(): void
    {
        $this->_stateCollections['connections'] = FilePublishTestConnections::init();
    }
}

/**
 * Connections on the framework base.
 *
 * @extends HilosConnections<FilePublishTestConnection>
 */
final class FilePublishTestConnections extends HilosConnections
{
    public const string STATE_CLASS = FilePublishTestConnection::class;
}

/**
 * Connection row with nothing of its own.
 */
final class FilePublishTestConnection extends HilosConnection
{
    /**
     * Nothing of its own to initialize.
     */
    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (nothing of its own to read)
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> Always empty
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Partial update (nothing of its own to apply)
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}
