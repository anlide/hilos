<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Fs\Exception\DirectoryCreateException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FilePermissionException;
use Hilos\Fs\Exception\FileWriteException;
use Hilos\Fs\FsPath;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the FsPath primitives on a real temporary directory.
 *
 * The point of every failure case here is the exception TYPE: FsPath is the only
 * place that turns a failing builtin into a typed exception, and the Backup units
 * above it see their own BackupException, so a substituted type is invisible there.
 */
final class FsPathTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-fspath-' . uniqid('', true);
        $this->assertTrue(mkdir($this->root, 0777, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testReadReturnsFileContents(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'read.txt';
        file_put_contents($path, 'payload');

        $this->assertSame('payload', FsPath::read($path));
    }

    public function testReadThrowsFileNotFoundForMissingFile(): void
    {
        $this->expectException(FileNotFoundException::class);

        FsPath::read($this->root . DIRECTORY_SEPARATOR . 'absent.txt');
    }

    public function testReadLinesYieldsLinesInFileOrderWithTheirEndings(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'lines.txt';
        file_put_contents($path, "first\nsecond\nlast");

        $this->assertSame(["first\n", "second\n", 'last'], iterator_to_array(FsPath::readLines($path)));
    }

    public function testReadLinesYieldsNothingForAnEmptyFile(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'empty.txt';
        file_put_contents($path, '');

        $this->assertSame([], iterator_to_array(FsPath::readLines($path)));
    }

    public function testReadLinesThrowsFileNotFoundOnTheFirstIteration(): void
    {
        $lines = FsPath::readLines($this->root . DIRECTORY_SEPARATOR . 'absent.txt');

        $this->expectException(FileNotFoundException::class);

        foreach ($lines as $line) {
            $this->fail("A missing file yielded {$line}");
        }
    }

    public function testReadLinesClosesTheHandleWhenTheCallerBreaks(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'break.txt';
        file_put_contents($path, "first\nsecond\nthird\n");
        $openStreams = count(get_resources('stream'));

        foreach (FsPath::readLines($path) as $line) {
            $this->assertSame("first\n", $line);
            break;
        }

        $this->assertSame($openStreams, count(get_resources('stream')));
    }

    public function testWriteOverwritesExistingContents(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'write.txt';
        file_put_contents($path, 'old');

        FsPath::write($path, 'new');

        $this->assertSame('new', file_get_contents($path));
    }

    public function testWriteThrowsFileWriteExceptionForMissingDirectory(): void
    {
        $this->expectException(FileWriteException::class);

        FsPath::write($this->root . DIRECTORY_SEPARATOR . 'absent' . DIRECTORY_SEPARATOR . 'write.txt', 'payload');
    }

    public function testAppendKeepsExistingContents(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'append.txt';
        file_put_contents($path, 'head');

        FsPath::append($path, '-tail');

        $this->assertSame('head-tail', file_get_contents($path));
    }

    public function testAppendThrowsFileWriteExceptionForMissingDirectory(): void
    {
        $this->expectException(FileWriteException::class);

        FsPath::append($this->root . DIRECTORY_SEPARATOR . 'absent' . DIRECTORY_SEPARATOR . 'append.txt', 'payload');
    }

    public function testMoveRenamesTheFile(): void
    {
        $source = $this->root . DIRECTORY_SEPARATOR . 'source.txt';
        $target = $this->root . DIRECTORY_SEPARATOR . 'target.txt';
        file_put_contents($source, 'payload');

        FsPath::move($source, $target);

        $this->assertFileDoesNotExist($source);
        $this->assertSame('payload', file_get_contents($target));
    }

    public function testMoveKeepsTheSourceWhenRenameFails(): void
    {
        $source = $this->root . DIRECTORY_SEPARATOR . 'source.txt';
        file_put_contents($source, 'payload');

        try {
            FsPath::move($source, $this->root . DIRECTORY_SEPARATOR . 'absent' . DIRECTORY_SEPARATOR . 'target.txt');
            $this->fail('move() into a missing directory must throw');
        } catch (FileMoveException) {
            $this->assertFileExists($source);
        }
    }

    public function testPublishMovesTempFileIntoPlace(): void
    {
        $tmp = $this->root . DIRECTORY_SEPARATOR . 'archive.tar.tmp';
        $final = $this->root . DIRECTORY_SEPARATOR . 'archive.tar';
        file_put_contents($tmp, 'archive');

        FsPath::publish($tmp, $final);

        $this->assertFileDoesNotExist($tmp);
        $this->assertSame('archive', file_get_contents($final));
    }

    public function testPublishRemovesTempFileWhenRenameFails(): void
    {
        $tmp = $this->root . DIRECTORY_SEPARATOR . 'orphan.tmp';
        file_put_contents($tmp, 'archive');

        try {
            FsPath::publish($tmp, $this->root . DIRECTORY_SEPARATOR . 'absent' . DIRECTORY_SEPARATOR . 'orphan');
            $this->fail('publish() into a missing directory must throw');
        } catch (FileMoveException) {
            $this->assertFileDoesNotExist($tmp);
        }
    }

    public function testCreateTempFileAppliesModeBeforeReturning(): void
    {
        $path = FsPath::createTempFile('hilos-fspath-test-', 0600);

        try {
            $this->assertFileExists($path);
            $this->assertSame(0600, fileperms($path) & 0777);
        } finally {
            unlink($path);
        }
    }

    public function testChmodAppliesMode(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'mode.txt';
        file_put_contents($path, 'payload');

        FsPath::chmod($path, 0640);

        $this->assertSame(0640, fileperms($path) & 0777);
    }

    public function testChmodThrowsFilePermissionExceptionForMissingFile(): void
    {
        $this->expectException(FilePermissionException::class);

        FsPath::chmod($this->root . DIRECTORY_SEPARATOR . 'absent.txt', 0600);
    }

    public function testEnsureDirectoryCreatesNestedDirectories(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'deeper';

        FsPath::ensureDirectory($path, 0755);

        $this->assertDirectoryExists($path);
    }

    public function testEnsureDirectoryIsNoopForExistingDirectory(): void
    {
        FsPath::ensureDirectory($this->root, 0755);

        $this->assertDirectoryExists($this->root);
    }

    public function testEnsureDirectoryThrowsDirectoryCreateExceptionWhenPathIsAFile(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'occupied';
        file_put_contents($path, 'payload');

        $this->expectException(DirectoryCreateException::class);

        FsPath::ensureDirectory($path);
    }

    public function testSizeReturnsByteCount(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'size.txt';
        file_put_contents($path, 'seven!!');

        $this->assertSame(7, FsPath::size($path));
    }

    public function testSizeThrowsFileNotFoundForMissingFile(): void
    {
        $this->expectException(FileNotFoundException::class);

        FsPath::size($this->root . DIRECTORY_SEPARATOR . 'absent.txt');
    }

    public function testDeleteRemovesExistingFile(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'delete.txt';
        file_put_contents($path, 'payload');

        FsPath::delete($path);

        $this->assertFileDoesNotExist($path);
    }

    public function testDeleteIsNoopForMissingFile(): void
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'absent.txt';

        FsPath::delete($path);

        $this->assertFileDoesNotExist($path);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
