<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fs;

use Hilos\Fs\Exception\DirectoryWatchException;
use Hilos\Fs\Watch\PollingFsWatch;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the fallback engine, on real temporary directories.
 *
 * The engine that has to work everywhere is tested everywhere: it needs no extension, so
 * this file runs on every node the suite runs on. Directory mtimes are moved by hand with
 * `touch()` rather than by waiting for the clock - the engine reads whole seconds, so a
 * test that wrote a file and hoped would be a test of the filesystem's timestamp
 * granularity rather than of this class.
 */
final class PollingFsWatchTest extends TestCase
{
    /** How far a moved mtime is pushed, in seconds; any value the engine can tell apart does. */
    private const int MTIME_STEP = 10;

    private string $root;

    private PollingFsWatch $watch;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-fswatch-' . uniqid('', true);
        $this->assertTrue(mkdir($this->root, 0777, true));
        $this->watch = new PollingFsWatch();
    }

    protected function tearDown(): void
    {
        $this->watch->close();
        $this->removeTree($this->root);
    }

    public function testWatchedListsWhatWasTakenUnderWatch(): void
    {
        $scope = $this->directory('scope');

        $this->watch->watch($this->root);
        $this->watch->watch($scope);

        $this->assertSame([$this->root, $scope], $this->watch->watched());
    }

    public function testWatchingTheSameDirectoryTwiceChangesNothing(): void
    {
        $this->watch->watch($this->root);
        $this->watch->watch($this->root);

        $this->assertSame([$this->root], $this->watch->watched());
        $this->assertSame([], $this->watch->takeChanged());
    }

    public function testUnwatchDropsTheDirectory(): void
    {
        $scope = $this->directory('scope');
        $this->watch->watch($this->root);
        $this->watch->watch($scope);

        $this->watch->unwatch($scope);

        $this->assertSame([$this->root], $this->watch->watched());
    }

    public function testUnwatchIgnoresADirectoryThatIsNotWatched(): void
    {
        $this->watch->watch($this->root);

        $this->watch->unwatch($this->root . DIRECTORY_SEPARATOR . 'never-watched');

        $this->assertSame([$this->root], $this->watch->watched());
    }

    public function testWatchRefusesSomethingThatIsNotADirectory(): void
    {
        $file = $this->root . DIRECTORY_SEPARATOR . 'plain.txt';
        file_put_contents($file, 'payload');

        $this->expectException(DirectoryWatchException::class);

        $this->watch->watch($file);
    }

    public function testAQuietDirectoryReportsNothing(): void
    {
        $this->watch->watch($this->root);

        $this->assertSame([], $this->watch->takeChanged());
    }

    public function testAChangedDirectoryIsReported(): void
    {
        $scope = $this->directory('scope');
        $this->watch->watch($this->root);
        $this->watch->watch($scope);

        $this->moveMtime($scope);

        $this->assertSame([$scope], $this->watch->takeChanged());
    }

    public function testAReportedChangeIsNotReportedAgain(): void
    {
        $this->watch->watch($this->root);
        $this->moveMtime($this->root);
        $this->assertSame([$this->root], $this->watch->takeChanged());

        // Also returns the engine to a state where it may poll, which is what discarding
        // right before a full read is for.
        $this->watch->discardPending();

        $this->assertSame([], $this->watch->takeChanged());
    }

    public function testASecondPollWithinTheIntervalIsSkipped(): void
    {
        $this->watch->watch($this->root);
        $this->assertSame([], $this->watch->takeChanged());

        $this->moveMtime($this->root);

        $this->assertSame([], $this->watch->takeChanged());
    }

    public function testDiscardPendingAbsorbsWhatHappenedBeforeIt(): void
    {
        $this->watch->watch($this->root);
        $this->moveMtime($this->root);

        $this->watch->discardPending();

        $this->assertSame([], $this->watch->takeChanged());
    }

    public function testDiscardPendingDoesNotAbsorbWhatHappensAfterIt(): void
    {
        $this->watch->watch($this->root);
        $this->watch->discardPending();

        $this->moveMtime($this->root);

        $this->assertSame([$this->root], $this->watch->takeChanged());
    }

    public function testADirectoryThatDisappearedIsAChange(): void
    {
        $scope = $this->directory('scope');
        $this->watch->watch($scope);

        $this->assertTrue(rmdir($scope));

        $this->assertSame([$scope], $this->watch->takeChanged());
    }

    public function testCloseForgetsEverything(): void
    {
        $this->watch->watch($this->root);

        $this->watch->close();

        $this->assertSame([], $this->watch->watched());
    }

    /**
     * @param string $name Basename of a subdirectory to create under the test root
     * @return string Absolute path of the created directory
     */
    private function directory(string $name): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . $name;
        $this->assertTrue(mkdir($path, 0777, true));

        return $path;
    }

    /**
     * Pushes a directory's mtime forward, which is what the engine reads as a change.
     *
     * @param string $directory Absolute path of a watched directory
     */
    private function moveMtime(string $directory): void
    {
        clearstatcache(true, $directory);
        $this->assertTrue(touch($directory, (int)filemtime($directory) + self::MTIME_STEP));
    }

    /**
     * @param string $path Absolute path of a directory to remove with everything under it
     */
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
            if (is_dir($child)) {
                $this->removeTree($child);

                continue;
            }
            unlink($child);
        }
        rmdir($path);
    }
}
