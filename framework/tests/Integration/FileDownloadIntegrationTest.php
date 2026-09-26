<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HttpConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Schema\Schema;
use Hilos\Database\View\Item\File;
use Hilos\Database\View\Item\Session;
use Hilos\Files\FileVisibility;
use Hilos\Files\HilosFiles;
use Hilos\Files\Library\AbstractFilesLibraryAgent;
use Hilos\Files\Storage\LocalFilesStorage;
use Hilos\Fs\Context\FsContext;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Socket\Http\DTO\HttpReplyDTO;
use Hilos\Socket\Http\DTO\HttpRequestDTO;

/**
 * Integration coverage for serving a registry file by id (HIL-138).
 *
 * The files library answers GET /_hilos/file?id=N from the real hilos_file and hilos_session
 * tables and a real files directory: the matrix of visibilities against the sessions a browser
 * can present, the two transports, and the project's extension. The agent is driven directly,
 * the way the worker hands it a parked request, and its reply is read off the signal queue it
 * leaves through.
 */
final class FileDownloadIntegrationTest extends FrameworkIntegrationTestCase
{
    /** Framework tables the cases raise, in dependency order. */
    private const array TABLES = ['hilos_session', 'hilos_file'];

    /** Owner of every fixture file. */
    private const int OWNER = 7;

    /** A signed-in user who owns none of them. */
    private const int STRANGER = 8;

    /** Internal nginx location the X-Accel case configures. */
    private const string XACCEL_LOCATION = '/_files_internal';

    private ?DbContext $previousDb = null;

    private ?FsContext $previousFs = null;

    private ?HilosFiles $previousFiles = null;

    private ?SignalRouter $previousRouter = null;

    private string $filesPath;

    /** @var array<int, string> Stored name of each fixture file, by row id */
    private array $storedNames = [];

