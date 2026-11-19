<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Generator;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Action\ActionMessages;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Execution\ExecutionFrame;
use Hilos\Core\Feature\Definition\UploadsFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\Exception\ActionForbiddenException;
use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Core\Page\Exception\ActionViewModeException;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Core\Page\PageException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Files\FileVisibility;
use Hilos\Files\Image\JpegOrientation;
use Hilos\Files\Upload\AbstractUploadTarget;
use Hilos\Files\Upload\DTO\UploadCancelActionDTO;
use Hilos\Files\Upload\DTO\UploadInitActionDTO;
use Hilos\Files\Upload\DTO\UploadStateSignalData;
use Hilos\Files\Upload\UploadFailureCode;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Hilos;
use Hilos\Log\AgentLogStream;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\AdminViewModeRuntime;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosUpload;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The uploads agent over a real tmp directory, a mounted collection and the signal queue (HIL-135).
 *
 * The agent is driven directly rather than through a daemon, as the throttle's case drives its
 * agent: the two actions, the binary frames and the tick are the whole of its interface, and
 * what it answers is the upload row, the file on disk and the state frames it queues. No
 * database is involved - uploads keep none.
 */
final class UploadsAgentIntegrationTest extends TestCase
{
    private const string GUEST = 'ak-guest';

    private const string SIGNED_IN = UploadsAgentIntegrationTestKeys::SIGNED_IN;

    private const int SIGNED_IN_USER = UploadsAgentIntegrationTestKeys::SIGNED_IN_USER;

    private const string ADMIN = UploadsAgentIntegrationTestKeys::ADMIN;

    private const int ADMIN_USER = UploadsAgentIntegrationTestKeys::ADMIN_USER;

    /** A 1x1 PNG: the smallest content libmagic names image/png. */
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private ?RtContext $previousRt = null;

    private ?SignalRouter $previousSignalRouter = null;

    private ?FsContext $previousFs = null;

    private ?BrowserContext $previousBrowser = null;

    private string $previousAppClass = Hilos::class;

    private string $tmpPath = '';

    private string $logPath = '';

    private UploadsAgent $agent;

    private UploadsTestRtContext $rt;

    private UploadsTestBrowserContext $browser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRt = Hilos::$rt;
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousFs = Hilos::$fs;
        $this->previousBrowser = Hilos::$browser;
        $this->previousAppClass = Hilos::appClass();

