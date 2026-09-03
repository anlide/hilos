<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\LogRotationConstants;
use Hilos\Log\LogBatchCarrier;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the second step of rotation: staging → archive (HIL-870).
 *
 * Over a throwaway temp directory, the fixture shape borrowed from {@see LogRotatorTest}. One
 * device is all a unit test has, so the whole-directory rename — the ordinary installation — is
 * covered directly, while the copying half is reached by making the rename impossible in the one
 * way a single device allows: an `.incoming-` directory already there, which is exactly the state
 * an interrupted carry leaves. That is deliberate, and the same trick HIL-480 could not use for
 * its device gate, which is why the gate stayed uncovered and this does not.
 */
final class LogBatchCarrierTest extends TestCase
{
    /** Name of a batch, in the shape rotation writes it. */
    private const string BATCH = '2026-09-03-10-00-00';

    /** A second, older batch, for the order the carrier takes them in. */
    private const string OLDER_BATCH = '2026-09-03-09-00-00';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-logcarrier-' . uniqid('', true);
        if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            $this->fail("Could not create fixture directory: {$this->dir}");
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testPendingBatchesAreTheTimestampedDirectoriesOldestFirst(): void
    {
        $this->stagedBatch(self::BATCH, ['daemon.log' => 'one']);
        $this->stagedBatch(self::OLDER_BATCH, ['daemon.log' => 'two']);
        // Neither of these is a batch: one is not a timestamp, the other is a file.
        $this->stagedBatch('not-a-batch', ['daemon.log' => 'three']);
        file_put_contents($this->stagingPath() . DIRECTORY_SEPARATOR . 'stray.log', 'four');

        $names = new LogBatchCarrier($this->dir)->pendingBatchNames();

        $this->assertSame([self::OLDER_BATCH, self::BATCH], $names);
    }

    public function testPendingBatchesAreEmptyWhenNothingHasRotated(): void
    {
        $this->assertSame([], new LogBatchCarrier($this->dir)->pendingBatchNames());
    }

    public function testABatchMovesByRenamingTheWholeDirectory(): void
    {
        $this->stagedBatch(self::BATCH, ['daemon.log' => 'one', 'worker-1.log' => 'two']);

        $report = new LogBatchCarrier($this->dir)->carry(self::BATCH);

        $this->assertNull($report->failure);
        $this->assertTrue($report->renamedWhole);
        // Nothing was copied: on one device the carry is a rename and the file count stays at zero.
        $this->assertSame(0, $report->movedFileCount);
        $this->assertFalse(is_dir($this->stagingPath() . DIRECTORY_SEPARATOR . self::BATCH));
        $this->assertSame('one', file_get_contents($this->archivedFile(self::BATCH, 'daemon.log')));
        $this->assertSame('two', file_get_contents($this->archivedFile(self::BATCH, 'worker-1.log')));
    }

    public function testAnInterruptedCarryIsFinishedByTheNextOne(): void
    {
        $this->stagedBatch(self::BATCH, ['daemon.log' => 'one', 'worker-1.log' => 'two']);
        // What an interrupted carry leaves: the first file already in the incoming directory and
        // gone from staging, the second still waiting. Its presence is also what sends this carry
        // down the copying branch instead of the rename.
        $incoming = $this->archivePath() . DIRECTORY_SEPARATOR
            . LogRotationConstants::INCOMING_DIR_PREFIX . self::BATCH;
        $this->makeDirectory($incoming);
        file_put_contents($incoming . DIRECTORY_SEPARATOR . 'daemon.log', 'one');
        unlink($this->stagingPath() . DIRECTORY_SEPARATOR . self::BATCH . DIRECTORY_SEPARATOR . 'daemon.log');

        $report = new LogBatchCarrier($this->dir)->carry(self::BATCH);

        $this->assertNull($report->failure);
        $this->assertFalse($report->renamedWhole);
        $this->assertSame(1, $report->movedFileCount);
        // The batch is whole even though only half of it travelled this time.
        $this->assertSame('one', file_get_contents($this->archivedFile(self::BATCH, 'daemon.log')));
        $this->assertSame('two', file_get_contents($this->archivedFile(self::BATCH, 'worker-1.log')));
        $this->assertFalse(is_dir($incoming));
        $this->assertFalse(is_dir($this->stagingPath() . DIRECTORY_SEPARATOR . self::BATCH));
    }

