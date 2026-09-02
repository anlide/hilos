<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\LogRotationConstants;
use Hilos\Log\LogArchivePruner;
use Hilos\Log\LogBatchTakeoutMarker;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the cleanup that removes the batches an operator carried off (HIL-382).
 *
 * The store underneath is a throwaway temp directory that the cases build by hand, the fixture
 * shape borrowed from {@see LogStoreAgentIndexTest}. The pruner takes its log root as a
 * constructor argument, so nothing here needs the environment or a running agent.
 *
 * What cannot be staged in this suite is a file that refuses to be deleted for want of a
 * permission: it runs as root in `hilos-cli-test`, where a chmod fixture lets every unlink
 * through. The refusal is therefore staged structurally instead — a batch directory that is a
 * symlink, which `rmdir()` declines whoever asks — and the invariant that a batch which could not
 * be finished keeps its confirmation is held down by the foreign-file case, which asserts the
 * marker is still on disk.
 */
final class LogArchivePrunerTest extends TestCase
{
    /** Any instant to date the fixture batches from; the pruner never compares one against a clock. */
    private const int T0 = 1_800_000_000;

    /** Stamp the fixture markers carry, which the report hands back for the journal line. */
    private const int TAKEN_AT = 1_800_000_500;

    /**
     * An instant whose batch name carries a five-digit year, so it cannot match the pattern
     * rotation writes. Far enough out that no timezone offset brings the year back to four digits.
     */
    private const int BEYOND_THE_NAME_PATTERN = 300_000_000_000;

    /** Any log basename a batch could hold. */
    private const string AGENT_LOG = 'agent-hilos_logs.log';

    /** A second one, so the cases can tell a partial removal from a whole one. */
    private const string WORKER_LOG = 'worker-1.log';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-log-pruner-' . uniqid('', true);
        if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            $this->fail("Could not create fixture directory: {$this->dir}");
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testABatchNobodyConfirmedIsLeftWhereItIs(): void
    {
        $this->makeBatch(self::T0, [self::AGENT_LOG], takenAt: null);

        $report = (new LogArchivePruner($this->dir))->prune([self::T0]);

        $this->assertSame([], $report->removedBatchTimestamps);
        $this->assertSame([], $report->failedPaths);
        $this->assertSame([], $report->keptDirNames);
        $this->assertSame([], $report->unreadableMarkerDirNames);
        $this->assertSame([self::AGENT_LOG], $this->fileNamesIn(self::T0));
    }

    public function testAConfirmedBatchGoesWholeAndReportsTheStampItCarried(): void
    {
        $this->makeBatch(self::T0, [self::AGENT_LOG, self::WORKER_LOG], self::TAKEN_AT);

        $report = (new LogArchivePruner($this->dir))->prune([self::T0]);

        // The stamp travels with the batch because the journal line is about it, and the directory
        // that held it is gone by the time the caller writes that line.
        $this->assertSame([self::T0 => self::TAKEN_AT], $report->removedBatchTimestamps);
        $this->assertSame([], $report->failedPaths);
        $this->assertDirectoryDoesNotExist($this->batchPath(self::T0));
    }

    public function testOnlyTheBatchesTheCallerNamedAreConsidered(): void
    {
        $this->makeBatch(self::T0, [self::AGENT_LOG], self::TAKEN_AT);
        $other = self::T0 + 3600;
        $this->makeBatch($other, [self::AGENT_LOG], self::TAKEN_AT);

        $report = (new LogArchivePruner($this->dir))->prune([$other]);

        $this->assertSame([$other => self::TAKEN_AT], $report->removedBatchTimestamps);
        $this->assertDirectoryExists($this->batchPath(self::T0));
    }

    public function testABatchHoldingSomethingElseKeepsItsDirectoryAndItsConfirmation(): void
    {
        $this->makeBatch(self::T0, [self::AGENT_LOG], self::TAKEN_AT);
        file_put_contents($this->batchPath(self::T0) . DIRECTORY_SEPARATOR . 'notes.txt', 'mine');

        $report = (new LogArchivePruner($this->dir))->prune([self::T0]);

        $this->assertSame([], $report->removedBatchTimestamps);
        $this->assertSame([], $report->failedPaths);
        $this->assertSame([$this->batchName(self::T0)], $report->keptDirNames);
        // The logs it put there are gone, the file it did not put there is not, and the marker
        // stays: an unfinished batch that had lost its marker would ask to be carried off again.
        $this->assertSame([], $this->fileNamesIn(self::T0));
        $this->assertFileExists($this->markerPath(self::T0));
    }