        $this->tmpPath = sys_get_temp_dir() . '/hilos-uploads-' . bin2hex(random_bytes(6));
        $this->logPath = $this->tmpPath . '-logs';
        mkdir($this->logPath);
        Logger::setLogFile($this->logPath . '/main.log');
        Hilos::$fs = new UploadsTestFsContext($this->tmpPath);
        Hilos::$fs->configure();
        Hilos::$sr = new SignalRouter();
        $this->browser = new UploadsTestBrowserContext();
        Hilos::$browser = $this->browser;
        self::bindAppClass(UploadsTestHilos::class);
        // The wrapper a removed row leaves in the view cache is dropped by this subscriber alone.
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());

        $this->rt = new UploadsTestRtContext();
        $this->rt->mountFeatureRuntime([new UploadsFeature()]);
        $this->rt->configure();
        $this->rt->bindStateCollectionNames();
        Hilos::$rt = $this->rt;
        $this->connect(self::GUEST);
        $this->connect(self::SIGNED_IN);
        $this->connect(self::ADMIN);
        RtTruthSourceRegistry::registerDaemon(AdminViewModeRuntime::RT_ITEM);

        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_UPLOADS);
        $this->agent = new UploadsAgent();
        OwnershipDeclaration::claimAll($this->agent);
        $this->agent->onStart();
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregister(StateHilosUpload::RT_COLLECTION, HilosAgentType::HILOS_UPLOADS);
        RtTruthSourceRegistry::unregisterDaemon(AdminViewModeRuntime::RT_ITEM);
        ExecutionContext::clear();
        SourceChangeBus::reset();
        Logger::resetLogFile();

        foreach ([$this->tmpPath, $this->logPath] as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        self::bindAppClass($this->previousAppClass);
        Hilos::$browser = $this->previousBrowser;
        Hilos::$fs = $this->previousFs;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$rt = $this->previousRt;

        parent::tearDown();
    }

    public function testADeclarationOpensAReadyUploadWithAnEmptyFile(): void
    {
        $this->assertNull($this->declare(self::GUEST, 'u1', size: 10));

        $upload = $this->upload(self::GUEST, 'u1');
        $this->assertSame(UploadPhase::READY, $upload->phase);
        $this->assertSame(0, $upload->receivedBytes);
        $this->assertNull($upload->userId);
        $this->assertSame('a.txt', $upload->filename);
        $this->assertSame('text/plain', $upload->mimeType);
        $this->assertSame(0, filesize($this->path($upload)));

        $this->assertSame([[self::GUEST, 'u1', 'ready', 0]], $this->frames());
    }

    public function testASignedInDeclarationRecordsThePerson(): void
    {
        $this->declare(self::SIGNED_IN, 'u1', target: UploadsTestHilos::IMAGE, mimeType: 'image/png', size: 10);

        $this->assertSame(self::SIGNED_IN_USER, $this->upload(self::SIGNED_IN, 'u1')->userId);
    }

    public function testAnUnknownTargetIsRefused(): void
    {
        $this->assertRefused('This upload is not accepted here', fn() => $this->declare(self::GUEST, 'u1', target: 'nope'));
    }

    public function testATargetRequiringSignInRefusesAGuest(): void
    {
        $this->expectException(ActionUnauthorizedException::class);

        $this->declare(self::GUEST, 'u1', target: UploadsTestHilos::IMAGE, mimeType: 'image/png');
    }

    public function testWithTheModeOnAGuestOnAnAdminTargetMeetsTheModesRefusal(): void
    {
        $this->switchMode(true);

        $this->assertViewModeRefusal(fn() => $this->declare(self::GUEST, 'u1', target: UploadsTestHilos::ADMIN));

        $this->assertNull(Hilos::$rt->hilosUploads->find(self::GUEST, 'u1'));
    }

    public function testWithTheModeOnASignedInPersonWhoIsNoAdminMeetsTheModesRefusal(): void
    {
        $this->switchMode(true);

        $this->assertViewModeRefusal(fn() => $this->declare(self::SIGNED_IN, 'u1', target: UploadsTestHilos::ADMIN));

        $this->assertNull(Hilos::$rt->hilosUploads->find(self::SIGNED_IN, 'u1'));
    }

    public function testWithTheModeOffAnAdminTargetAsksAGuestToSignInAndForbidsAPersonWhoIsNoAdmin(): void
    {
        $this->switchMode(false);

        $this->assertInstanceOf(
            ActionUnauthorizedException::class,
            $this->refusalOf(fn() => $this->declare(self::GUEST, 'u1', target: UploadsTestHilos::ADMIN)),
        );
        $forbidden = $this->refusalOf(fn() => $this->declare(self::SIGNED_IN, 'u1', target: UploadsTestHilos::ADMIN));
        $this->assertInstanceOf(ActionForbiddenException::class, $forbidden);
        $this->assertSame('forbidden', $forbidden->errorCode);

        $this->assertNull(Hilos::$rt->hilosUploads->find(self::GUEST, 'u1'));
        $this->assertNull(Hilos::$rt->hilosUploads->find(self::SIGNED_IN, 'u1'));
    }

    public function testAnAdministratorUploadsToAnAdminTargetWithTheModeOnAndOff(): void
    {
        $this->switchMode(true);
        $this->assertNull($this->declare(self::ADMIN, 'u1', target: UploadsTestHilos::ADMIN));
        $this->switchMode(false);
        $this->assertNull($this->declare(self::ADMIN, 'u2', target: UploadsTestHilos::ADMIN));

        $this->assertSame(self::ADMIN_USER, $this->upload(self::ADMIN, 'u1')->userId);
        $this->assertSame(self::ADMIN_USER, $this->upload(self::ADMIN, 'u2')->userId);
    }

    public function testWithTheModeOnAGuestOnATargetRequiringSignInIsAskedToSignIn(): void
    {
        $this->switchMode(true);

        $this->assertInstanceOf(
            ActionUnauthorizedException::class,
            $this->refusalOf(fn() => $this->declare(self::GUEST, 'u1', target: UploadsTestHilos::IMAGE, mimeType: 'image/png')),
        );
    }

    public function testAViewerCannotCancelAnUploadOfAnAdminTarget(): void
    {
        $this->switchMode(false);
        $this->declare(self::ADMIN, 'u1', target: UploadsTestHilos::ADMIN);
        $this->browser->adminRights = false;
        $this->switchMode(true);

        $this->assertViewModeRefusal(fn() => $this->agent->onAgentAction(
            self::ADMIN,
            HilosSignalConstants::HILOS_UPLOAD_CANCEL,
            new UploadCancelActionDTO('u1'),
        ));

        $this->assertNotNull(Hilos::$rt->hilosUploads->find(self::ADMIN, 'u1'));
    }

    public function testAConnectionThatSignedOutStillCancelsItsUploadOfAnAuthenticatedTarget(): void
    {
        $this->switchMode(true);
        $this->declare(self::SIGNED_IN, 'u1', target: UploadsTestHilos::IMAGE, mimeType: 'image/png', size: 10);
        $path = $this->path($this->upload(self::SIGNED_IN, 'u1'));
        $this->frames();
        $this->browser->signedOut[] = self::SIGNED_IN;

        $this->assertNull($this->agent->onAgentAction(
            self::SIGNED_IN,
            HilosSignalConstants::HILOS_UPLOAD_CANCEL,
            new UploadCancelActionDTO('u1'),
        ));

        $this->assertNull(Hilos::$rt->hilosUploads->find(self::SIGNED_IN, 'u1'));
        $this->assertFileDoesNotExist($path);
        $this->assertSame([[self::SIGNED_IN, 'u1', null, null]], $this->frames());
    }

    public function testAViewerCannotReplaceAnUploadOfAnAdminTargetUnderAnotherTarget(): void
    {
        $this->switchMode(false);
        $this->declare(self::ADMIN, 'u1', target: UploadsTestHilos::ADMIN, size: 2);
        $this->chunk(self::ADMIN, 'u1', 'ab');
        $path = $this->path($this->upload(self::ADMIN, 'u1'));
        $this->browser->adminRights = false;
        $this->switchMode(true);

        $this->assertViewModeRefusal(fn() => $this->declare(self::ADMIN, 'u1', size: 5));

        $kept = $this->upload(self::ADMIN, 'u1');
        $this->assertSame(UploadsTestHilos::ADMIN, $kept->target);
        $this->assertSame(UploadPhase::COMPLETE, $kept->phase);
        $this->assertFileExists($path);
    }

    public function testAnUploadInProgressCannotBeDeclaredAgain(): void
    {
        $this->declare(self::GUEST, 'u1', size: 10);

        $this->assertRefused('This upload is already in progress', fn() => $this->declare(self::GUEST, 'u1', size: 10));
    }

    public function testAConnectionHoldsAtMostSixteenFiles(): void
    {
        for ($upload = 0; $upload < UploadsAgent::MAX_OPEN_UPLOADS_PER_CONNECTION; $upload++) {
            $this->declare(self::GUEST, "u{$upload}", size: 10);
        }

        $this->assertRefused('Too many uploads at once', fn() => $this->declare(self::GUEST, 'one-more', size: 10));
        // Another connection is not held back by this one.
        $this->assertNull($this->declare(self::SIGNED_IN, 'u0', size: 10));
    }

    public function testAFailedUploadDoesNotCountAgainstTheConnection(): void
    {
        for ($upload = 0; $upload < UploadsAgent::MAX_OPEN_UPLOADS_PER_CONNECTION; $upload++) {
            $this->declare(self::GUEST, "u{$upload}", size: 1);
        }
        $this->chunk(self::GUEST, 'u0', 'xx');

        $this->assertNull($this->declare(self::GUEST, 'one-more', size: 10));
    }

    public function testTheTargetChecksRefuseByTheirOwnWords(): void
    {
        $this->assertRefused('File is empty', fn() => $this->declare(self::GUEST, 'u1', size: 0));
        $this->assertRefused(
            'File is larger than the allowed size',
            fn() => $this->declare(self::GUEST, 'u1', size: UploadsTestOpenTarget::MAX_BYTES + 1),
        );
        $this->assertRefused(
            'This file type is not allowed',
            fn() => $this->declare(self::SIGNED_IN, 'u1', target: UploadsTestHilos::IMAGE, mimeType: 'text/plain'),
        );
        $this->assertRefused('This file type is not allowed', fn() => $this->declare(self::GUEST, 'u1', mimeType: 'plain'));

        $this->assertNull(Hilos::$rt->hilosUploads->find(self::GUEST, 'u1'));
        $this->assertSame([], glob($this->tmpPath . '/*'));
    }

    public function testRedeclaringAFinishedUploadReplacesItAndItsFile(): void
    {
        $this->declare(self::GUEST, 'u1', size: 2);
        $this->chunk(self::GUEST, 'u1', 'ab');
        $oldPath = $this->path($this->upload(self::GUEST, 'u1'));

        $this->declare(self::GUEST, 'u1', size: 5);

        $replaced = $this->upload(self::GUEST, 'u1');
        $this->assertSame(UploadPhase::READY, $replaced->phase);
        $this->assertSame(5, $replaced->declaredSize);
        $this->assertFileDoesNotExist($oldPath);
        $this->assertFileExists($this->path($replaced));
    }

    public function testChunksMoveTheUploadToUploadingAndOnlyTheFirstIsFramed(): void
    {
        $this->declare(self::GUEST, 'u1', size: 10);
        $this->frames();

        $this->chunk(self::GUEST, 'u1', 'abc');
        $this->chunk(self::GUEST, 'u1', 'def');

        $upload = $this->upload(self::GUEST, 'u1');
        $this->assertSame(UploadPhase::UPLOADING, $upload->phase);
        // The second chunk arrives inside the throttle interval: counted, but neither written nor framed.
        $this->assertSame(3, $upload->receivedBytes);
        $this->assertSame([[self::GUEST, 'u1', 'uploading', 3]], $this->frames());
        $this->assertSame('abcdef', file_get_contents($this->path($upload)));
    }

    public function testAChangeOfPhaseIsFramedWhateverTheThrottle(): void
    {
        $this->declare(self::GUEST, 'u1', size: 6);
        $this->frames();

        $this->chunk(self::GUEST, 'u1', 'abc');
        $this->chunk(self::GUEST, 'u1', 'def');

        $this->assertSame([[self::GUEST, 'u1', 'uploading', 3], [self::GUEST, 'u1', 'complete', 6]], $this->frames());
    }

    public function testMoreBytesThanDeclaredFailTheUploadAndDeleteItsFile(): void
    {
        $this->declare(self::GUEST, 'u1', size: 3);
        $path = $this->path($this->upload(self::GUEST, 'u1'));

        $this->chunk(self::GUEST, 'u1', 'abcd');

        $upload = $this->upload(self::GUEST, 'u1');
        $this->assertSame(UploadPhase::FAILED, $upload->phase);
        $this->assertSame(UploadFailureCode::SIZE_OVERFLOW, $upload->errorCode);
        $this->assertSame('Uploaded data exceeds declared size', $upload->errorMessage);
        $this->assertNull($upload->tmpIndex);
        $this->assertFileDoesNotExist($path);
    }

    public function testExactlyTheDeclaredSizeCompletesTheUpload(): void
    {
        $this->declare(self::GUEST, 'u1', size: 4);

        $this->chunk(self::GUEST, 'u1', 'abcd');

        $upload = $this->upload(self::GUEST, 'u1');
        $this->assertSame(UploadPhase::COMPLETE, $upload->phase);
        $this->assertSame(4, $upload->receivedBytes);
        $this->assertNull($upload->detectedMimeType);
        $this->assertSame(hash('sha256', 'abcd'), $upload->contentHash);
        $this->assertSame('abcd', file_get_contents($this->path($upload)));
    }

    public function testTheFingerprintCountedOverTheChunksIsTheOneOfTheWholeFile(): void
    {
        $this->declare(self::GUEST, 'u1', size: 6);

        $this->chunk(self::GUEST, 'u1', 'abc');
        $this->assertNull($this->upload(self::GUEST, 'u1')->contentHash, 'No fingerprint before the last byte');
        $this->chunk(self::GUEST, 'u1', 'def');

        $upload = $this->upload(self::GUEST, 'u1');
        $this->assertSame(UploadPhase::COMPLETE, $upload->phase);
        $this->assertSame(hash('sha256', 'abcdef'), $upload->contentHash);
    }

    public function testJpegMetadataIsStrippedBeforeTheFingerprintEvenWithoutContentSniffing(): void
    {
        $jpeg = self::jpegWithGps();
        $this->declare(self::GUEST, 'u1', mimeType: 'image/jpeg', size: strlen($jpeg));
        $this->chunk(self::GUEST, 'u1', substr($jpeg, 0, 17));
        $this->chunk(self::GUEST, 'u1', substr($jpeg, 17));

        $upload = $this->upload(self::GUEST, 'u1');
        $stored = (string)file_get_contents($this->path($upload));
        self::assertSame(UploadPhase::COMPLETE, $upload->phase);
        self::assertSame(strlen($jpeg), $upload->declaredSize);
        self::assertSame(strlen($jpeg), $upload->receivedBytes);
        self::assertStringNotContainsString('GPS-SECRET', $stored);
        self::assertSame(6, JpegOrientation::read($stored));
        self::assertSame(hash('sha256', $stored), $upload->contentHash);
        self::assertLessThan(strlen($jpeg), strlen($stored));
    }

    public function testMalformedJpegCompletesUnchangedAndWritesAWarning(): void
    {
        $jpeg = "\xff\xd8\xff\xe1\xff\xffbroken";
        $this->declare(self::GUEST, 'u1', mimeType: 'image/jpeg', size: strlen($jpeg));
        $this->chunk(self::GUEST, 'u1', $jpeg);

        $upload = $this->upload(self::GUEST, 'u1');
        self::assertSame(UploadPhase::COMPLETE, $upload->phase);
        self::assertSame($jpeg, file_get_contents($this->path($upload)));
        self::assertSame(hash('sha256', $jpeg), $upload->contentHash);
        $log = AgentLogStream::pathFor($this->logPath, $this->agent->getId(), false);
        self::assertStringContainsString('Upload u1 of ak-guest keeps its metadata:', (string)file_get_contents($log));
    }

    public function testASniffedPngCompletesWithItsDetectedType(): void
    {
        $png = (string)base64_decode(self::PNG_BASE64, true);
        $this->declare(self::SIGNED_IN, 'u1', target: UploadsTestHilos::IMAGE, mimeType: 'image/png', size: strlen($png));

        $this->chunk(self::SIGNED_IN, 'u1', $png);

        $upload = $this->upload(self::SIGNED_IN, 'u1');
        $this->assertSame(UploadPhase::COMPLETE, $upload->phase);
        $this->assertSame('image/png', $upload->detectedMimeType);
        $this->assertSame(hash('sha256', $png), $upload->contentHash);
    }

    public function testSniffedTextUnderAnImageTargetFailsAsContentMismatch(): void
    {
        $text = 'this is not an image at all';
        $this->declare(self::SIGNED_IN, 'u1', target: UploadsTestHilos::IMAGE, mimeType: 'image/png', size: strlen($text));
        $path = $this->path($this->upload(self::SIGNED_IN, 'u1'));

        $this->chunk(self::SIGNED_IN, 'u1', $text);

        $upload = $this->upload(self::SIGNED_IN, 'u1');
        $this->assertSame(UploadPhase::FAILED, $upload->phase);
        $this->assertSame(UploadFailureCode::CONTENT_MISMATCH, $upload->errorCode);
        $this->assertSame('text/plain', $upload->detectedMimeType);
        $this->assertFileDoesNotExist($path);
    }

    public function testChunksNamingNoUploadOfTheirConnectionCreateNothing(): void
    {
        $this->declare(self::GUEST, 'u1', size: 10);
        $this->frames();

        $this->agent->onSignalFrameBinary(new WebSocketFrameBinarySignalDTO(self::GUEST, chr(0) . 'abc'), SignalSource::WEBSOCKET, '');
        $this->chunk(self::GUEST, 'unknown', 'abc');
        $this->chunk(self::SIGNED_IN, 'u1', 'abc');

        $this->assertCount(1, Hilos::$rt->hilosUploads);
        $this->assertSame(0, $this->upload(self::GUEST, 'u1')->receivedBytes);
        $this->assertSame([], $this->frames());
    }

    public function testACancelDropsTheUploadAndItsFileAndSaysSo(): void
    {
        $this->declare(self::GUEST, 'u1', size: 10);
        $path = $this->path($this->upload(self::GUEST, 'u1'));
        $this->frames();

        $this->assertNull($this->agent->onAgentAction(
            self::GUEST,
            HilosSignalConstants::HILOS_UPLOAD_CANCEL,
            new UploadCancelActionDTO('u1'),
        ));

        $this->assertNull(Hilos::$rt->hilosUploads->find(self::GUEST, 'u1'));
        $this->assertFileDoesNotExist($path);
        $this->assertSame([[self::GUEST, 'u1', null, null]], $this->frames());
    }

    public function testCancelingAnUploadThatIsGoneSucceedsSilently(): void
    {
        $this->assertNull($this->agent->onAgentAction(
            self::GUEST,
            HilosSignalConstants::HILOS_UPLOAD_CANCEL,
            new UploadCancelActionDTO('never-declared'),
        ));

        $this->assertSame([], $this->frames());
    }

    public function testTheSweepDropsTheUploadsOfAGoneConnectionWithoutAWord(): void
    {
        $this->declare(self::GUEST, 'u1', size: 10);
        $path = $this->path($this->upload(self::GUEST, 'u1'));
        $this->frames();
        Hilos::$rt->connectionsSource()?->remove(self::GUEST);

        $this->sweep();

        $this->assertNull(Hilos::$rt->hilosUploads->find(self::GUEST, 'u1'));
        $this->assertFileDoesNotExist($path);
        $this->assertSame([], $this->frames());
    }

    public function testTheSweepDropsAnUploadUnchangedForAnHourAndSaysSo(): void
    {
        $this->declare(self::GUEST, 'u1', size: 10);
        $this->declare(self::GUEST, 'u2', size: 10);
        $path = $this->path($this->upload(self::GUEST, 'u1'));
        $this->frames();
        $this->rt->ageUpload(StateHilosUpload::keyFor(self::GUEST, 'u1'), UploadsAgent::UPLOAD_TTL_SECONDS + 1);

        $this->sweep();

        $this->assertNull(Hilos::$rt->hilosUploads->find(self::GUEST, 'u1'));
        $this->assertNotNull(Hilos::$rt->hilosUploads->find(self::GUEST, 'u2'));
        $this->assertFileDoesNotExist($path);
        $this->assertSame([[self::GUEST, 'u1', null, null]], $this->frames());
    }

    public function testStoppingDropsEveryUploadAndTellsItsConnection(): void
    {
        $this->declare(self::GUEST, 'u1', size: 10);
        $this->declare(self::SIGNED_IN, 'u2', size: 10);
        $this->frames();

        $this->agent->onStop();

        $this->assertCount(0, Hilos::$rt->hilosUploads);
        $this->assertSame([], glob($this->tmpPath . '/*'));
        $this->assertEqualsCanonicalizing(
            [[self::GUEST, 'u1', null, null], [self::SIGNED_IN, 'u2', null, null]],
            $this->frames(),
        );
    }

    public function testStartingDropsWhatAPredecessorLeft(): void
    {
        $this->declare(self::GUEST, 'u1', size: 10);
        $path = $this->path($this->upload(self::GUEST, 'u1'));

        $successor = new UploadsAgent();
        $successor->onStart();

        $this->assertCount(0, Hilos::$rt->hilosUploads);
        $this->assertFileDoesNotExist($path);
    }

    /**
     * Declares one file the way the dispatcher hands the action over.
     *
     * @param string $acceptKey Connection declaring
     * @param string $clientUploadId Upload id
     * @param string $target Target name
     * @param string $mimeType Declared type
     * @param int $size Declared size
     * @return mixed What the action answered
     * @throws ValidationException When the declaration is refused
     */
    private function declare(
        string $acceptKey,
        string $clientUploadId,
        string $target = UploadsTestHilos::OPEN,
        string $mimeType = 'text/plain; charset=utf-8',
        int $size = 1,
    ): mixed {
        return $this->agent->onAgentAction(
            $acceptKey,
            HilosSignalConstants::HILOS_UPLOAD_INIT,
            new UploadInitActionDTO($target, $clientUploadId, 'a.txt', $mimeType, $size),
        );
    }

    /**
     * Sends one signed chunk.
     *
     * @param string $acceptKey Connection sending
     * @param string $clientUploadId Upload the chunk is signed with
     * @param string $bytes Chunk bytes
     */
    private function chunk(string $acceptKey, string $clientUploadId, string $bytes): void
    {
        $this->agent->onSignalFrameBinary(
            new WebSocketFrameBinarySignalDTO($acceptKey, chr(strlen($clientUploadId)) . $clientUploadId . $bytes),
            SignalSource::WEBSOCKET,
            '',
        );
    }

    /**
     * Runs the sweep now, as if its interval had passed.
     */
    private function sweep(): void
    {
        new ReflectionProperty(UploadsAgent::class, 'lastSweepAt')->setValue($this->agent, 0.0);
        $this->agent->onTick();
    }

    /**
     * @param string $message Sentence the refusal is expected to carry
     * @param callable(): mixed $declare Declaration expected to be refused
     */
    private function assertRefused(string $message, callable $declare): void
    {
        try {
            $declare();
        } catch (ValidationException $refused) {
            $this->assertSame($message, $refused->getMessage());

            return;
        }

        $this->fail("Expected the declaration to be refused with: {$message}");
    }

    /**
     * @param callable(): mixed $action Action expected to be refused by the target's access level
     * @return PageException The refusal
     */
    private function refusalOf(callable $action): PageException
    {
        try {
            $action();
        } catch (PageException $refused) {
            return $refused;
        }

        $this->fail('Expected the action to be refused by the target\'s access level');
    }

    /**
     * @param callable(): mixed $action Action a viewer of the admin view mode is expected to be refused
     */
    private function assertViewModeRefusal(callable $action): void
    {
        $refused = $this->refusalOf($action);

        $this->assertInstanceOf(ActionViewModeException::class, $refused);
        $this->assertSame(403, $refused->getCode());
        $this->assertSame('view_mode', $refused->errorCode);
        $this->assertSame(ActionMessages::VIEW_MODE, $refused->getMessage());
    }

    /**
     * Turns the node's admin view mode on or off the way the master does - as the daemon, not as the agent.
     *
     * @param bool $enabled Whether the mode is on
     */
    private function switchMode(bool $enabled): void
    {
        ExecutionContext::run(new ExecutionFrame(), fn() => Hilos::$rt?->hilosAdminViewModeRuntime?->actions->set($enabled));
    }

    /**
     * @param string $acceptKey Connection
     * @param string $clientUploadId Upload id
     * @return HilosUpload The upload, which must exist
     */
    private function upload(string $acceptKey, string $clientUploadId): HilosUpload
    {
        $upload = Hilos::$rt->hilosUploads->find($acceptKey, $clientUploadId);
        $this->assertNotNull($upload);

        return $upload;
    }

    /**
     * @param HilosUpload $upload Upload that still has a file
     * @return string Path of its temporary file
     */
    private function path(HilosUpload $upload): string
    {
        $this->assertNotNull($upload->tmpIndex);

        return $this->tmpPath . '/' . $upload->tmpIndex;
    }

    /** @return string JPEG with a GPS IFD and a scan */
    private static function jpegWithGps(): string
    {
        $ifd = pack('v', 2) . pack('vvVvv', 0x0112, 3, 1, 6, 0)
            . pack('vvVV', 0x8825, 4, 1, 38) . pack('V', 0);
        $gps = pack('v', 1) . pack('vvVV', 1, 2, 11, 56) . pack('V', 0) . 'GPS-SECRET';
        $exif = "Exif\0\0II" . pack('vV', 42, 8) . $ifd . $gps;

        return "\xff\xd8\xff\xe1" . pack('n', strlen($exif) + 2) . $exif
            . "\xff\xda\x00\x02pixels\xff\xd9";
    }

    /**
     * Drains the queue and lists the state frames it held.
     *
     * @return list<array{0: ?string, 1: string, 2: ?string, 3: ?int}> Connection, upload id, phase, received bytes
     */
    private function frames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) instanceof SignalDTO) {
            if ($signal->signalName->getName() !== HilosSignalConstants::HILOS_UPLOAD_STATE
                || !$signal->data instanceof WebSocketSignalData
                || !$signal->data->data instanceof UploadStateSignalData) {
                continue;
            }
            $state = $signal->data->data;
            $frames[] = [$signal->data->targetAcceptKey, $state->clientUploadId, $state->phase, $state->receivedBytes];
        }

        return $frames;
    }

    /**
     * @param string $acceptKey Connection to open
     */
    private function connect(string $acceptKey): void
    {
        Hilos::$rt->connectionsSource()?->add(UploadsTestConnection::create($acceptKey, null));
    }

    /**
     * @param class-string<Hilos> $hilosClass Facade the agent reads its targets from
     */
    private static function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }
}