    public function testAHalfCarriedBatchIsNotABatchYet(): void
    {
        $incoming = LogRotationConstants::INCOMING_DIR_PREFIX . self::BATCH;
        $this->stagedBatch($incoming, ['daemon.log' => 'half']);

        // The leading dot is what keeps a batch that has not arrived out of the index, away from
        // the cleanup, and out of the carrier's own queue: the name is not one anybody recognizes.
        $this->assertSame(0, preg_match(LogRotationConstants::TIMESTAMP_DIR_NAME_PATTERN, $incoming));
        $this->assertSame([], new LogBatchCarrier($this->dir)->pendingBatchNames());
    }

    public function testABatchIsNeverMergedIntoOneOfTheSameNameInTheArchive(): void
    {
        $this->stagedBatch(self::BATCH, ['daemon.log' => 'staged']);
        $this->makeDirectory($this->archivePath() . DIRECTORY_SEPARATOR . self::BATCH);
        file_put_contents($this->archivedFile(self::BATCH, 'daemon.log'), 'archived');

        $report = new LogBatchCarrier($this->dir)->carry(self::BATCH);

        $this->assertSame('the archive already holds a batch of that name', $report->failure);
        // Both sides are exactly as they were: the collision means something is broken, and
        // overwriting the archived copy would destroy the half nobody can get back.
        $this->assertSame('archived', file_get_contents($this->archivedFile(self::BATCH, 'daemon.log')));
        $this->assertSame(
            'staged',
            file_get_contents($this->stagingPath() . DIRECTORY_SEPARATOR . self::BATCH . '/daemon.log'),
        );
    }

    public function testAnEmptyStagingLeftoverIsClearedRatherThanCalledACollision(): void
    {
        // What a carry interrupted between publishing the batch and removing its source leaves.
        $this->makeDirectory($this->stagingPath() . DIRECTORY_SEPARATOR . self::BATCH);
        $this->makeDirectory($this->archivePath() . DIRECTORY_SEPARATOR . self::BATCH);
        file_put_contents($this->archivedFile(self::BATCH, 'daemon.log'), 'archived');

        $report = new LogBatchCarrier($this->dir)->carry(self::BATCH);

        $this->assertNull($report->failure);
        $this->assertFalse(is_dir($this->stagingPath() . DIRECTORY_SEPARATOR . self::BATCH));
        $this->assertSame('archived', file_get_contents($this->archivedFile(self::BATCH, 'daemon.log')));
    }

    public function testANameThatIsNotABatchIsRefused(): void
    {
        $report = new LogBatchCarrier($this->dir)->carry('../escape');

        $this->assertSame('the name is not a rotation batch', $report->failure);
    }

    public function testStagingWeighsTheFilesOfEveryWaitingBatch(): void
    {
        $this->stagedBatch(self::BATCH, ['daemon.log' => str_repeat('x', 100)]);
        $this->stagedBatch(self::OLDER_BATCH, ['daemon.log' => str_repeat('x', 40)]);

        $this->assertSame(140, new LogBatchCarrier($this->dir)->stagingBytes());
    }

    /**
     * @return string Staging subtree of the fixture
     */
    private function stagingPath(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_STAGING_SUBDIR_NAME;
    }

    /**
     * @return string Archive subtree of the fixture
     */
    private function archivePath(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME;
    }

    /**
     * @param string $batchName Name of the batch directory
     * @param string $fileName Basename inside it
     * @return string Path of that file inside the archive
     */
    private function archivedFile(string $batchName, string $fileName): string
    {
        return $this->archivePath() . DIRECTORY_SEPARATOR . $batchName . DIRECTORY_SEPARATOR . $fileName;
    }

    /**
     * Puts one batch in the staging directory, as rotation would have left it.
     *
     * @param string $batchName Name of the batch directory
     * @param array<string, string> $files Basename → contents
     */
    private function stagedBatch(string $batchName, array $files): void
    {
        $path = $this->stagingPath() . DIRECTORY_SEPARATOR . $batchName;
        $this->makeDirectory($path);
        foreach ($files as $name => $contents) {
            file_put_contents($path . DIRECTORY_SEPARATOR . $name, $contents);
        }
    }

    /**
     * @param string $path Directory to create, parents included
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
