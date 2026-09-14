<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\LogStreamConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Log\DaemonRawStream;
use Hilos\Log\LogStoreReader;
use PHPUnit\Framework\TestCase;

/**
 * Guard against writer/reader stream naming drift (HIL-982).
 *
 * Writer names are composed from shared constants without Reflection. This test feeds those same
 * combinations to the reader and checks if it classifies them into the expected buckets.
 */
final class LogStreamNamingContractTest extends TestCase
{
    private const string DAEMON_LOG = 'daemon.log';
    private const string DAEMON_ERROR_LOG = 'daemon-error.log';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'hilos-log-contract-test-' . mt_rand();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->dir);
    }

    public function testNamesComposedByWriterRulesAreClassifiedCorrectlyByReader(): void
    {
        // 1. Worker (regular) with STREAM_SUFFIX
        $name1 = LogStreamConstants::WORKER_STREAM_PREFIX . WorkerConstants::TYPE_REGULAR
            . LogStreamConstants::WORKER_TYPE_SEPARATOR . '1' . LogStreamConstants::STREAM_SUFFIX;
        $this->writeLive($name1);

        // 2. Worker (regular) with ERROR_STREAM_SUFFIX
        $name2 = LogStreamConstants::WORKER_STREAM_PREFIX . WorkerConstants::TYPE_REGULAR
            . LogStreamConstants::WORKER_TYPE_SEPARATOR . '1' . LogStreamConstants::ERROR_STREAM_SUFFIX;
        $this->writeLive($name2);

        // 3. Worker monopolistic with STREAM_SUFFIX
        $name3 = LogStreamConstants::WORKER_STREAM_PREFIX . WorkerConstants::TYPE_MONOPOLISTIC
            . LogStreamConstants::WORKER_TYPE_SEPARATOR . '2' . LogStreamConstants::STREAM_SUFFIX;
        $this->writeLive($name3);

        // 4. Worker monopolistic with ERROR_STREAM_SUFFIX
        $name4 = LogStreamConstants::WORKER_STREAM_PREFIX . WorkerConstants::TYPE_MONOPOLISTIC
            . LogStreamConstants::WORKER_TYPE_SEPARATOR . '2' . LogStreamConstants::ERROR_STREAM_SUFFIX;
        $this->writeLive($name4);

        // 5. Agent with STREAM_SUFFIX
        $name5 = LogStreamConstants::AGENT_STREAM_PREFIX . 'hilos_logs_0' . LogStreamConstants::STREAM_SUFFIX;
        $this->writeLive($name5);

        // 6. Agent with ERROR_STREAM_SUFFIX
        $name6 = LogStreamConstants::AGENT_STREAM_PREFIX . 'hilos_logs_0' . LogStreamConstants::ERROR_STREAM_SUFFIX;
        $this->writeLive($name6);

        // 7, 8. Daemon logs
        $this->writeLive(self::DAEMON_LOG);
        $this->writeLive(self::DAEMON_ERROR_LOG);

        $liveFiles = $this->reader()->read()->liveFiles();

        $this->assertArrayHasKey($name1, $liveFiles[LogStoreReader::CLASS_WORKER]);
        $this->assertArrayHasKey($name2, $liveFiles[LogStoreReader::CLASS_WORKER]);
        $this->assertArrayHasKey($name3, $liveFiles[LogStoreReader::CLASS_WORKER_MONOPOLISTIC]);
        $this->assertArrayHasKey($name4, $liveFiles[LogStoreReader::CLASS_WORKER_MONOPOLISTIC]);
        $this->assertArrayHasKey($name5, $liveFiles[LogStoreReader::CLASS_AGENT]);
        $this->assertArrayHasKey($name6, $liveFiles[LogStoreReader::CLASS_AGENT]);
        $this->assertArrayHasKey(self::DAEMON_LOG, $liveFiles[LogStoreReader::CLASS_DAEMON]);
        $this->assertArrayHasKey(self::DAEMON_ERROR_LOG, $liveFiles[LogStoreReader::CLASS_DAEMON]);
    }

    private function writeLive(string $name): void
    {
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . $name, 'x');
    }

    private function reader(): LogStoreReader
    {
        return new LogStoreReader(
            $this->dir,
            [self::DAEMON_LOG, self::DAEMON_ERROR_LOG],
            self::DAEMON_ERROR_LOG,
            [DaemonRawStream::pathFor(self::DAEMON_LOG), DaemonRawStream::pathFor(self::DAEMON_ERROR_LOG)],
        );
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
