<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Cluster\ClusterContext;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\Schema\Schema;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Item\File;
use Hilos\Database\View\Item\FileVariant;
use Hilos\Database\View\Item\Session;
use Hilos\Files\FilesSettingsCatalog;
use Hilos\Files\FileVisibility;
use Hilos\Files\HilosFiles;
use Hilos\Files\Image\DTO\ImageRenderedSignalData;
use Hilos\Files\Image\DTO\ImageRenderSignalData;
use Hilos\Files\Image\GdImageEngine;
use Hilos\Files\Image\ImageEngineInterface;
use Hilos\Files\Image\ImageFit;
use Hilos\Files\Image\ImageFormat;
use Hilos\Files\Image\ImageProbe;
use Hilos\Files\Image\ImageRenderOutcome;
use Hilos\Files\Image\ImagesAgent;
use Hilos\Files\Image\ImageVariant;
use Hilos\Files\Library\AbstractFilesLibraryAgent;
use Hilos\Files\Storage\FilesStorageInterface;
use Hilos\Files\Storage\LocalFilesStorage;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Hilos;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;

/**
 * Real registry rows, GD bytes and files, with the agent frames delivered explicitly to control
 * the lost-result and retry windows. No demo declares IMAGES yet, so no browser fixture consumes it.
 */
final class ImageVariantIntegrationTest extends FrameworkIntegrationTestCase
{
    private const array TABLES = [
        'hilos_user',
        'hilos_setting',
        'hilos_session',
        'hilos_file',
        'hilos_file_variant',
    ];
    /** Node the library runs on, and the one holding the browser's connection unless a case says otherwise. */
    private const string LIBRARY_NODE = 'node-b';
    /** Cluster env values setUp sets, and tearDown removes. */
    private const array CLUSTER_ENV = ['CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_ROLE'];
    private ?DbContext $previousDb;
    private ?ClusterContext $previousCluster;
    private ?FsContext $previousFs;
    private ?HilosFiles $previousFiles;
    private ?SettingsAccessor $previousSetting;
    private string $previousApp;
    private string $previousMemoryLimit;
    private string $directory;
    private ImageVariantLibrary $library;
    private ImageVariantAgent $images;
    private ImageVariantEngine $engine;
    private ImageVariantStorage $storage;

