<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Browser\Context\ConnectionIdentity;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\Definition\UploadsFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Page\Exception\ActionUnauthorizedException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Files\Upload\AbstractUploadTarget;
use Hilos\Files\Upload\DTO\UploadCancelActionDTO;
use Hilos\Files\Upload\DTO\UploadInitActionDTO;
use Hilos\Files\Upload\DTO\UploadStateSignalData;
use Hilos\Files\Upload\UploadFailureCode;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Fs\Context\FsContext;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\HilosConnections;
use Hilos\Runtime\State\Item\HilosConnection;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosUpload;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
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

    /** A 1x1 PNG: the smallest content libmagic names image/png. */
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private ?RtContext $previousRt = null;

    private ?SignalRouter $previousSignalRouter = null;

    private ?FsContext $previousFs = null;

    private ?BrowserContext $previousBrowser = null;

    private string $previousAppClass = Hilos::class;

    private string $tmpPath = '';

    private UploadsAgent $agent;

    private UploadsTestRtContext $rt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousRt = Hilos::$rt;
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousFs = Hilos::$fs;
        $this->previousBrowser = Hilos::$browser;
        $this->previousAppClass = Hilos::appClass();

        $this->tmpPath = sys_get_temp_dir() . '/hilos-uploads-' . bin2hex(random_bytes(6));
        Hilos::$fs = new UploadsTestFsContext($this->tmpPath);
        Hilos::$fs->configure();
        Hilos::$sr = new SignalRouter();
        Hilos::$browser = new UploadsTestBrowserContext();
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

        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_UPLOADS);
        $this->agent = new UploadsAgent();
        OwnershipDeclaration::claimAll($this->agent);
        $this->agent->onStart();
    }

    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregister(StateHilosUpload::RT_COLLECTION, HilosAgentType::HILOS_UPLOADS);
        ExecutionContext::clear();
        SourceChangeBus::reset();

        foreach (glob($this->tmpPath . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->tmpPath)) {
            rmdir($this->tmpPath);
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
 * Facade of a project that declares uploads with two targets.
 */
final class UploadsTestHilos extends Hilos
{
    public const string OPEN = 'open';

    public const string IMAGE = 'image';

    protected const array FEATURES = [HilosFeature::UPLOADS];

    public const array UPLOAD_TARGETS = [
        self::OPEN => UploadsTestOpenTarget::class,
        self::IMAGE => UploadsTestImageTarget::class,
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
     * @return bool False: guests upload too
     */
    public function requiresSignIn(): bool
    {
        return false;
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
     * @return bool True: only a signed-in person
     */
    public function requiresSignIn(): bool
    {
        return true;
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
 * Browser context that knows one signed-in connection.
 */
final class UploadsTestBrowserContext extends BrowserContext
{
    /**
     * @param string $acceptKey Connection accept key
     * @return ConnectionIdentity The signed-in person on one connection, a guest on any other
     */
    protected function resolveConnectionIdentity(string $acceptKey): ConnectionIdentity
    {
        return ConnectionIdentity::resolved(
            $acceptKey === UploadsAgentIntegrationTestKeys::SIGNED_IN ? UploadsAgentIntegrationTestKeys::SIGNED_IN_USER : null,
        );
    }
}

/**
 * The signed-in connection of the case, readable by its browser fixture.
 */
final class UploadsAgentIntegrationTestKeys
{
    public const string SIGNED_IN = 'ak-signed-in';

    public const int SIGNED_IN_USER = 42;
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
        $this->setTmpPath($this->tmpPath);
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