/**
 * Facade of a project that declares uploads with three targets.
 */
final class UploadsTestHilos extends Hilos
{
    public const string OPEN = 'open';

    public const string IMAGE = 'image';

    public const string ADMIN = 'admin';

    protected const array FEATURES = [HilosFeature::UPLOADS];

    public const array UPLOAD_TARGETS = [
        self::OPEN => UploadsTestOpenTarget::class,
        self::IMAGE => UploadsTestImageTarget::class,
        self::ADMIN => UploadsTestAdminTarget::class,
    ];

    /**
     * @return HilosDbContext Never configured, never queried
     */
    protected static function createDb(): HilosDbContext
    {
        return new UploadsTestDbContext();
    }
}

/**
 * Target anyone may upload any type to, up to a hundred bytes.
 */
final class UploadsTestOpenTarget extends AbstractUploadTarget
{
    public const int MAX_BYTES = 100;

    /**
     * @return int A hundred bytes
     */
    public function maxBytes(): int
    {
        return self::MAX_BYTES;
    }

    /**
     * @return PageAccessLevel PUBLIC: guests upload too
     */
    public function accessLevel(): PageAccessLevel
    {
        return PageAccessLevel::PUBLIC;
    }

    /** @return FileVisibility A signed-in person may read a published file */
    public function visibility(): FileVisibility
    {
        return FileVisibility::AUTHENTICATED;
    }
}