    /** Builds fresh registry tables and isolated files, and starts the real renderer. */
    protected function setUp(): void
    {
        parent::setUp();
        self::tables(true);
        self::tables(false);
        Database::sqlRun("INSERT INTO `hilos_user` (`id`, `name`) VALUES (7, 'Owner'), (8, 'Stranger')");
        Database::sqlRun('CREATE TABLE image_variant_link (file_id INT UNSIGNED NOT NULL, '
            . 'FOREIGN KEY (file_id) REFERENCES hilos_file (id))');
        Schema::reset();
        Schema::initialize();
        $this->previousDb = Hilos::$db;
        $this->previousFs = Hilos::$fs;
        $this->previousFiles = Hilos::$files;
        $this->previousSetting = Hilos::$setting;
        $this->previousApp = Hilos::appClass();
        $this->previousMemoryLimit = ini_get('memory_limit');
        self::app(ImageVariantHilos::class);
        Hilos::$db = new ImageVariantDb();
        Hilos::$db->configure();
        Hilos::$setting = new SettingsAccessor(FilesSettingsCatalog::class);
        $this->directory = sys_get_temp_dir() . '/hilos-images-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        mkdir($this->directory . '/files');
        mkdir($this->directory . '/tmp');
        Hilos::$fs = new ImageVariantFs($this->directory);
        Hilos::$fs->configure();
        $this->storage = new ImageVariantStorage();
        Hilos::$files = new HilosFiles($this->storage);
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        putenv(EnvConstants::HILOS_FILES_XACCEL_LOCATION->name . '=');
        // A request carries its origin only on a cluster, and the daemon's own body goes to a browser of this node alone.
        $this->previousCluster = Hilos::$cluster;
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=' . self::LIBRARY_NODE);
        putenv('CLUSTER_NODE_ROLE=master');
        Hilos::$cluster = new ClusterContext();
        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_FILES_LIBRARY);
        $this->library = new ImageVariantLibrary();
        OwnershipDeclaration::claimAll($this->library);
        $this->library->onStart();
        $this->engine = new ImageVariantEngine();
        ImageVariantAgent::$testEngine = $this->engine;
        $this->images = new ImageVariantAgent();
        $this->images->onStart();
    }

    /** Restores the facade and worker memory budget, and removes every fixture. */
    protected function tearDown(): void
    {
        $this->images->onStop();
        self::assertSame($this->previousMemoryLimit, ini_get('memory_limit'));
        TruthSourceRegistry::unregisterAgent(HilosAgentType::HILOS_FILES_LIBRARY);
        ExecutionContext::clear();
        SourceChangeBus::reset();
        if (is_link($this->directory . '-node-b')) {
            unlink($this->directory . '-node-b');
        }
        foreach (['files', 'tmp'] as $folder) {
            foreach (glob($this->directory . '/' . $folder . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($this->directory . '/' . $folder);
        }
        rmdir($this->directory);
        putenv(EnvConstants::HILOS_FILES_XACCEL_LOCATION->name);
        foreach (self::CLUSTER_ENV as $key) {
            putenv($key);
        }
        Hilos::$cluster = $this->previousCluster;
        Hilos::$files = $this->previousFiles;
        Hilos::$fs = $this->previousFs;
        Hilos::$db = $this->previousDb;
        Hilos::$setting = $this->previousSetting;
        self::app($this->previousApp);
        self::tables(true);
        Schema::reset();
        parent::tearDown();
    }

    /** Exercises the library and renderer over real registry rows and files. */
    public function testFirstRequestRendersAndSecondServesTheStoredCopy(): void
    {
        $file = $this->publish();
        $request = $this->ask($file);
        self::assertSame([], $this->library->replies);
        $render = $this->takeRender();
        self::assertFalse($render->retry);
        self::assertSame($request->toArray(), $render->request->toArray());
        $result = $this->render($render);
        self::assertSame(ImageRenderOutcome::RENDERED, $result->outcome);
        $tmp = Hilos::$fs->getTmp()[$result->tmpIndex]->getPath();
        self::assertFileExists($tmp);
        $this->deliver($result);
        $copy = $this->copy($file);
        self::assertFileDoesNotExist($tmp);
        self::assertSame($result->size, $copy->size);
        self::assertSame($this->storage->read($copy->storedName), $this->reply()->body);
        self::assertSame($request->correlationId, $this->reply()->correlationId);
        self::assertSame('node-b', $this->reply()->originNodeId);
        self::assertSame('image/webp', $this->reply()->headers[HttpConstants::HEADER_CONTENT_TYPE]);
        self::assertSame('public, max-age=31536000, immutable', $this->reply()->headers[HttpConstants::HEADER_CACHE_CONTROL]);
        $header = getimagesizefromstring($this->reply()->body);
        self::assertSame([12, 6], [$header[0], $header[1]]);
        $this->ask($file);
        self::assertCount(2, $this->library->replies);
        self::assertSame([], $this->library->renders);
        self::assertSame(1, $this->engine->renders);
        self::assertCount(1, Hilos::$db->fileVariants->forFile($file->id));
    }

    /** The renderer and library resolve the same tmp index through different mount paths. */
    public function testACopyDrawnOnOneNodeIsKeptByTheLibraryOnAnother(): void
    {
        symlink($this->directory, $this->directory . '-node-b');
        $libraryFs = new ImageVariantFs($this->directory . '-node-b');
        $libraryFs->configure();
        $file = $this->publish();
        $this->ask($file);
        $result = $this->render($this->takeRender());
        $tmp = Hilos::$fs->getTmp()[$result->tmpIndex]->getPath();
        self::assertFileExists($tmp);

        $rendererFs = Hilos::$fs;
        Hilos::$fs = $libraryFs;
        try {
            $this->deliver($result);
        } finally {
            Hilos::$fs = $rendererFs;
        }

        $copy = $this->copy($file);
        self::assertSame($this->storage->read($copy->storedName), $this->reply()->body);
        self::assertFileDoesNotExist($tmp);
    }

    /** Exercises the library and renderer over real registry rows and files. */
    public function testTwoWaitingRequestsShareOneRenderAndALateRequestGetsReady(): void
    {
        $file = $this->publish();
        $first = $this->ask($file);
        $second = $this->ask($file);
        $this->ask($file);
        $late = array_pop($this->library->renders);
        $this->enqueue($this->takeRender());
        $this->enqueue($this->takeRender());
        $result = $this->tick();
        self::assertCount(2, $result->requests);
        $this->enqueue($late);
        $ready = $this->takeResult();
        self::assertSame(ImageRenderOutcome::READY, $ready->outcome);
        $this->deliver($result);
        $this->deliver($ready);
        self::assertCount(3, $this->library->replies);
        self::assertSame([$first->correlationId, $second->correlationId], array_map(
            static fn(HttpReplyDTO $reply): string => $reply->correlationId, array_slice($this->library->replies, 0, 2)));
        self::assertSame(1, $this->engine->renders);
        self::assertSame([], $this->library->renders);
    }

    /** Exercises the library and renderer over real registry rows and files. */
    public function testChangedVariantReplacesTheRowAndRemovesOldBytes(): void
    {
        $file = $this->publish();
        $this->complete($file);
        $copy = $this->copy($file);
        $id = $copy->id;
        $oldName = $copy->storedName;
        $oldSignature = $copy->signature;
        self::app(ImageVariantSmallerHilos::class);
        $this->complete($file);
        $copy = $this->copy($file);
        self::assertSame($id, $copy->id);
        self::assertNotSame($oldSignature, $copy->signature);
        self::assertNull($this->storage->size($oldName));
        $header = getimagesizefromstring($this->reply()->body);
        self::assertSame([4, 2], [$header[0], $header[1]]);
    }

    /** @return iterable<string, array{string, string}> Bytes that must not enter GD's pixel decoder successfully */
    public static function brokenImages(): iterable
    {
        yield 'text as png' => ['plain text', 'image/png'];
        yield 'broken jpeg' => ["\xff\xd8broken", 'image/jpeg'];
        $header = pack('NNCCCCC', 10000, 5000, 8, 6, 0, 0, 0);
        yield 'above ceiling' => ["\x89PNG\r\n\x1a\n" . pack('N', strlen($header)) . 'IHDR'
            . $header . pack('N', crc32('IHDR' . $header)), 'image/png'];
    }

    /**
     * @param string $bytes Invalid or oversized image
     * @param string $mimeType Registered source type
     */
    #[DataProvider('brokenImages')]
    public function testPictureRefusalIsRememberedEvenForRetry(string $bytes, string $mimeType): void
    {
        $file = $this->publish($bytes, $mimeType);
        $this->complete($file);
        self::assertSame($bytes, $this->reply()->body);
        self::assertSame('public, max-age=3600', $this->reply()->headers[HttpConstants::HEADER_CACHE_CONTROL]);
        self::assertSame(1, $this->engine->probes);
        self::assertCount(1, $this->images->warnings);
        if (str_starts_with($bytes, "\x89PNG")) {
            self::assertStringContainsString('above the 40000000-pixel ceiling', $this->images->warnings[0]);
        }
        $this->ask($file);
        $render = $this->takeRender();
        $this->enqueue(new ImageRenderSignalData($render->request, $render->fileId, $render->storedName,
            $render->mimeType, $render->variant, true));
        $result = $this->takeResult();
        self::assertSame(ImageRenderOutcome::FAILED, $result->outcome);
        $this->deliver($result);
        self::assertSame(1, $this->engine->probes);
        self::assertSame([], glob($this->directory . '/tmp/*'));
    }

    /** The copy is still drawn and kept; only the daemon's own body stays off the peer link. */
    public function testAVariantForABrowserOnAnotherNodeWithoutNginxIsRefusedAfterTheRender(): void
    {
        $file = $this->publish();
        $this->ask($file, null, 'node-c');
        $this->deliver($this->render($this->takeRender()));

        $this->copy($file);
        self::assertSame(HttpConstants::HTTP_INTERNAL_ERROR, $this->reply()->status);
        self::assertSame('node-c', $this->reply()->originNodeId);
        self::assertSame([
            "File {$file->id} variant thumb is not sent by the daemon: the browser's connection is on node node-c"
            . " and the daemon's own body does not travel between nodes; set HILOS_FILES_XACCEL_LOCATION",
        ], $this->library->errors);
    }

    /** Exercises the library and renderer over real registry rows and files. */
    public function testUnsupportedSourceSkipsTheRenderer(): void
    {
        $file = $this->publish('plain document', 'text/plain');
        $this->ask($file);
        self::assertSame('plain document', $this->reply()->body);
        self::assertSame('public, max-age=3600', $this->reply()->headers[HttpConstants::HEADER_CACHE_CONTROL]);
        self::assertSame([], $this->library->renders);
    }

    /** Exercises the library and renderer over real registry rows and files. */
    public function testMissingOriginalIsNotRememberedAndAnswersNotFound(): void
    {
        $file = $this->publish();
        $bytes = $this->storage->read($file->storedName);
        $this->storage->delete($file->storedName);
        $this->complete($file);
        self::assertSame(404, $this->reply()->status);
        self::assertSame(["File {$file->id} has a row but no file on disk"], $this->library->warnings);
        file_put_contents($this->directory . '/files/' . $file->storedName, $bytes);
        $this->complete($file);
        self::assertSame(200, $this->reply()->status);
    }

    /** Exercises the library and renderer over real registry rows and files. */
    public function testRemovedOriginalRowDiscardsTheRenderingAndAnswersNotFound(): void
    {
        $file = $this->publish();
        $this->ask($file);
        $result = $this->render($this->takeRender());
        $tmp = Hilos::$fs->getTmp()[$result->tmpIndex]->getPath();
        $file->actions->delete();
        $this->deliver($result);
        self::assertSame(404, $this->reply()->status);
        self::assertFileDoesNotExist($tmp);
    }

    /** Exercises the library and renderer over real registry rows and files. */
    public function testUnknownOrDisabledVariantIsRefusedBeforeReadingTheRegistry(): void
    {
        $db = Hilos::$db;
        Hilos::$db = null;
        try {
            $this->library->onSignalHttpRequest(new HttpRequestDTO('unknown', 'GET', '/_hilos/file',
                ['id' => '1', 'variant' => 'unknown'], null, null), SignalSource::DAEMON, 'GET /_hilos/file');
            self::assertSame(404, $this->reply()->status);
            self::app(Hilos::class);
            $this->library->onSignalHttpRequest(new HttpRequestDTO('disabled', 'GET', '/_hilos/file',
                ['id' => '1', 'variant' => 'thumb'], null, null), SignalSource::DAEMON, 'GET /_hilos/file');
            self::assertSame(404, $this->reply()->status);
            self::assertSame([], $this->library->renders);
        } finally {
            Hilos::$db = $db;
        }
    }

    /** Exercises the library and renderer over real registry rows and files. */
    public function testVariantKeepsOriginalAccessAndDoesNotJudgeItTwice(): void
    {
        $file = $this->publish(visibility: FileVisibility::OWNER);
        $this->ask($file);
        self::assertSame(401, $this->reply()->status);
        $this->ask($file, $this->session(8));
        self::assertSame(403, $this->reply()->status);
        self::assertSame([], $this->library->renders);
        $token = $this->session(7);
        $this->ask($file, $token);
        $result = $this->render($this->takeRender());
        ExecutionContext::setCurrentAgentId('framework-test-agent');
        Hilos::$db->sessions->findByToken($token)->actions->delete();
        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_FILES_LIBRARY);
        $this->deliver($result);
        self::assertSame(200, $this->reply()->status);
        self::assertSame('private, max-age=31536000, immutable', $this->reply()->headers[HttpConstants::HEADER_CACHE_CONTROL]);
    }

    /** Exercises the library and renderer over real registry rows and files. */
    public function testProjectMayGrantAccessAndNginxReceivesTheCopyName(): void
    {
        $this->library->grant = true;
        $file = $this->publish(visibility: FileVisibility::OWNER);
        putenv(EnvConstants::HILOS_FILES_XACCEL_LOCATION->name . '=/_files_internal');
        $this->complete($file);
        self::assertSame('', $this->reply()->body);
        self::assertSame('/_files_internal/' . $this->copy($file)->storedName,
            $this->reply()->headers[HttpConstants::HEADER_X_ACCEL_REDIRECT]);
    }

    /** Exercises the library and renderer over real registry rows and files. */
    public function testStorageFailureFallsBackAndTheNextRequestRecoversViaRetry(): void
    {
        $file = $this->publish();
        $this->storage->failStore = true;
        $this->complete($file);
        self::assertSame($this->storage->read($file->storedName), $this->reply()->body);
        self::assertNull(Hilos::$db->fileVariants->findFor($file->id, 'thumb'));
        self::assertSame([], glob($this->directory . '/tmp/*'));
        self::assertCount(1, glob($this->directory . '/files/*'));
        self::assertCount(1, $this->library->errors);
        $this->storage->failStore = false;
        $this->recoverViaRetry($file);
        self::assertSame(2, $this->engine->renders);
    }

    /** Exercises the library and renderer over real registry rows and files. */
    public function testLostRenderedFrameRecoversViaRetry(): void
    {
        $file = $this->publish();
        $this->ask($file);
        $lost = $this->render($this->takeRender());
        $this->recoverViaRetry($file);
        self::assertSame(2, $this->engine->renders);
        self::assertFileExists(Hilos::$fs->getTmp()[$lost->tmpIndex]->getPath(), 'Lost frames leave tmp for its future janitor');
    }

    /** Exercises the library and renderer over real registry rows and files. */
    public function testMissingCopyBytesReplaceTheExistingRowViaRetry(): void
    {
        $file = $this->publish();
        $this->complete($file);
        $copy = $this->copy($file);
        $id = $copy->id;
        $oldName = $copy->storedName;
        $this->storage->delete($oldName);
        $this->recoverViaRetry($file);
        self::assertSame($id, $this->copy($file)->id);
        self::assertNotSame($oldName, $this->copy($file)->storedName);
    }

    /** Exercises the library and renderer over real registry rows and files. */
    public function testSweptCopiesOfALinkedOriginalAreDrawnAgain(): void
    {
        $file = $this->publish();
        $this->complete($file);
        $oldName = $this->copy($file)->storedName;
        Database::sqlRun('UPDATE hilos_file SET created_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 90000), $file->id]);
        Database::sqlRun('INSERT INTO image_variant_link (file_id) VALUES (?)', [$file->id]);
        new ReflectionProperty(AbstractFilesLibraryAgent::class, 'filesSweepBacklog')->setValue($this->library, true);
        $this->library->onTick();
        self::assertTrue($file->bound);
        self::assertNull(Hilos::$db->fileVariants->findFor($file->id, 'thumb'));
        self::assertNull($this->storage->size($oldName));
        self::assertNotNull($this->storage->size($file->storedName));
        $this->recoverViaRetry($file);
    }

    /** Exercises the library and renderer over real registry rows and files. */
    public function testTwoRetriesInSeparateTicksKeepTheFirstCopyAndDiscardTheSecond(): void
    {
        $file = $this->publish();
        $this->ask($file);
        $render = $this->takeRender();
        $retry = new ImageRenderSignalData($render->request, $render->fileId, $render->storedName, $render->mimeType, $render->variant, true);
        $first = $this->render($retry);
        $second = $this->render($retry);
        $this->deliver($first);
        $id = $this->copy($file)->id;
        $name = $this->copy($file)->storedName;
        $this->deliver($second);
        self::assertSame($id, $this->copy($file)->id);
        self::assertSame($name, $this->copy($file)->storedName);
        self::assertSame(1, $this->storage->stores);
        self::assertSame([], glob($this->directory . '/tmp/*'));
        self::assertSame(2, $this->engine->renders);
    }

    /** Exercises the library and renderer over real registry rows and files. */
    public function testTemporaryWriteFailureIsNotRemembered(): void
    {
        $file = $this->publish();
        $this->engine->failWrites = 1;
        $this->complete($file);
        self::assertSame('public, max-age=3600', $this->reply()->headers[HttpConstants::HEADER_CACHE_CONTROL]);
        self::assertSame([], glob($this->directory . '/tmp/*'));
        self::assertCount(1, $this->images->errors);
        self::assertSame([], $this->images->warnings);
        $this->complete($file);
        self::assertSame('image/webp', $this->reply()->headers[HttpConstants::HEADER_CONTENT_TYPE]);
        self::assertSame(2, $this->engine->renders);
    }

    /**
     * A stale handover hint costs exactly one extra trip and never answers ready to retry.
     *
     * @param File $file Original whose handed-over copy is no longer live
     */
    private function recoverViaRetry(File $file): void
    {
        $this->ask($file);
        $before = count($this->library->replies);
        $this->enqueue($this->takeRender());
        $ready = $this->takeResult();
        self::assertSame(ImageRenderOutcome::READY, $ready->outcome);
        $this->deliver($ready);
        self::assertCount($before, $this->library->replies);
        $retry = $this->takeRender();
        self::assertTrue($retry->retry);
        $this->enqueue($retry);
        self::assertSame([], $this->images->results);
        $result = $this->tick();
        self::assertSame(ImageRenderOutcome::RENDERED, $result->outcome);
        $this->deliver($result);
        self::assertSame('image/webp', $this->reply()->headers[HttpConstants::HEADER_CONTENT_TYPE]);
        self::assertSame([], $this->library->renders);
    }

    /** @param File $file Original to request, render and serve */
    private function complete(File $file): void
    {
        $this->ask($file);
        $this->deliver($this->render($this->takeRender()));
    }

    /**
     * @param File $file Original being requested
     * @param ?string $token Presented session token
     * @param string $originNodeId Node holding the browser's connection
     * @return HttpRequestDTO Request passed to the library
     */
    private function ask(File $file, ?string $token = null, string $originNodeId = self::LIBRARY_NODE): HttpRequestDTO
    {
        $request = new HttpRequestDTO(bin2hex(random_bytes(16)), 'GET', '/_hilos/file',
            ['id' => (string)$file->id, 'variant' => 'thumb', 'v' => 'ignored'], $token, $originNodeId);
        $this->library->onSignalHttpRequest($request, SignalSource::DAEMON, 'GET /_hilos/file');
        return $request;
    }

    /** @return ImageRenderSignalData Next library request after a wire roundtrip */
    private function takeRender(): ImageRenderSignalData
    {
        $render = array_shift($this->library->renders);
        self::assertInstanceOf(ImageRenderSignalData::class, $render);
        return ImageRenderSignalData::fromArray($render->toArray());
    }

    /**
     * @param ImageRenderSignalData $render Request delivered before ticking
     * @return ImageRenderedSignalData Result sent by the next tick
     */
    private function render(ImageRenderSignalData $render): ImageRenderedSignalData
    {
        $this->enqueue($render);
        return $this->tick();
    }

    /** @param ImageRenderSignalData $render Request to queue without ticking */
    private function enqueue(ImageRenderSignalData $render): void
    {
        $this->images->onSignalAgent(new AgentSignalData($render), HilosAgentType::HILOS_FILES_LIBRARY, HilosSignalConstants::HILOS_IMAGE_RENDER);
    }

    /** @return ImageRenderedSignalData Next result, with the tick running under the images agent identity */
    private function tick(): ImageRenderedSignalData
    {
        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_IMAGES);
        try {
            $this->images->onTick();
        } finally {
            ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_FILES_LIBRARY);
        }
        return $this->takeResult();
    }

    /** @return ImageRenderedSignalData Next renderer answer after a wire roundtrip */
    private function takeResult(): ImageRenderedSignalData
    {
        $result = array_shift($this->images->results);
        self::assertInstanceOf(ImageRenderedSignalData::class, $result);
        return ImageRenderedSignalData::fromArray($result->toArray());
    }

    /** @param ImageRenderedSignalData $result Answer handed to the owning library */
    private function deliver(ImageRenderedSignalData $result): void
    {
        $this->library->onSignalAgent(new AgentSignalData($result), HilosAgentType::HILOS_IMAGES, HilosSignalConstants::HILOS_IMAGE_RENDERED);
    }

    /** @return HttpReplyDTO Most recent browser answer */
    private function reply(): HttpReplyDTO
    {
        self::assertNotEmpty($this->library->replies);
        return $this->library->replies[array_key_last($this->library->replies)];
    }

    /**
     * @param File $file Original whose thumb must be registered
     * @return FileVariant Stored thumb row
     */
    private function copy(File $file): FileVariant
    {
        $copy = Hilos::$db->fileVariants->findFor($file->id, 'thumb');
        self::assertNotNull($copy);
        return $copy;
    }

    /**
     * @param ?string $bytes Original bytes, or null to create a real PNG
     * @param string $mimeType Registered source type
     * @param FileVisibility $visibility Access policy
     * @return File Published fixture owned by user 7
     */
    private function publish(?string $bytes = null, string $mimeType = 'image/png', FileVisibility $visibility = FileVisibility::PUBLIC): File
    {
        if ($bytes === null) {
            ob_start();
            imagepng(imagecreatetruecolor(40, 20));
            $bytes = ob_get_clean();
        }
        $storedName = bin2hex(random_bytes(16)) . '.png';
        file_put_contents($this->directory . '/files/' . $storedName, $bytes);
        return Hilos::$db->files->actions->create($storedName, 'photo.png', $mimeType, strlen($bytes), hash('sha256', $bytes), 7, $visibility);
    }

    /**
     * @param int $userId Signed-in user
     * @return string Unexpired fixture session token
     */
    private function session(int $userId): string
    {
        $token = bin2hex(random_bytes(16));
        Database::sqlRun('INSERT INTO hilos_session (token, user_id, expires_at) VALUES (?, ?, ?)',
            [$token, $userId, date('Y-m-d H:i:s', time() + 3600)]);
        return $token;
    }

    /** @param class-string<Hilos> $class Active facade for this test phase */
    private static function app(string $class): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $class);
    }

    /** @param bool $down Drop instead of creating the framework stubs */
    private static function tables(bool $down): void
    {
        if ($down) {
            Database::sqlRun('DROP TABLE IF EXISTS image_variant_link');
        }
        // external-boundary: the up migration carries no suffix
        $suffix = $down ? '_down' : '';
        foreach ($down ? array_reverse(self::TABLES) : self::TABLES as $table) {
            Database::sqlRun(file_get_contents(dirname(__DIR__, 2) . "/backend/Database/Migration/Stub/create_{$table}{$suffix}.sql"));
        }
    }
}

