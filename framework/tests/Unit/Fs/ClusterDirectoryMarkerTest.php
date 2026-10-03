<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fs;

use Closure;
use Hilos\Fs\ClusterDirectoryMarker;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Fs\FsPath;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The marker of a cluster directory: written once, read by everybody, never overwritten (HIL-1242).
 *
 * Held down here: the first ensure creates the directory and writes the marker, every later one
 * reads the same file back untouched, a marker another node wrote is taken as it is, a file another
 * node is still writing is waited for, and a file of a shape this build does not write is refused
 * rather than taken for a directory without a marker. A pause the marker did not need fails the case.
 */
final class ClusterDirectoryMarkerTest extends TestCase
{
    /** @var string Directory name the marker is read for in these cases */
    private const string NAME = 'data_export';

    /** @var string Node id of the node asking */
    private const string LOCAL_NODE = 'node-a';

    /** @var string Node id of the node that wrote the marker before this one started */
    private const string OTHER_NODE = 'node-b';

    /** @var string Marker another node wrote */
    private const string OTHER_MARKER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var string Waiting line written while another node is still writing the marker */
    private const string WAITING_LINE = 'Waiting for the marker of cluster directory ' . self::NAME;

    private string $root = '';

    private string $directory = '';

    private string $logFile = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-cluster-directory-' . uniqid('', true);
        $this->directory = $this->root . DIRECTORY_SEPARATOR . self::NAME;

        $file = tempnam(sys_get_temp_dir(), 'hilos-cluster-directory-log');
        $this->assertIsString($file);
        $this->logFile = $file;
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        FsPath::delete($this->logFile);
        FsPath::delete(ClusterDirectoryMarker::pathIn($this->directory));
        FsPath::removeDirectory($this->directory);
        FsPath::removeDirectory($this->root);