    public function testAMarkerThatCannotBeReadCountsAsNotCarriedOff(): void
    {
        $this->makeBatch(self::T0, [self::AGENT_LOG], takenAt: null);
        file_put_contents($this->markerPath(self::T0), 'this is not the json it should be');

        $report = (new LogArchivePruner($this->dir))->prune([self::T0]);

        $this->assertSame([], $report->removedBatchTimestamps);
        $this->assertSame([$this->batchName(self::T0)], $report->unreadableMarkerDirNames);
        $this->assertSame([self::AGENT_LOG], $this->fileNamesIn(self::T0));
    }

    public function testATimestampThatDoesNotNameABatchIsIgnored(): void
    {
        $this->makeBatch(self::BEYOND_THE_NAME_PATTERN, [self::AGENT_LOG], self::TAKEN_AT);

        $report = (new LogArchivePruner($this->dir))->prune([self::BEYOND_THE_NAME_PATTERN]);

        $this->assertSame([], $report->removedBatchTimestamps);
        $this->assertSame([], $report->failedPaths);
        $this->assertDirectoryExists($this->batchPath(self::BEYOND_THE_NAME_PATTERN));
    }

    public function testABatchTheArchiveNoLongerHoldsIsPassedOverInSilence(): void
    {
        $report = (new LogArchivePruner($this->dir))->prune([self::T0]);

        $this->assertSame([], $report->removedBatchTimestamps);
        $this->assertSame([], $report->failedPaths);
        $this->assertSame([], $report->keptDirNames);
        $this->assertSame([], $report->unreadableMarkerDirNames);
    }

    public function testADirectoryThatWillNotGoIsNamedAndItsBatchIsNotReportedRemoved(): void
    {
        $target = $this->dir . DIRECTORY_SEPARATOR . 'elsewhere';
        $this->assertTrue(mkdir($target, 0755, true));
        file_put_contents($target . DIRECTORY_SEPARATOR . self::AGENT_LOG, 'x');
        LogBatchTakeoutMarker::write($target, self::TAKEN_AT, null);
        $this->assertTrue(mkdir($this->archivePath(), 0755, true));
        // A batch directory that is a symlink: everything inside it goes, and `rmdir()` then
        // declines the link itself whoever is asking - a refusal root cannot override.
        $this->assertTrue(symlink($target, $this->batchPath(self::T0)));

        $report = (new LogArchivePruner($this->dir))->prune([self::T0]);

        $this->assertSame([], $report->removedBatchTimestamps);
        $this->assertSame([$this->batchPath(self::T0)], $report->failedPaths);
    }

    /**
     * Builds one batch directory with the given logs in it, confirmed or not.
     *
     * @param int $timestamp Instant the batch is named after
     * @param list<string> $logNames Basenames of the `*.log` files to put in it
     * @param ?int $takenAt Stamp to write the takeout marker with, or null to leave the batch unconfirmed
     */
    private function makeBatch(int $timestamp, array $logNames, ?int $takenAt): void
    {
        $path = $this->batchPath($timestamp);
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            $this->fail("Could not create fixture batch: {$path}");
        }
        foreach ($logNames as $name) {
            file_put_contents($path . DIRECTORY_SEPARATOR . $name, str_repeat('x', 16));
        }
        if ($takenAt !== null) {
            LogBatchTakeoutMarker::write($path, $takenAt, null);
        }
    }

    /**
     * @return string Absolute path of the archive subdirectory of the fixture log root
     */
    private function archivePath(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME;
    }

    /**
     * @param int $timestamp Instant the batch is named after
     * @return string Name of that batch's directory
     */
    private function batchName(int $timestamp): string
    {
        return date(LogRotationConstants::TIMESTAMP_FORMAT, $timestamp);
    }

    /**
     * @param int $timestamp Instant the batch is named after
     * @return string Absolute path of that batch's directory
     */
    private function batchPath(int $timestamp): string
    {
        return $this->archivePath() . DIRECTORY_SEPARATOR . $this->batchName($timestamp);
    }

    /**
     * @param int $timestamp Instant the batch is named after
     * @return string Absolute path of that batch's takeout marker
     */
    private function markerPath(int $timestamp): string
    {
        return $this->batchPath($timestamp) . DIRECTORY_SEPARATOR . LogBatchTakeoutMarker::FILE_NAME;
    }

    /**
     * @param int $timestamp Instant the batch is named after
     * @return list<string> Basenames of the `*.log` files that batch still holds, sorted
     */
    private function fileNamesIn(int $timestamp): array
    {
        $files = glob($this->batchPath($timestamp) . DIRECTORY_SEPARATOR . '*.log');
        if ($files === false) {
            return [];
        }
        $names = array_map(basename(...), $files);
        sort($names);

        return $names;
    }

    /**
     * Recursively removes a directory tree, following no symlink out of it.
     *
     * @param string $path Directory, link or file to remove
     */
    private function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            if (is_link($path) || is_file($path)) {
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
