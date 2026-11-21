<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Core\CLI\Commands;

use Hilos\Core\CLI\Commands\TestPathSweeper;
use Hilos\Fs\ClusterDirectoryMarker;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\FsPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the sweep the test-only reset commands empty a directory with.
 *
 * The tally is worth a test because a caller reports it to an operator, but the rule this file
 * really exists for is the other one: the sweep must not take the file that makes an otherwise
 * empty directory exist in git. That rule was learnt the plain way - the first run of the backup
 * reset deleted `demo/chat/data/backup/.gitignore`, which is the only thing git knows about that
 * directory, so a fresh checkout would have come without it.
 */
final class TestPathSweeperTest extends TestCase
{
    /** @var string Directory this test builds and takes down again */
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hilos-sweeper-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/archive/batch-1', 0o777, true);
    }

    protected function tearDown(): void
    {
        new TestPathSweeper()->emptyDirectory($this->root);
        self::removeWhatTheSweepKeeps($this->root);
    }

    public function testItEmptiesTheDirectoryAndKeepsTheDirectoryItself(): void
    {
        file_put_contents($this->root . '/archive/batch-1/daemon.log', 'x');
        file_put_contents($this->root . '/daemon.log', 'x');

        $sweeper = new TestPathSweeper();
        $sweeper->emptyDirectory($this->root);

        $this->assertSame([], $sweeper->failed());
        $this->assertSame(4, $sweeper->removed(), 'the two files, the batch and the archive');
        $this->assertDirectoryExists($this->root);
        $this->assertFileDoesNotExist($this->root . '/daemon.log');
    }

    public function testItNeverTakesTheFileThatMakesTheDirectoryExistInGit(): void
    {
        file_put_contents($this->root . '/.gitignore', '*');
        file_put_contents($this->root . '/archive/.gitkeep', '');
        file_put_contents($this->root . '/archive/batch-1/daemon.log', 'x');

        new TestPathSweeper()->emptyDirectory($this->root);

        $this->assertFileExists($this->root . '/.gitignore');
        $this->assertFileExists($this->root . '/archive/.gitkeep');
        $this->assertDirectoryDoesNotExist($this->root . '/archive/batch-1');
    }

    public function testItNeverTakesTheClusterDirectoryMarker(): void
    {
        file_put_contents($this->root . '/' . ClusterDirectoryMarker::FILE_NAME, '{}');
        file_put_contents($this->root . '/archive/batch-1/daemon.log', 'x');

        new TestPathSweeper()->emptyDirectory($this->root);

        $this->assertFileExists($this->root . '/' . ClusterDirectoryMarker::FILE_NAME);
        $this->assertDirectoryDoesNotExist($this->root . '/archive/batch-1');
    }

    public function testAGlobSweepSpareThemToo(): void
    {
        file_put_contents($this->root . '/.gitkeep', '');
        file_put_contents($this->root . '/daemon.log', 'x');

        $sweeper = new TestPathSweeper();
        $sweeper->removeMatching($this->root . '/*');

        $this->assertFileExists($this->root . '/.gitkeep');
        $this->assertFileDoesNotExist($this->root . '/daemon.log');
        $this->assertSame([], $sweeper->failed());
    }

    /**
     * Takes down what the sweep is written to leave behind, so the box keeps no temp trees.
     *
     * The sweep spares the markers on purpose and the directories holding them with it, so a
     * teardown that only swept would leave its own root standing after every case.
     *
     * @param string $path Directory to take down, with everything under it
     */
    private static function removeWhatTheSweepKeeps(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) ? self::removeWhatTheSweepKeeps($child) : unlink($child);
        }

        rmdir($path);
    }

    public function testAPathTheDeleteLeavesInPlaceIsReportedAsFailedNotRemoved(): void
    {
        // A dangling symlink is what is_file() denies: the seam's delete leaves it without a word,
        // and what counts as removed is a path that is gone after the call.
        $link = $this->root . '/dangling';
        symlink($this->root . '/never-made', $link);

        try {
            $sweeper = new TestPathSweeper();
            $sweeper->emptyDirectory($this->root);

            $this->assertSame([$link], $sweeper->failed());
            $this->assertSame(2, $sweeper->removed(), 'the batch and the archive');
            $this->assertTrue(is_link($link));
        } finally {
            unlink($link);
        }
    }

    /** A file at the scope address must not look like an absent scope. */
    public function testAnExistingFileIsReportedAsFailed(): void
    {
        $path = $this->root . '/scope';
        file_put_contents($path, 'x');
        $sweeper = new TestPathSweeper();
        $sweeper->emptyDirectory($path);

        $this->assertSame([$path], $sweeper->failed());
        $this->assertSame(0, $sweeper->removed());
        $this->assertFileExists($path);
    }

    /** A listing failure at the scope is an incomplete sweep. */
    public function testAnUnreadableScopeIsReportedAsFailed(): void
    {
        $this->withRestrictedDirectory($this->root, 0111, function (): void {
            $sweeper = new TestPathSweeper();
            $sweeper->emptyDirectory($this->root);

            $this->assertSame([$this->root], $sweeper->failed());
            $this->assertSame(0, $sweeper->removed());
        });
    }

    /** An inaccessible parent does not prove the requested scope is missing. */
    public function testAnInaccessibleScopeIsReportedAsFailed(): void
    {
        $scope = $this->root . '/archive/batch-1';
        $this->withRestrictedDirectory($this->root . '/archive', 0000, function () use ($scope): void {
            $sweeper = new TestPathSweeper();
            $sweeper->emptyDirectory($scope);

            $this->assertSame([$scope], $sweeper->failed());
            $this->assertSame(0, $sweeper->removed());
        });
    }

    /** A failed nested listing must not authorize removal of that directory. */
    public function testAnUnreadableNestedDirectoryIsReportedOnceAndKept(): void
    {
        $path = $this->root . '/archive/batch-1';
        $this->withRestrictedDirectory($path, 0111, function () use ($path): void {
            $sweeper = new TestPathSweeper();
            $sweeper->emptyDirectory($this->root);

            $this->assertSame([$path], $sweeper->failed());
            $this->assertSame(0, $sweeper->removed());
            $this->assertDirectoryExists($path);
        });
    }

    /** A failed unlink is reported without counting the file or its parent twice. */
    public function testAFileThatCannotBeDeletedIsReportedOnce(): void
    {
        $path = $this->root . '/archive/daemon.log';
        file_put_contents($path, 'x');
        file_put_contents($this->root . '/archive/batch-1/.gitkeep', '');
        $this->withRestrictedDirectory($this->root . '/archive', 0555, function () use ($path): void {
            $sweeper = new TestPathSweeper();
            $sweeper->emptyDirectory($this->root);

            $this->assertSame([$path], $sweeper->failed());
            $this->assertSame(0, $sweeper->removed());
            $this->assertFileExists($path);
        });
    }

    /** A failed rmdir is a failed path, even when the directory is empty. */
    public function testADirectoryThatCannotBeDeletedIsReportedOnce(): void
    {
        $path = $this->root . '/archive/batch-1';
        $this->withRestrictedDirectory($this->root . '/archive', 0555, function () use ($path): void {
            $sweeper = new TestPathSweeper();
            $sweeper->removeMatching($path);

            $this->assertSame([$path], $sweeper->failed());
            $this->assertSame(0, $sweeper->removed());
            $this->assertDirectoryExists($path);
        });
    }

    /**
     * Exercises permissions under root too, retaining the real UID for restoration in finally.
     *
     * @param string $path Directory whose access is restricted
     * @param int $mode Permissions during the callback
     * @param callable(): void $callback Assertions made with unprivileged access
     */
    private function withRestrictedDirectory(string $path, int $mode, callable $callback): void
    {
        $originalUid = posix_geteuid();
        $unprivilegedUid = $originalUid;
        if ($originalUid === 0) {
            $nobody = posix_getpwnam('nobody');
            $this->assertIsArray($nobody);
            $unprivilegedUid = $nobody['uid'];
            $this->assertNotSame(0, $unprivilegedUid);
        }

        foreach ([FsPath::class, DirectoryNotFoundException::class, FileReadException::class, FileDeleteException::class] as $class) {
            $this->assertTrue(class_exists($class));
        }
        foreach ([$this->root, $this->root . '/archive', $this->root . '/archive/batch-1'] as $directory) {
            FsPath::chmod($directory, 0777);
        }

        try {
            FsPath::chmod($path, $mode);
            $this->assertTrue(posix_seteuid($unprivilegedUid));
            $this->assertSame($unprivilegedUid, posix_geteuid());
            $callback();
        } finally {
            $restored = posix_seteuid($originalUid);
            if ($restored && is_dir($path)) {
                FsPath::chmod($path, 0777);
            }
            $this->assertTrue($restored);
            $this->assertSame($originalUid, posix_geteuid());
        }
    }

    public function testADirectoryThatIsNotThereIsNothingToDo(): void
    {
        $sweeper = new TestPathSweeper();
        $sweeper->emptyDirectory($this->root . '/never-made');

        $this->assertSame(0, $sweeper->removed());
        $this->assertSame([], $sweeper->failed());
    }

    /** @return array<string, array{string}> Links at each boundary the sweep can enter through */
    public static function directoryLinks(): array
    {
        return [
            'scope' => ['scope'],
            'scope with trailing separator' => ['trailing'],
            'scope component' => ['component'],
            'nested entry' => ['nested'],
            'glob through a scope component' => ['glob'],
        ];
    }

    /** @param string $location Position of the link relative to the requested scope */
    #[DataProvider('directoryLinks')]
    public function testDirectoryLinksLeaveTheirTargetsIntact(string $location): void
    {
        $target = $this->root . '/neighbor/node';
        mkdir($target, 0777, true);
        file_put_contents($target . '/sentinel', 'keep');
        file_put_contents($target . '/' . ClusterDirectoryMarker::FILE_NAME, 'marker');
        mkdir($this->root . '/journal');
        $scope = $this->root . '/journal/test/node';
        if (in_array($location, ['component', 'glob'], true)) {
            $link = dirname($scope);
            symlink(dirname($target), $link);
        } else {
            mkdir(dirname($scope));
            if ($location === 'nested') {
                mkdir($scope);
            }
            $link = $location === 'nested' ? $scope . '/linked' : $scope;
            symlink($target, $link);
        }

        try {
            $sweeper = new TestPathSweeper();
            if ($location === 'glob') {
                $sweeper->removeMatching(dirname($scope) . '/*');
            } else {
                $sweeper->emptyDirectory($location === 'trailing' ? $scope . '/' : $scope);
            }

            self::assertFileExists($target . '/sentinel');
            self::assertSame('keep', file_get_contents($target . '/sentinel'));
            self::assertSame('marker', file_get_contents($target . '/' . ClusterDirectoryMarker::FILE_NAME));
            self::assertSame([$location === 'nested' ? $link : ($location === 'trailing' ? $scope . '/' : $scope)], $sweeper->failed());
            self::assertSame(0, $sweeper->removed());
            self::assertTrue(is_link($link));
        } finally {
            unlink($link);
        }
    }
}