abstract class ImageVariantHilos extends Hilos
{
    public const array IMAGE_VARIANTS = ['thumb' => [
        ImageVariant::WIDTH => 12, ImageVariant::HEIGHT => 12, ImageVariant::FIT => ImageFit::CONTAIN,
    ]];
    protected const array FEATURES = [HilosFeature::FILES, HilosFeature::IMAGES];
}

abstract class ImageVariantSmallerHilos extends ImageVariantHilos
{
    public const array IMAGE_VARIANTS = ['thumb' => [
        ImageVariant::WIDTH => 4, ImageVariant::HEIGHT => 4, ImageVariant::FIT => ImageFit::CONTAIN,
    ]];
}

final class ImageVariantDb extends HilosDbContext
{
}

final class ImageVariantFs extends FsContext
{
    /** @param string $directory Isolated filesystem root */
    public function __construct(private readonly string $directory)
    {
    }

    /** Registers the real storage directory and the temporary-file seam. */
    public function configure(): void
    {
        $this->registerDirectory(self::FILES, $this->directory . '/files', DirectoryScope::CLUSTER);
        $this->setTmpPath($this->directory . '/tmp', DirectoryScope::CLUSTER);
    }
}

final class ImageVariantLibrary extends AbstractFilesLibraryAgent
{
    /** @var list<ImageRenderSignalData> Requests sent to the renderer */
    public array $renders = [];
    /** @var list<HttpReplyDTO> Responses sent to browsers */
    public array $replies = [];
    /** @var list<string> Warning log */
    public array $warnings = [];
    /** @var list<string> Error log */
    public array $errors = [];
    public bool $grant = false;

