<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Fs;

use Hilos\Fs\Exception\DirectoryWatchException;
use Hilos\Fs\Watch\FsWatch;
use Hilos\Fs\Watch\FsWatchInterface;
use Hilos\Fs\Watch\InotifyFsWatch;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the kernel-backed engine, on real temporary directories.
 *
 * Skipped where ext-inotify is absent, which is exactly why the extension is installed into
 * the test image: the suite is only a proof of two engines if it really runs both. What is
 * asserted here is the CONTRACT of {@see FsWatchInterface} rather than the event masks, so
 * the two implementations are held to one description.
 *
 * Events are read out of a queue the kernel fills asynchronously, so every assertion goes
 * through {@see changedWithin()} - a bounded wait rather than a fixed sleep, which keeps the
 * test honest on a loaded machine without paying for the timeout when it passes.
 */
final class InotifyFsWatchTest extends TestCase
{
    /** Longest a queued event is waited for before the assertion is allowed to fail. */
    private const int EVENT_TIMEOUT_MICROSECONDS = 2_000_000;

    /** How long each turn of the bounded wait sleeps. */
    private const int EVENT_POLL_MICROSECONDS = 2_000;

    private string $root;

    private FsWatchInterface $watch;

    /** @var ?FsWatchInterface Second watch on the same directory, opened only where a test needs one */
    private ?FsWatchInterface $witness = null;

    protected function setUp(): void
    {
        if (!extension_loaded('inotify')) {
            $this->markTestSkipped('ext-inotify is not loaded on this node');
        }

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-inotify-' . uniqid('', true);
        $this->assertTrue(mkdir($this->root, 0777, true));
        $this->watch = FsWatch::open();
        $this->assertInstanceOf(InotifyFsWatch::class, $this->watch);
    }

    protected function tearDown(): void
    {
        if (!extension_loaded('inotify')) {
            return;
        }

        $this->watch->close();
        $this->witness?->close();
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
    }

    public function testUnwatchDropsTheDirectory(): void
    {
        $scope = $this->directory('scope');
        $this->watch->watch($this->root);
        $this->watch->watch($scope);

        $this->watch->unwatch($scope);

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

    public function testAPublishedFileIsReported(): void
    {
        $scope = $this->directory('scope');
        $this->watch->watch($scope);

        $this->publish($scope, 'archive.tar');

        $this->assertSame([$scope], $this->changedWithin($this->watch));
    }

    public function testADeletedFileIsReported(): void
    {
        $scope = $this->directory('scope');
        $file = $this->publish($scope, 'archive.tar');
        $this->watch->watch($scope);

        $this->assertTrue(unlink($file));

        $this->assertSame([$scope], $this->changedWithin($this->watch));
    }

    public function testOnlyTheDirectoryTheChangeHappenedInIsReported(): void
    {
        $scope = $this->directory('scope');
        $other = $this->directory('other');
        $this->watch->watch($scope);
        $this->watch->watch($other);

        $this->publish($other, 'archive.tar');

        $this->assertSame([$other], $this->changedWithin($this->watch));
    }

    public function testAReportedChangeIsNotReportedAgain(): void
    {
        $scope = $this->directory('scope');
        $this->watch->watch($scope);
        $this->publish($scope, 'archive.tar');
        $this->assertSame([$scope], $this->changedWithin($this->watch));

        $this->assertSame([], $this->watch->takeChanged());
    }

    public function testDiscardPendingAbsorbsWhatHappenedBeforeIt(): void
    {
        $scope = $this->directory('scope');
        $witness = $this->witness($scope);
        $this->watch->watch($scope);

        $this->publish($scope, 'archive.tar');
        // The witness has been watching since before the write, so its report is the proof
        // that the kernel really queued events for the watch under test as well - without it
        // this test would pass on a queue that is merely still empty.
        $this->assertSame([$scope], $this->changedWithin($witness));

        $this->watch->discardPending();

        $this->assertSame([], $this->watch->takeChanged());
    }

    public function testDiscardPendingDoesNotAbsorbWhatHappensAfterIt(): void
    {
        $scope = $this->directory('scope');
        $this->watch->watch($scope);
        $this->watch->discardPending();

        $this->publish($scope, 'archive.tar');

        $this->assertSame([$scope], $this->changedWithin($this->watch));
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
     * Writes a file the way this framework publishes one: a temp file, then a rename into place.
     *
     * @param string $directory Absolute path of the directory to publish into
     * @param string $name Published basename
     * @return string Absolute path of the published file
     */
    private function publish(string $directory, string $name): string
    {
        $tmp = $directory . DIRECTORY_SEPARATOR . $name . '.tmp';
        $final = $directory . DIRECTORY_SEPARATOR . $name;
        file_put_contents($tmp, 'payload');
        $this->assertTrue(rename($tmp, $final));

        return $final;
    }

    /**
     * Opens a second watch on one directory, closed by {@see tearDown()} whatever happens.
     *
     * @param string $directory Absolute path both watches will be watching
     * @return FsWatchInterface The second watch, already watching
     * @throws DirectoryWatchException When the directory cannot be watched
     */
    private function witness(string $directory): FsWatchInterface
    {
        $witness = FsWatch::open();
        $this->witness = $witness;
        $witness->watch($directory);

        return $witness;
    }

    /**
     * @param FsWatchInterface $watch Watch to ask
     * @return list<string> Directories it reports, waiting out the queue's latency
     */
    private function changedWithin(FsWatchInterface $watch): array
    {
        $waited = 0;
        while ($waited < self::EVENT_TIMEOUT_MICROSECONDS) {
            $changed = $watch->takeChanged();
            if ($changed !== []) {
                return $changed;
            }

            usleep(self::EVENT_POLL_MICROSECONDS);
            $waited += self::EVENT_POLL_MICROSECONDS;
        }

        return [];
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
