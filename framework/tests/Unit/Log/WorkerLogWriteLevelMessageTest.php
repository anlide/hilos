<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Log\LogWriteLevelApplier;
use Hilos\Socket\Worker\DTO\WorkerLogWriteLevelDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Utils\LogLevel;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the write level travelling from a worker to the master (HIL-761).
 *
 * The master is forbidden the database, so it cannot read the setting that decides how loudly
 * this node logs; a worker that can read it says so over the link the two already share. What is
 * pinned here is the frame itself, and the master's side of it: it obeys a level it is told, it
 * stays quiet when told the same level again - a node has several workers and they all report -
 * and it changes nothing at all on a name that is not a level.
 */
final class WorkerLogWriteLevelMessageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        LogWriteLevelApplier::reset();
        Logger::setWriteLevel(LogLevel::Info);
    }

    protected function tearDown(): void
    {
        LogWriteLevelApplier::reset();
        Logger::setWriteLevel(LogLevel::Info);

        parent::tearDown();
    }

    public function testTheFrameIsBuiltAndReadBackWithItsLevel(): void
    {
        $json = new WorkerLogWriteLevelDTO(LogLevel::Warning->value)->toJson();

        $restored = WorkerDTO::factoryWorkerDTO($json);

        $this->assertInstanceOf(WorkerLogWriteLevelDTO::class, $restored);
        $this->assertSame(LogLevel::Warning->value, $restored->level);
        $this->assertSame(WorkerConstants::MESSAGE_WORKER_LOG_WRITE_LEVEL, $restored->getType());
    }

    /**
     * A report with no level in it is not a report of anything, and INFO is a real answer rather
     * than a stand-in for a missing one.
     */
    public function testAFrameWithoutALevelIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);

        WorkerLogWriteLevelDTO::fromArray([WorkerLogWriteLevelDTO::TYPE => WorkerLogWriteLevelDTO::MESSAGE_TYPE]);
    }

    public function testTheMasterObeysTheLevelItIsTold(): void
    {
        $lines = $this->capture(static function (): void {
            LogWriteLevelApplier::applyReported(LogLevel::Warning, 0);
        });

        $this->assertSame(LogLevel::Warning, Logger::writeLevel());
        $this->assertStringContainsString('Log write level set to WARNING (reported by worker #0)', $lines);
    }

    /**
     * Every worker of a node reports, and they all read one setting: without this the node would
     * write the same line once per worker on every start.
     */
    public function testTheSameLevelReportedAgainWritesNothing(): void
    {
        LogWriteLevelApplier::applyReported(LogLevel::Warning, 0);

        $lines = $this->capture(static function (): void {
            LogWriteLevelApplier::applyReported(LogLevel::Warning, 1);
            LogWriteLevelApplier::applyReported(LogLevel::Warning, 2);
        });

        $this->assertSame(LogLevel::Warning, Logger::writeLevel());
        $this->assertSame('', $lines);
    }

    /**
     * A name that is not a level can only mean the frame was built wrong, and silencing
     * daemon.log on the strength of a malformed frame is worse than ignoring it.
     */
    public function testAnUnknownNameIsNotALevelAndChangesNothing(): void
    {
        $this->assertNull(LogLevel::fromName('TRACE'));
        $this->assertSame(LogLevel::Info, Logger::writeLevel());
    }

    /**
     * Runs something that may write to the journal and hands back what it wrote.
     *
     * @param callable(): void $write Work whose journal output is wanted
     * @return string Everything written to stdout while it ran
     */
    private function capture(callable $write): string
    {
        ob_start();
        $write();
        $written = ob_get_clean();
        $this->assertNotFalse($written);

        return $written;
    }
}
