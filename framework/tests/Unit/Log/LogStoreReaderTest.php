<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\LogRotationConstants;
use Hilos\Log\LogKeySummary;
use Hilos\Log\LogStoreReader;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the log store reader's classification and its two walks (HIL-753).
 *
 * The reader arrived with HIL-383 untested, and this leaf changes exactly the part a test can
 * pin down: the classifier gains a fourth class for the daemon's own streams, matched by exact
 * basename rather than by prefix, and the walk splits into the full {@see LogStoreReader::read()}
 * and the cheap {@see LogStoreReader::readLiveFiles()}. Everything runs over a throwaway temp
 * directory, the fixture shape borrowed from {@see LogRotatorTest}.
 */
final class LogStoreReaderTest extends TestCase
{
    /** Basename of the daemon's main log, as `DAEMON_LOG_FILE` names it in every demo. */
    private const string DAEMON_LOG = 'daemon.log';

    /** Basename of the daemon's error log, as `DAEMON_ERROR_LOG_FILE` names it in every demo. */
    private const string DAEMON_ERROR_LOG = 'daemon-error.log';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-logstore-' . uniqid('', true);
        if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            $this->fail("Could not create fixture directory: {$this->dir}");
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testClassifiesLiveFilesByExactDaemonNameAndByPrefix(): void
    {
        $this->writeLive(self::DAEMON_LOG, 10);
        $this->writeLive(self::DAEMON_ERROR_LOG, 20);
        $this->writeLive('agent-hilos_logs.log', 30);
        $this->writeLive('worker-1.log', 40);
        $this->writeLive('worker-monopolistic-2.log', 50);

        $classes = [];
        foreach ($this->reader()->read()->keys() as $key) {
            $classes[$key->key] = $key->class;
        }

        $this->assertSame([
            'agent-hilos_logs.log' => LogKeySummary::CLASS_AGENT,
            self::DAEMON_ERROR_LOG => LogKeySummary::CLASS_DAEMON,
            self::DAEMON_LOG => LogKeySummary::CLASS_DAEMON,
            'worker-1.log' => LogKeySummary::CLASS_WORKER,
            'worker-monopolistic-2.log' => LogKeySummary::CLASS_WORKER,
        ], $classes);
    }

    public function testDaemonStreamsAreNotWorkers(): void
    {
        $this->writeLive(self::DAEMON_LOG, 10);
        $this->writeLive('worker-1.log', 40);

        $workers = $this->reader()->read()->workers();

        $this->assertCount(1, $workers);
        $this->assertSame('worker-1.log', $workers[0]->key);
    }

    public function testUnprefixedAndNonLogFilesStayOutOfTheIndex(): void
    {
        $this->writeLive(self::DAEMON_LOG, 10);
        // Neither is a log stream: notes.txt is not a *.log at all, and stray.log carries no known
        // name — the daemon class is matched by exact basename precisely so it cannot swallow it.
        $this->writeLive('notes.txt', 100);
        $this->writeLive('stray.log', 100);
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'protected-mode.state.json', '{}');

        $keys = $this->reader()->read()->keys();

        $this->assertCount(1, $keys);
        $this->assertSame(self::DAEMON_LOG, $keys[0]->key);
    }

    public function testKeyTotalSumsLiveFileAndEveryBatchOccurrence(): void
    {
        $this->writeLive(self::DAEMON_LOG, 10);
        $this->writeBatch('2026-08-01-00-00-00', [self::DAEMON_LOG => 100]);
        $this->writeBatch('2026-08-02-00-00-00', [self::DAEMON_LOG => 1000]);

        $keys = $this->reader()->read()->keys();

        $this->assertCount(1, $keys);
        $this->assertSame(1110, $keys[0]->totalBytes);
        $this->assertTrue($keys[0]->live);
        $this->assertCount(2, $keys[0]->batchTimestamps);
    }

    public function testBatchCountsTheDaemonFilesApartFromTheRest(): void
    {
        $this->writeBatch('2026-08-01-00-00-00', [
            self::DAEMON_LOG => 100,
            self::DAEMON_ERROR_LOG => 200,
            'agent-hilos_logs.log' => 30,
            'worker-1.log' => 40,
            'worker-monopolistic-2.log' => 50,
        ]);

        $batches = $this->reader()->read()->batches();

        $this->assertCount(1, $batches);
        $this->assertSame(2, $batches[0]->daemonFileCount);
        $this->assertSame(300, $batches[0]->daemonBytes);
        $this->assertSame(1, $batches[0]->agentFileCount);
        $this->assertSame(1, $batches[0]->workerFileCount);
        $this->assertSame(1, $batches[0]->workerMonopolisticFileCount);
    }

    public function testLiveWalkSeesTheLogRootOnly(): void
    {
        $this->writeLive(self::DAEMON_LOG, 10);
        $this->writeBatch('2026-08-01-00-00-00', ['agent-hilos_logs.log' => 30]);

        $live = $this->reader()->readLiveFiles();

        $this->assertNotNull($live);
        $this->assertSame([self::DAEMON_LOG => 10], $live[LogStoreReader::CLASS_DAEMON]);
        $this->assertSame([], $live[LogStoreReader::CLASS_AGENT]);
    }

    public function testLiveWalkResampledOverAnEarlierSnapshotKeepsItsBatches(): void
    {
        $this->writeLive(self::DAEMON_LOG, 10);
        $this->writeBatch('2026-08-01-00-00-00', [self::DAEMON_LOG => 100]);
        $reader = $this->reader();
        $snapshot = $reader->read();

        $this->writeLive(self::DAEMON_LOG, 25);
        $live = $reader->readLiveFiles();
        $this->assertNotNull($live);
        $keys = $snapshot->withLiveFiles($live)->keys();

        $this->assertCount(1, $keys);
        // Fresh live weight over the batch the full walk already found: 25 + 100.
        $this->assertSame(125, $keys[0]->totalBytes);
    }

    public function testUnresolvedLogRootIsUnavailableRatherThanEmpty(): void
    {
        $reader = new LogStoreReader(null);

        $this->assertFalse($reader->read()->available);
        $this->assertSame([], $reader->read()->keys());
        $this->assertNull($reader->readLiveFiles());
    }

    /**
     * Reader over the fixture directory, knowing the two daemon basenames.
     *
     * @return LogStoreReader Reader bound to the temp log root
     */
    private function reader(): LogStoreReader
    {
        return new LogStoreReader($this->dir, [self::DAEMON_LOG, self::DAEMON_ERROR_LOG]);
    }

    /**
     * Writes one file of the given size into the log root.
     *
     * @param string $name Basename to write
     * @param int $bytes Size in bytes
     */
    private function writeLive(string $name, int $bytes): void
    {
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . $name, str_repeat('x', $bytes));
    }

    /**
     * Writes one archive batch folder holding the given files.
     *
     * @param string $timestampDirName Folder name in {@see LogRotationConstants::TIMESTAMP_FORMAT}
     * @param array<string, int> $files Basename → size in bytes
     */
    private function writeBatch(string $timestampDirName, array $files): void
    {
        $path = $this->dir
            . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME
            . DIRECTORY_SEPARATOR . $timestampDirName;
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            $this->fail("Could not create fixture batch: {$path}");
        }
        foreach ($files as $name => $bytes) {
            file_put_contents($path . DIRECTORY_SEPARATOR . $name, str_repeat('x', $bytes));
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
