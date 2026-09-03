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
 * directory: moving the live *.log files into a timestamped staging batch (HIL-870 — the archive
 * may be on another device, so rotation never writes there), keeping back the basenames the
 * runtime rotator must not move, and no-op behavior when there is nothing to rotate.
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

    public function testRotateMovesLiveLogsIntoTimestampedStagingBatch(): void
    {
        file_put_contents($this->dir . '/daemon.log', 'one');
        file_put_contents($this->dir . '/worker.log', 'two');

        $report = new LogRotator($this->dir)->rotate();

        $this->assertSame(2, $report->movedCount);
        $this->assertSame([], $report->failedFiles);
        // The live files are gone.
        $this->assertSame([], glob($this->dir . '/*.log'));

        // A single timestamped batch directory now holds both files under the staging subdir.
        $stagingDir = $this->dir . '/' . LogRotationConstants::LOG_STAGING_SUBDIR_NAME;
        $batches = glob($stagingDir . '/*', GLOB_ONLYDIR);
        $this->assertCount(1, $batches);
        $this->assertMatchesRegularExpression(
            LogRotationConstants::TIMESTAMP_DIR_NAME_PATTERN,
            basename($batches[0]),
        );
        // The report names that batch, so the caller can say where the files went.
        $this->assertSame(basename($batches[0]), $report->batchDirName);
        $this->assertSame('one', file_get_contents($batches[0] . '/daemon.log'));
        $this->assertSame('two', file_get_contents($batches[0] . '/worker.log'));
    }

    public function testRotateLeavesTheKeptBasenamesLive(): void
    {
        file_put_contents($this->dir . '/daemon.log', 'logger');
        file_put_contents($this->dir . '/daemon-raw.log', 'raw');
        file_put_contents($this->dir . '/worker.log', 'worker');

        $report = new LogRotator($this->dir, ['daemon-raw.log'])->rotate();

        $this->assertSame(2, $report->movedCount);
        $this->assertSame([$this->dir . '/daemon-raw.log'], glob($this->dir . '/*.log'));
        $this->assertSame('raw', file_get_contents($this->dir . '/daemon-raw.log'));

        $batches = glob($this->dir . '/' . LogRotationConstants::LOG_STAGING_SUBDIR_NAME . '/*', GLOB_ONLYDIR);
        $this->assertCount(1, $batches);
        $this->assertSame([], glob($batches[0] . '/daemon-raw.log'));
    }

    public function testRotateCreatesNoBatchWhenOnlyKeptFilesAreLive(): void
    {
        file_put_contents($this->dir . '/daemon-raw.log', 'raw');

        $report = new LogRotator($this->dir, ['daemon-raw.log'])->rotate();

        $this->assertSame(0, $report->movedCount);
        // No batch directory: an empty folder in staging is walked by the carrier on every tick.
        $this->assertNull($report->batchDirName);
        $this->assertFalse(is_dir($this->dir . '/' . LogRotationConstants::LOG_STAGING_SUBDIR_NAME));
    }

    public function testRotateIsNoOpWhenNoLiveLogs(): void
    {
        $report = new LogRotator($this->dir)->rotate();

        $this->assertSame(0, $report->movedCount);
        $this->assertNull($report->batchDirName);
        $this->assertFalse(is_dir($this->dir . '/' . LogRotationConstants::LOG_STAGING_SUBDIR_NAME));
    }

    public function testRotateIsNoOpWhenDirectoryMissing(): void
    {
        $report = new LogRotator($this->dir . '/does-not-exist')->rotate();

        $this->assertSame(0, $report->movedCount);
        $this->assertNull($report->batchDirName);
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