        parent::tearDown();
    }

    public function testTheFirstEnsureCreatesTheDirectoryAndWritesTheMarker(): void
    {
        $row = ClusterDirectoryMarker::ensure(self::NAME, $this->directory, self::LOCAL_NODE, $this->noPause());

        $this->assertFileExists(ClusterDirectoryMarker::pathIn($this->directory));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $row->marker);
        $this->assertSame(self::LOCAL_NODE, $row->writtenBy);
        $this->assertNotSame('', $row->writtenAt);
    }

    public function testASecondEnsureReadsTheSameMarkerAndLeavesTheFileAsItWas(): void
    {
        $first = ClusterDirectoryMarker::ensure(self::NAME, $this->directory, self::LOCAL_NODE, $this->noPause());
        $written = FsPath::read(ClusterDirectoryMarker::pathIn($this->directory));

        $second = ClusterDirectoryMarker::ensure(self::NAME, $this->directory, self::OTHER_NODE, $this->noPause());

        $this->assertEquals($first, $second);
        $this->assertSame($written, FsPath::read(ClusterDirectoryMarker::pathIn($this->directory)));
    }

    public function testAMarkerAnotherNodeWroteIsReadAsItIs(): void
    {
        FsPath::ensureDirectory($this->directory);
        $this->writeMarkerFile($this->otherNodesMarker());

        $row = ClusterDirectoryMarker::ensure(self::NAME, $this->directory, self::LOCAL_NODE, $this->noPause());

        $this->assertSame(self::OTHER_MARKER, $row->marker);
        $this->assertSame(self::OTHER_NODE, $row->writtenBy);
        $this->assertSame('2026-09-30 12:00:00', $row->writtenAt);
    }

    /**
     * Two names registered on one path read one file: the second ensure finds what the first wrote.
     */
    public function testTwoNamesOnOnePathReadOneMarker(): void
    {
        $published = ClusterDirectoryMarker::ensure('published', $this->directory, self::LOCAL_NODE, $this->noPause());
        $files = ClusterDirectoryMarker::ensure('files', $this->directory, self::LOCAL_NODE, $this->noPause());

        $this->assertSame($published->marker, $files->marker);
    }

    /**
     * O_EXCL makes the file visible before its payload: a node that finds it empty waits for the
     * writer, says so in the journal, and returns what the writer wrote - not a marker of its own.
     */
    public function testAnEmptyFileIsWaitedForUntilAnotherNodeHasWrittenIt(): void
    {
        FsPath::ensureDirectory($this->directory);
        $this->writeMarkerFile('');
        $pauses = 0;

        $row = ClusterDirectoryMarker::ensure(
            self::NAME,
            $this->directory,
            self::LOCAL_NODE,
            function () use (&$pauses): void {
                $pauses++;
                $this->writeMarkerFile($this->otherNodesMarker());
            },
        );

        $this->assertSame(1, $pauses);
        $this->assertSame(self::OTHER_MARKER, $row->marker);
        $this->assertSame(1, substr_count(FsPath::read($this->logFile), self::WAITING_LINE));
    }

    /**
     * The wait has no deadline and does not fill the journal: one line on the first poll and one on
     * every 30th after it. A half-written file - not yet JSON - is waited for like an empty one.
     */
    public function testTheWaitWritesItsLineOnTheFirstPollAndOnEveryThirtiethAfterIt(): void
    {
        FsPath::ensureDirectory($this->directory);
        $this->writeMarkerFile('{"version":1,"marker":"');
        $pauses = 0;

        ClusterDirectoryMarker::ensure(
            self::NAME,
            $this->directory,
            self::LOCAL_NODE,
            function () use (&$pauses): void {
                $pauses++;
                if ($pauses === 31) {
                    $this->writeMarkerFile($this->otherNodesMarker());
                }
            },
        );

        $this->assertSame(31, $pauses);
        $this->assertSame(2, substr_count(FsPath::read($this->logFile), self::WAITING_LINE));
    }

    /**
     * @param string $contents A marker file of a shape this build does not write
     */
    #[DataProvider('foreignFiles')]
    public function testAFileThisBuildDoesNotWriteIsRefusedNotOverwritten(string $contents): void
    {
        FsPath::ensureDirectory($this->directory);
        $this->writeMarkerFile($contents);

        try {
            ClusterDirectoryMarker::ensure(self::NAME, $this->directory, self::LOCAL_NODE, $this->noPause());
            $this->fail('A foreign marker file was accepted');
        } catch (FileReadException $refusal) {
            $this->assertSame(
                'Cluster directory ' . self::NAME . ' carries ' . ClusterDirectoryMarker::pathIn($this->directory)
                . ', which is not a marker this build writes: remove it while no node of the cluster runs',
                $refusal->getMessage(),
            );
        }
        $this->assertSame($contents, FsPath::read(ClusterDirectoryMarker::pathIn($this->directory)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function foreignFiles(): array
    {
        $whole = ['version' => 1, 'marker' => self::OTHER_MARKER, 'writtenBy' => self::OTHER_NODE, 'writtenAt' => '2026-09-30 12:00:00'];
        $missingWriter = $whole;
        unset($missingWriter['writtenBy']);

        return [
            'another version' => [(string)json_encode(['version' => 2] + $whole)],
            'a field missing' => [(string)json_encode($missingWriter)],
            'a marker that is not 32 hex' => [(string)json_encode(['marker' => 'not-a-marker'] + $whole)],
            'JSON that is not an object' => ['42'],
        ];
    }

    /**
     * A node id that JSON cannot spell is a marker that cannot be written: the start is told in the
     * family of file errors it handles, and no file is left behind.
     */
    public function testAMarkerThatCannotBeEncodedIsNotWritten(): void
    {
        try {
            ClusterDirectoryMarker::ensure(self::NAME, $this->directory, "node-\xB1", $this->noPause());
            $this->fail('A marker with a node id that is not UTF-8 was written');
        } catch (FileWriteException $failure) {
            $this->assertSame(
                'Cannot encode the marker of cluster directory ' . self::NAME . ' for ' . ClusterDirectoryMarker::pathIn($this->directory),
                $failure->getMessage(),
            );
        }
        $this->assertFileDoesNotExist(ClusterDirectoryMarker::pathIn($this->directory));
    }

    public function testThePlaceNamesTheDirectoryAndItsPath(): void
    {
        $this->assertSame(
            'cluster directory data_export at /app/data/data_export',
            ClusterDirectoryMarker::place(self::NAME, '/app/data/data_export'),
        );
    }

    /**
     * @return string A whole marker file as another node writes it
     */
    private function otherNodesMarker(): string
    {
        return (string)json_encode([
            'version' => 1,
            'marker' => self::OTHER_MARKER,
            'writtenBy' => self::OTHER_NODE,
            'writtenAt' => '2026-09-30 12:00:00',
        ]);
    }

    /**
     * @param string $contents Bytes to leave in the marker file, over whatever is there
     */
    private function writeMarkerFile(string $contents): void
    {
        FsPath::write(ClusterDirectoryMarker::pathIn($this->directory), $contents);
    }

    /**
     * A pause that fails the case: the file is whole on every read here.
     *
     * @return Closure(): void
     */
    private function noPause(): Closure
    {
        return function (): void {
            $this->fail('The marker waited, but nobody else was writing it');
        };
    }
}