    /**
     * @throws DatabaseException When a stub statement or the schema reset fails
     * @throws HilosException When the database context cannot be configured
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::runStubs(down: true);
        self::runStubs(down: false);
        Schema::reset();
        Schema::initialize();

        $this->previousDb = Hilos::$db;
        $this->previousFs = Hilos::$fs;
        $this->previousFiles = Hilos::$files;
        $this->previousRouter = Hilos::$sr;
        Hilos::$db = new FileDownloadTestDbContext();
        Hilos::$db->configure();
        $this->filesPath = sys_get_temp_dir() . '/hilos-files-' . bin2hex(random_bytes(6));
        mkdir($this->filesPath);
        Hilos::$fs = new FileDownloadTestFsContext($this->filesPath);
        Hilos::$fs->configure();
        Hilos::$files = new HilosFiles(new LocalFilesStorage());
        Hilos::$sr = new SignalRouter();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        putenv(EnvConstants::HILOS_FILES_XACCEL_LOCATION->name . '=');
        ExecutionContext::setCurrentAgentId(HilosAgentType::HILOS_FILES_LIBRARY);
        OwnershipDeclaration::claimAll(new FileDownloadTestAgent());
    }

    /**
     * @throws DatabaseException When dropping the stub tables fails
     */
    protected function tearDown(): void
    {
        TruthSourceRegistry::unregisterAgent(HilosAgentType::HILOS_FILES_LIBRARY);
        ExecutionContext::clear();
        SourceChangeBus::reset();
        putenv(EnvConstants::HILOS_FILES_XACCEL_LOCATION->name);

        foreach (glob($this->filesPath . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->filesPath);

        Hilos::$sr = $this->previousRouter;
        Hilos::$files = $this->previousFiles;
        Hilos::$fs = $this->previousFs;
        Hilos::$db = $this->previousDb;
        self::runStubs(down: true);
        Schema::reset();

        parent::tearDown();
    }

    /**
     * @throws HilosException When a row cannot be written or the library fails
     */
    public function testAPublicFileIsServedToABrowserWithNoSessionAtAll(): void
    {
        $fileId = $this->publish(FileVisibility::PUBLIC, 'public bytes');

        $reply = $this->ask(new FileDownloadTestAgent(), (string)$fileId, null);

        $this->assertSame(HttpConstants::HTTP_OK, $reply->status);
        $this->assertSame('public bytes', $reply->body);
        $this->assertSame('public, max-age=31536000, immutable', $reply->headers[HttpConstants::HEADER_CACHE_CONTROL]);
    }

    /**
     * @throws HilosException When a row cannot be written or the library fails
     */
    public function testAFileForTheSignedInAnswersEachKindOfViewer(): void
    {
        $agent = new FileDownloadTestAgent();
        $fileId = (string)$this->publish(FileVisibility::AUTHENTICATED, 'members only');

        $this->assertSame(HttpConstants::HTTP_UNAUTHORIZED, $this->ask($agent, $fileId, null)->status);
        $this->assertSame(HttpConstants::HTTP_UNAUTHORIZED, $this->ask($agent, $fileId, $this->session(null))->status);
        $this->assertSame(
            HttpConstants::HTTP_UNAUTHORIZED,
            $this->ask($agent, $fileId, $this->session(self::OWNER, '-1 hour'))->status,
            'an expired session is not signed in, though its row still names the user',
        );
        $served = $this->ask($agent, $fileId, $this->session(self::STRANGER));
        $this->assertSame(HttpConstants::HTTP_OK, $served->status);
        $this->assertSame('members only', $served->body);
    }

    /**
     * @throws HilosException When a row cannot be written or the library fails
     */
    public function testAnOwnersFileIsServedToItsOwnerAloneAndRefusedToOthersWith403(): void
    {
        $agent = new FileDownloadTestAgent();
        $fileId = (string)$this->publish(FileVisibility::OWNER, 'mine');

        $this->assertSame(HttpConstants::HTTP_OK, $this->ask($agent, $fileId, $this->session(self::OWNER))->status);
        $this->assertSame(HttpConstants::HTTP_FORBIDDEN, $this->ask($agent, $fileId, $this->session(self::STRANGER))->status);
        $this->assertSame(HttpConstants::HTTP_UNAUTHORIZED, $this->ask($agent, $fileId, null)->status);
    }

    /**
     * @throws HilosException When a row cannot be written or the library fails
     */
    public function testAnIdThatNamesNoFileIsNotFound(): void
    {
        $agent = new FileDownloadTestAgent();
        $fileId = $this->publish(FileVisibility::PUBLIC, 'x');

        $this->assertSame(HttpConstants::HTTP_NOT_FOUND, $this->ask($agent, (string)($fileId + 1), null)->status);
        $this->assertSame(HttpConstants::HTTP_NOT_FOUND, $this->ask($agent, 'abc', null)->status);
        $this->assertSame(HttpConstants::HTTP_NOT_FOUND, $this->ask($agent, '0', null)->status);
        $this->assertSame(HttpConstants::HTTP_NOT_FOUND, $this->ask($agent, null, null)->status);
    }

    /**
     * @throws HilosException When a row cannot be written or the library fails
     */
    public function testARowWhoseFileIsGoneIsNotFoundAndSaidInTheJournal(): void
    {
        $agent = new FileDownloadTestAgent();
        $fileId = $this->publish(FileVisibility::PUBLIC, 'x');
        unlink($this->filesPath . '/' . $this->storedNames[$fileId]);

        $this->assertSame(HttpConstants::HTTP_NOT_FOUND, $this->ask($agent, (string)$fileId, null)->status);
        $this->assertSame(["File {$fileId} has a row but no file on disk"], $agent->warnings);
    }

    /**
     * @throws HilosException When a row cannot be written or the library fails
     */
    public function testBehindNginxTheFileIsHandedToXAccelWithAnEmptyBody(): void
    {
        putenv(EnvConstants::HILOS_FILES_XACCEL_LOCATION->name . '=' . self::XACCEL_LOCATION);
        $fileId = $this->publish(FileVisibility::PUBLIC, 'nginx sends these');

        $reply = $this->ask(new FileDownloadTestAgent(), (string)$fileId, null);

        $this->assertSame(HttpConstants::HTTP_OK, $reply->status);
        $this->assertSame('', $reply->body);
        $this->assertSame(
            self::XACCEL_LOCATION . '/' . $this->storedNames[$fileId],
            $reply->headers[HttpConstants::HEADER_X_ACCEL_REDIRECT],
        );
    }

    /**
     * The project's extension may only let a viewer in, and it may let in a guest - which is how
     * a project keeps a file visible to everyone who opened the site (HIL-144's question for chat).
     *
     * @throws HilosException When a row cannot be written or the library fails
     */
    public function testTheProjectsExtensionLetsAGuestSeeAFileForTheSignedIn(): void
    {
        $fileId = (string)$this->publish(FileVisibility::AUTHENTICATED, 'for everyone who came');

        $this->assertSame(HttpConstants::HTTP_UNAUTHORIZED, $this->ask(new FileDownloadTestAgent(), $fileId, $this->session(null))->status);
        $reply = $this->ask(new FileDownloadGrantingTestAgent(), $fileId, $this->session(null));
        $this->assertSame(HttpConstants::HTTP_OK, $reply->status);
        $this->assertSame('for everyone who came', $reply->body);
    }

    /**
     * Hands one parked request to the library and reads the reply it queued.
     *
     * @param AbstractFilesLibraryAgent $agent Library answering
     * @param ?string $id Value of the id key, or null to send none
     * @param ?string $sessionToken Session token the request presents, or null for none
     * @return HttpReplyDTO Reply the library queued
     * @throws HilosException When the library fails
     */
    private function ask(AbstractFilesLibraryAgent $agent, ?string $id, ?string $sessionToken): HttpReplyDTO
    {
        $request = new HttpRequestDTO(
            str_repeat('c', 32),
            HttpConstants::METHOD_GET,
            HilosFiles::DOWNLOAD_PATH,
            $id === null ? [] : [HilosFiles::DOWNLOAD_ID_KEY => $id],
            $sessionToken,
            null,
        );

        $agent->onSignalHttpRequest($request, SignalSource::DAEMON, HttpConstants::METHOD_GET . ' ' . HilosFiles::DOWNLOAD_PATH);

        // The row writes of the fixtures queue their sync frames ahead of the reply.
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            if ($signal->signalType->getType() === SignalTypeConstants::HTTP_REPLY) {
                $this->assertInstanceOf(HttpReplyDTO::class, $signal->data);
                $this->assertNull(Hilos::$sr->getNextQueuedSignal(), 'one request, one reply');

                return $signal->data;
            }
        }

        $this->fail('The library queued no reply');
    }