/**
 * Target for signed-in people, PNG only, content sniffed.
 */
final class UploadsTestImageTarget extends AbstractUploadTarget
{
    /**
     * @return int A kilobyte
     */
    public function maxBytes(): int
    {
        return 1024;
    }

    /**
     * @return PageAccessLevel AUTHENTICATED: only a signed-in person
     */
    public function accessLevel(): PageAccessLevel
    {
        return PageAccessLevel::AUTHENTICATED;
    }

    /** @return FileVisibility A signed-in person may read a published file */
    public function visibility(): FileVisibility
    {
        return FileVisibility::AUTHENTICATED;
    }

    /**
     * @return list<string> PNG only
     */
    public function acceptedMimeTypes(): array
    {
        return ['image/png'];
    }

    /**
     * @return bool True: the content must be a PNG as well
     */
    public function sniffsContent(): bool
    {
        return true;
    }
}

/**
 * Target only an administrator uploads to, any type, up to a hundred bytes.
 */
final class UploadsTestAdminTarget extends AbstractUploadTarget
{
    /**
     * @return int A hundred bytes
     */
    public function maxBytes(): int
    {
        return 100;
    }

    /**
     * @return PageAccessLevel ADMIN: judged the way an admin page is
     */
    public function accessLevel(): PageAccessLevel
    {
        return PageAccessLevel::ADMIN;
    }