    /**
     * Captures the typed frame at the transport seam.
     *
     * @param string $signalName Outbound signal name
     * @param SignalDataInterface $data Outbound payload
     */
    public function sendToAgent(string $signalName, SignalDataInterface $data): void
    {
        ImageVariantIntegrationTest::assertSame(HilosSignalConstants::HILOS_IMAGE_RENDER, $signalName);
        ImageVariantIntegrationTest::assertInstanceOf(ImageRenderSignalData::class, $data);
        $this->renders[] = $data;
    }

    /** @param HttpReplyDTO $reply Response captured instead of sent to the master */
    public function replyToHttpRequest(HttpReplyDTO $reply): void
    {
        $this->replies[] = $reply;
    }

    /**
     * @param File $file Original being authorized
     * @param ?Session $session Presented session
     * @return bool The project grant set by the test
     */
    protected function grantsRead(File $file, ?Session $session): bool
    {
        return $this->grant;
    }

    /** @param string $message Ignored routine journal line */
    protected function logAgentInfo(string $message): void
    {
    }

    /** @param string $message Captured warning */
    protected function logAgentWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** @param string $message Captured error */
    protected function logAgentError(string $message): void
    {
        $this->errors[] = $message;
    }
}

final class ImageVariantAgent extends ImagesAgent
{
    public static ImageVariantEngine $testEngine;
    /** @var list<ImageRenderedSignalData> Results handed to the library */
    public array $results = [];
    /** @var list<string> Picture refusals */
    public array $warnings = [];
    /** @var list<string> Attempt failures */
    public array $errors = [];

