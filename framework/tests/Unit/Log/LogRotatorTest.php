<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use ErrorException;
use Hilos\Constants\LogRotationConstants;
use Hilos\Core\Daemon\BaseManager;
use Hilos\Fs\Exception\DirectoryCreateException;
use Hilos\Log\LogRotator;
use Hilos\Utils\Exception\LogRotationException;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Unit tests for the log rotator's file moves over a fixture directory (HIL-379).
 *
 * Exercises the mechanics extracted from DockerManager::rotateLogs() against a throwaway temp
 * directory: moving the live *.log files into a timestamped staging batch (HIL-870 — the archive
 * may be on another device, so rotation never writes there), keeping back the basenames the
 * runtime rotator must not move, and no-op behavior when there is nothing to rotate.
 *
 * Three of the cases run under the error handler every Hilos manager installs (HIL-1045). A
 * rotation that calls the bare PHP primitives cannot reach its own failure branches inside a
 * Hilos process — the warning becomes an ErrorException first — so a case that proves those
 * branches reachable has to install that handler, the way the five older cases must not.
 */
final class LogRotatorTest extends TestCase
{
    private string $dir;

    private bool $handlerInstalled = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-logrotator-' . uniqid('', true);
        if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            $this->fail("Could not create fixture directory: {$this->dir}");
        }
    }

    protected function tearDown(): void
    {
        if ($this->handlerInstalled) {
            restore_error_handler();
            $this->handlerInstalled = false;
        }

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

    public function testRotateRecordsAFileItCannotMoveWhileTheManagerHandlerIsInstalled(): void
    {
        file_put_contents($this->dir . '/daemon.log', 'one');
        file_put_contents($this->dir . '/worker.log', 'two');
        foreach ($this->candidateBatchPaths() as $batchPath) {
            $this->makeDirectory($batchPath);
            $this->makeDirectory($batchPath . '/worker.log');
            file_put_contents($batchPath . '/worker.log/occupant', 'taken');
        }
        $this->installManagerErrorHandler();

        $report = new LogRotator($this->dir)->rotate();

        $this->assertSame(1, $report->movedCount);
        $this->assertSame([$this->dir . '/worker.log'], $report->failedFiles);
        // The file rotation could not take is still live, and the one it could took the batch.
        $this->assertSame([$this->dir . '/worker.log'], glob($this->dir . '/*.log'));
        $batchDir = $this->dir . '/' . LogRotationConstants::LOG_STAGING_SUBDIR_NAME
            . '/' . $report->batchDirName;
        $this->assertSame('one', file_get_contents($batchDir . '/daemon.log'));
    }

    public function testRotateRaisesItsOwnExceptionWhenTheStagingDirectoryCannotBeCreated(): void
    {
        file_put_contents($this->dir . '/daemon.log', 'one');
        file_put_contents($this->dir . '/' . LogRotationConstants::LOG_STAGING_SUBDIR_NAME, 'not a directory');
        $this->installManagerErrorHandler();

        $caught = $this->captureRotationFailure();

        $this->assertInstanceOf(LogRotationException::class, $caught);
        $this->assertStringContainsString('Cannot create staging directory', $caught->getMessage());
        // The chained cause is what tells an ErrorException-shaped regression from the real one.
        $this->assertInstanceOf(DirectoryCreateException::class, $caught->getPrevious());
    }

    public function testRotateRaisesItsOwnExceptionWhenTheTimestampDirectoryCannotBeCreated(): void
    {
        file_put_contents($this->dir . '/daemon.log', 'one');
        $this->makeDirectory($this->dir . '/' . LogRotationConstants::LOG_STAGING_SUBDIR_NAME);
        foreach ($this->candidateBatchPaths() as $batchPath) {
            file_put_contents($batchPath, 'not a directory');
        }
        $this->installManagerErrorHandler();

        $caught = $this->captureRotationFailure();

        $this->assertInstanceOf(LogRotationException::class, $caught);
        $this->assertStringContainsString('Cannot create timestamp directory', $caught->getMessage());
        $this->assertInstanceOf(DirectoryCreateException::class, $caught->getPrevious());
    }

    /**
     * Runs a rotation that is expected to fail and hands back whatever it threw.
     *
     * Written with a try/catch rather than `expectException()` so the case can assert the chained
     * cause as well as the class: an ErrorException escaping rotate() is the regression these
     * cases exist against, and a bare class assertion does not see it.
     *
     * @return ?Throwable What the rotation threw, or null when it returned
     */
    private function captureRotationFailure(): ?Throwable
    {
        try {
            new LogRotator($this->dir)->rotate();
        } catch (Throwable $failure) {
            return $failure;
        }

        return null;
    }

    /**
     * Installs the error handler every Hilos manager installs around its work.
     *
     * The same shape as {@see BaseManager::errorHandler()}: an active warning
     * becomes an ErrorException, a suppressed one is left to PHP. Restored in tearDown().
     */
    private function installManagerErrorHandler(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        $this->handlerInstalled = true;
    }

    /**
     * Names the batch paths this second's rotation could pick.
     *
     * The test reads the clock before rotate() does, so the two readings may straddle a second.
     * Preparing the fixture under both names is what keeps these cases from flaking.
     *
     * @return list<string> Absolute staging batch paths for this second and the next one
     */
    private function candidateBatchPaths(): array
    {
        $stagingDir = $this->dir . '/' . LogRotationConstants::LOG_STAGING_SUBDIR_NAME;
        $now = time();

        return [
            $stagingDir . '/' . date(LogRotationConstants::TIMESTAMP_FORMAT, $now),
            $stagingDir . '/' . date(LogRotationConstants::TIMESTAMP_FORMAT, $now + 1),
        ];
    }

    /**
     * Creates a fixture directory, failing the case when it cannot be made.
     *
     * @param string $path Absolute directory path
     */
    private function makeDirectory(string $path): void
    {
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            $this->fail("Could not create fixture directory: {$path}");
        }
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