    /** @return FileVisibility Every visitor may read a published file */
    public function visibility(): FileVisibility
    {
        return FileVisibility::PUBLIC;
    }
}

/**
 * Browser context that knows two signed-in connections, one of them an administrator's.
 *
 * Who acts as an admin is answered here rather than read: the case has neither the person table
 * nor the impersonation setting the framework's answer reads.
 */
final class UploadsTestBrowserContext extends BrowserContext
{
    /** Whether the administrator of the case still holds their rights. */
    public bool $adminRights = true;

    /** @var list<string> Connections whose person has signed out: the server takes each of them for a guest from now on. */
    public array $signedOut = [];

    /**
     * @param string $acceptKey Connection accept key
     * @return ConnectionIdentity The person on one of the two signed-in connections unless signed out, a guest on any other
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        if (in_array($acceptKey, $this->signedOut, true)) {
            return ConnectionIdentity::resolved(null);
        }

        return ConnectionIdentity::resolved(match ($acceptKey) {
            UploadsAgentIntegrationTestKeys::SIGNED_IN => UploadsAgentIntegrationTestKeys::SIGNED_IN_USER,
            UploadsAgentIntegrationTestKeys::ADMIN => UploadsAgentIntegrationTestKeys::ADMIN_USER,
            default => null,
        });
    }

    /**
     * @param string $acceptKey Connection asked about
     * @param int $userId Person the session acts as
     * @return bool Whether the person is the administrator of the case and still holds their rights
     */
    public function actsAsAdmin(string $acceptKey, int $userId): bool
    {
        return $userId === UploadsAgentIntegrationTestKeys::ADMIN_USER && $this->adminRights;
    }
}