    /** @return ImageEngineInterface The counting engine installed by the case */
    public static function createEngine(): ImageEngineInterface
    {
        return self::$testEngine;
    }

    /**
     * Captures the typed frame at the transport seam.
     *
     * @param string $signalName Outbound signal name
     * @param SignalDataInterface $data Outbound payload
     */
    public function sendToAgent(string $signalName, SignalDataInterface $data): void
    {
        ImageVariantIntegrationTest::assertSame(HilosSignalConstants::HILOS_IMAGE_RENDERED, $signalName);
        ImageVariantIntegrationTest::assertInstanceOf(ImageRenderedSignalData::class, $data);
        $this->results[] = $data;
    }

    /** @param string $message Captured warning */
    protected function logAgentWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** @param string $message Captured error */
    protected function logAgentError(string $message): void
    {
        $this->errors[] = $message;
    }
}

final class ImageVariantEngine implements ImageEngineInterface
{
    public int $renders = 0;
    public int $probes = 0;
    public int $failWrites = 0;

    /**
     * @param list<ImageFormat> $outputs Required output formats
     * @return ?string Real GD capability refusal
     */
    public function unusableReason(array $outputs): ?string
    {
        return new GdImageEngine()->unusableReason($outputs);
    }

    /**
     * @param string $bytes Original image
     * @return ?ImageProbe Real header, counted before decoding
     */
    public function probe(string $bytes): ?ImageProbe
    {
        $this->probes++;
        return new GdImageEngine()->probe($bytes);
    }

