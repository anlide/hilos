<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\LogRotationConstants;
use Hilos\Log\LogRotator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the log rotator's file moves over a fixture directory (HIL-379).
 *
 * Exercises the mechanics extracted from DockerManager::rotateLogs() against a throwaway temp
 * directory: measuring the live size, moving the live *.log files into a timestamped archive
 * batch, and no-op behavior when there is nothing to rotate.
 */
final class LogRotatorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-logrotator-' . uniqid('', true);
        if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            $this->fail("Could not create fixture directory: {$this->dir}");
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testLiveLogBytesSumsOnlyLiveLogFiles(): void
    {
        file_put_contents($this->dir . '/daemon.log', str_repeat('a', 100));
        file_put_contents($this->dir . '/worker.log', str_repeat('b', 50));
        // A non-log file and an archive tree must not count toward the live size.
        file_put_contents($this->dir . '/notes.txt', str_repeat('c', 999));
        mkdir($this->dir . '/' . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME, 0755, true);
        file_put_contents(
            $this->dir . '/' . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME . '/old.log',
            str_repeat('d', 777),
        );

        $this->assertSame(150, (new LogRotator($this->dir))->liveLogBytes());
    }

    public function testRotateMovesLiveLogsIntoTimestampedArchiveBatch(): void
    {
        file_put_contents($this->dir . '/daemon.log', 'one');
        file_put_contents($this->dir . '/worker.log', 'two');

        $moved = (new LogRotator($this->dir))->rotate();

        $this->assertSame(2, $moved);
        // The live files are gone.
        $this->assertSame([], glob($this->dir . '/*.log'));

        // A single timestamped batch directory now holds both files under the archive subdir.
        $archiveDir = $this->dir . '/' . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME;
        $batches = glob($archiveDir . '/*', GLOB_ONLYDIR);
        $this->assertCount(1, $batches);
        $this->assertMatchesRegularExpression(
            LogRotationConstants::TIMESTAMP_DIR_NAME_PATTERN,
            basename($batches[0]),
        );
        $this->assertSame('one', file_get_contents($batches[0] . '/daemon.log'));
        $this->assertSame('two', file_get_contents($batches[0] . '/worker.log'));
    }

    public function testRotateIsNoOpWhenNoLiveLogs(): void
    {
        $rotator = new LogRotator($this->dir);

        $this->assertSame(0, $rotator->rotate());
        $this->assertFalse(is_dir($this->dir . '/' . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME));
    }

    public function testRotateIsNoOpWhenDirectoryMissing(): void
    {
        $rotator = new LogRotator($this->dir . '/does-not-exist');

        $this->assertSame(0, $rotator->rotate());
        $this->assertSame(0, $rotator->liveLogBytes());
    }

    /**
     * Recursively removes a directory tree.
     *
     * @param string $path Directory or file to remove
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($path);
    }
}