/**
 * The signed-in connections of the case, readable by its browser fixture.
 */
final class UploadsAgentIntegrationTestKeys
{
    public const string SIGNED_IN = 'ak-signed-in';

    public const int SIGNED_IN_USER = 42;

    public const string ADMIN = 'ak-admin';

    public const int ADMIN_USER = 7;
}

/**
 * FS context with a tmp directory of the case's own.
 */
final class UploadsTestFsContext extends FsContext
{
    /**
     * @param string $tmpPath Tmp directory of the case
     */
    public function __construct(private readonly string $tmpPath)
    {
    }

    /**
     * Configures the tmp directory.
     */
    public function configure(): void
    {
        $this->setTmpPath($this->tmpPath, DirectoryScope::CLUSTER);
    }
}

/**
 * Runtime context holding the feature's uploads and a connections collection.
 */
final class UploadsTestRtContext extends RtContext
{
    /**
     * Mounts the connections the sweep compares uploads against.
     */
    public function configure(): void
    {
        $this->_stateCollections['connections'] = UploadsTestConnections::init();
    }

    /**
     * Moves the last change of one upload into the past, as an hour of silence would.
     *
     * @param string $key Upload row id
     * @param int $seconds How far back the last change is moved
     */
    public function ageUpload(string $key, int $seconds): void
    {
        $this->_stateCollections[StateHilosUpload::RT_COLLECTION]->get($key)?->applyDiff([
            StateHilosUpload::updatedAt => time() - $seconds,
        ]);
    }
}

/**
 * Connections on the framework base.
 *
 * @extends HilosConnections<UploadsTestConnection>
 */
final class UploadsTestConnections extends HilosConnections
{
    public const string STATE_CLASS = UploadsTestConnection::class;
}

/**
 * Connection row with nothing of its own.
 */
final class UploadsTestConnection extends HilosConnection
{
    /**
     * Nothing of its own to initialize.
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function initOwn(): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $row Serialized runtime row (nothing of its own to read)
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function hydrateOwn(RtRowReader $row): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowWriter $out Segmented destination after both base stages
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function exportOwn(RtRowWriter $out): Generator
    {
        yield new RtWork();
    }

    /**
     * @param RtRowReader $diff Partial update (nothing of its own to apply)
     * @return Generator<int, RtWork, int, void> No project fields
     */
    protected function applyOwnDiff(RtRowReader $diff): Generator
    {
        yield new RtWork();
    }
}

/**
 * DB context the facade hands back; never configured, never queried.
 */
final class UploadsTestDbContext extends HilosDbContext
{
    /**
     * No-op DB configuration.
     */
    public function configure(): void
    {
    }
}