    /**
     * Registers a file owned by the fixture owner and puts its bytes in the files directory.
     *
     * @param FileVisibility $visibility Visibility of the row
     * @param string $bytes Bytes of the file
     * @return int Row id
     * @throws HilosException When the row cannot be written
     */
    private function publish(FileVisibility $visibility, string $bytes): int
    {
        $storedName = 'stored-' . bin2hex(random_bytes(8)) . '.png';
        $file = Hilos::$db->files->actions->create(
            $storedName,
            'photo.png',
            'image/png',
            strlen($bytes),
            hash('sha256', $bytes),
            self::OWNER,
            $visibility,
        );
        $this->assertInstanceOf(File::class, $file);
        $id = $file->id;
        $this->assertIsInt($id);
        file_put_contents($this->filesPath . '/' . $storedName, $bytes);
        $this->storedNames[$id] = $storedName;

        return $id;
    }

    /**
     * Writes a session row and hands back its token.
     *
     * @param ?int $userId User the session is signed in as, or null for a guest
     * @param string $expiresIn Relative expiry, strtotime-style
     * @return string Session token
     * @throws DatabaseException When the row cannot be written
     */
    private function session(?int $userId, string $expiresIn = '+1 day'): string
    {
        $token = bin2hex(random_bytes(16));
        Database::sqlRun(
            'INSERT INTO `hilos_session` (`token`, `user_id`, `expires_at`) VALUES (?, ?, ?)',
            [$token, $userId, date('Y-m-d H:i:s', (int)strtotime($expiresIn))],
        );
        $this->assertInstanceOf(Session::class, Hilos::$db->sessions->findByToken($token));

        return $token;
    }

    /**
     * @param bool $down Whether to run the down stubs instead of the up ones
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
}

/**
 * Files library whose framework behavior is under test; it keeps what it logs.
 */
class FileDownloadTestAgent extends AbstractFilesLibraryAgent
{
    /** @var list<string> Warning lines */
    public array $warnings = [];

    /** @var list<string> Error lines */
    public array $errors = [];

    /**
     * @param string $message Message the library logged
     */
    protected function logAgentInfo(string $message): void
    {
    }

    /**
     * @param string $message Message the library logged
     */
    protected function logAgentWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * @param string $message Message the library logged
     */
    protected function logAgentError(string $message): void
    {
        $this->errors[] = $message;
    }
}

/**
 * Files library of a project that lets every visitor see what the signed-in may see.
 */
final class FileDownloadGrantingTestAgent extends FileDownloadTestAgent
{
    /**
     * @param File $file Registry row of the file asked for
     * @param ?Session $session Session the request presented
     * @return bool True for any session at all, a guest's included
     */
    protected function grantsRead(File $file, ?Session $session): bool
    {
        return $session !== null && $file->visibility === FileVisibility::AUTHENTICATED;
    }
}

/**
 * DB context of the case: the framework collections and nothing of a project's.
 */
final class FileDownloadTestDbContext extends HilosDbContext
{
}

/**
 * FS context naming the files directory of the case.
 */
final class FileDownloadTestFsContext extends FsContext
{
    /**
     * @param string $filesPath Files directory of the case
     */
    public function __construct(private readonly string $filesPath)
    {
    }

    /**
     * Registers the files directory.
     */
    public function configure(): void
    {
        $this->registerDirectory(FsContext::FILES, $this->filesPath);
    }
}