    /**
     * @param string $bytes Original image
     * @param ImageProbe $probe Checked header
     * @param int $orientation EXIF orientation
     * @param ImageVariant $variant Copy declaration
     * @param string $targetPath Destination path
     * @return int Written byte count
     * @throws FileWriteException When the test injects a partial write failure
     */
    public function render(string $bytes, ImageProbe $probe, int $orientation, ImageVariant $variant, string $targetPath): int
    {
        $this->renders++;
        if ($this->failWrites > 0) {
            $this->failWrites--;
            file_put_contents($targetPath, 'partial copy');
            throw new FileWriteException('The temporary volume is full');
        }
        return new GdImageEngine()->render($bytes, $probe, $orientation, $variant, $targetPath);
    }
}

final class ImageVariantStorage implements FilesStorageInterface
{
    public bool $failStore = false;
    public int $stores = 0;

    /**
     * @param string $storedName New storage name
     * @param string $tmpIndex Temporary copy
     * @throws FileWriteException When the test injects a failure after moving the bytes
     */
    public function storeFromTmp(string $storedName, string $tmpIndex): void
    {
        $this->stores++;
        new LocalFilesStorage()->storeFromTmp($storedName, $tmpIndex);
        if ($this->failStore) {
            throw new FileWriteException('Storage refused after moving the bytes');
        }
    }

    /** @param string $storedName Stored file to remove */
    public function delete(string $storedName): void
    {
        new LocalFilesStorage()->delete($storedName);
    }

    /**
     * @param string $storedName Stored file to inspect
     * @return ?int Real byte count, or null for absent bytes
     */
    public function size(string $storedName): ?int
    {
        return new LocalFilesStorage()->size($storedName);
    }

    /**
     * @param string $storedName Stored file to read
     * @return string Real bytes
     */
    public function read(string $storedName): string
    {
        return new LocalFilesStorage()->read($storedName);
    }
}
