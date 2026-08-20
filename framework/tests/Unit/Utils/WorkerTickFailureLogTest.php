<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Utils;

use Hilos\Core\Daemon\Worker\ContainedFailure;
use Hilos\Core\Daemon\Worker\WorkerTickUnit;
use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Utils\Logger;
use Hilos\Utils\WorkerTickFailureLog;
use PHPUnit\Framework\TestCase;

/**
 * What the worker's tick puts in the journal when it contains a failure (HIL-574).
 *
 * The line is the only trace a swallowed failure leaves, so its wording is pinned here
 * character for character - an operator greps for it. The two answers this writer gives
 * differently from the master's are pinned too: the level never softens, and a flood of
 * one kind is held back however many addresses it arrives from.
 */
final class WorkerTickFailureLogTest extends TestCase
{
    /** Worker index the assertions look for in the written line */
    private const int WORKER_INDEX = 3;

    /** Temporary main log file the assertions read the written lines back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-worker-tick-failure-log');
        Logger::setLogFile($this->logFile);
        WorkerTickFailureLog::reset();
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        WorkerTickFailureLog::reset();

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testTheLineNamesTheWorkerTheUnitTheAddressAndWhereItCameFrom(): void
    {
        $failure = new InvalidJsonException('Payload does not decode as JSON: Syntax error');

        WorkerTickFailureLog::write(
            self::WORKER_INDEX,
            new ContainedFailure(WorkerTickUnit::DAEMON_MESSAGE, 'agent_start', $failure),
            1000.0,
        );

        $this->assertStringContainsString(
            sprintf(
                'Worker #%d contained a failure in daemon message (agent_start): %s in %s:%d - %s',
                self::WORKER_INDEX,
                InvalidJsonException::class,
                basename($failure->getFile()),
                $failure->getLine(),
                'Payload does not decode as JSON: Syntax error',
            ),
            $this->logged(),
        );
    }

    /**
     * The master softens input it could not parse to a warning because its port is open
     * to the internet. A worker hears only its own daemon and runs only the project's own
     * code, so the marker changes nothing here.
     */
    public function testInputThatCouldNotBeParsedIsStillAnError(): void
    {
        WorkerTickFailureLog::write(
            self::WORKER_INDEX,
            new ContainedFailure(
                WorkerTickUnit::DAEMON_MESSAGE,
                'agent_start',
                new InvalidJsonException('Payload does not decode as JSON: Syntax error'),
            ),
            1000.0,
        );

        $logged = $this->logged();
        $this->assertStringContainsString('ERROR: Worker #' . self::WORKER_INDEX, $logged);
        $this->assertStringNotContainsString('WARNING:', $logged);
    }

    public function testAStreamOfOneKindIsWrittenInFullAndThenHeldBack(): void
    {
        $this->flood(WorkerTickFailureLog::BURST_LINES + 4, 1000.0);

        $this->assertSame(
            WorkerTickFailureLog::BURST_LINES,
            substr_count($this->logged(), 'Syntax error'),
        );
    }

    /**
     * One broken page declaration is read by every subscription that carries it, so a
     * limit told apart by address would let one mistake through as many times as it has
     * subscribers - exactly when holding it back matters most.
     */
    public function testAddressesOfTheSameKindShareOneWindow(): void
    {
        $now = 1000.0;
        for ($written = 0; $written < WorkerTickFailureLog::BURST_LINES + 4; $written++) {
            WorkerTickFailureLog::write(
                self::WORKER_INDEX,
                new ContainedFailure(
                    WorkerTickUnit::BROWSER_SUBSCRIPTION,
                    'page=chat acceptKey=ak-' . $written,
                    new InvalidJsonException('Payload does not decode as JSON: Syntax error'),
                ),
                $now,
            );
        }

        $this->assertSame(
            WorkerTickFailureLog::BURST_LINES,
            substr_count($this->logged(), 'Syntax error'),
        );
    }

    public function testTheClosingWindowSaysHowManyLinesItHeldBack(): void
    {
        $opened = 1000.0;
        $this->flood(WorkerTickFailureLog::BURST_LINES + 4, $opened);

        WorkerTickFailureLog::flushClosedWindows(
            self::WORKER_INDEX,
            $opened + WorkerTickFailureLog::WINDOW_SECONDS,
        );

        $this->assertStringContainsString(
            sprintf(
                'Suppressed %d more %s failures in %s on worker #%d in the last %d seconds',
                4,
                InvalidJsonException::class,
                WorkerTickUnit::DAEMON_MESSAGE->value,
                self::WORKER_INDEX,
                (int)WorkerTickFailureLog::WINDOW_SECONDS,
            ),
            $this->logged(),
        );
    }

    public function testAWindowStillRunningIsNotClosed(): void
    {
        $opened = 1000.0;
        $this->flood(WorkerTickFailureLog::BURST_LINES + 1, $opened);

        WorkerTickFailureLog::flushClosedWindows(self::WORKER_INDEX, $opened);

        $this->assertStringNotContainsString('Suppressed', $this->logged());
    }

    public function testEachUnitIsCountedOnItsOwn(): void
    {
        $now = 1000.0;
        $this->flood(WorkerTickFailureLog::BURST_LINES + 1, $now);

        WorkerTickFailureLog::write(
            self::WORKER_INDEX,
            new ContainedFailure(
                WorkerTickUnit::AGENT,
                'chat',
                new InvalidJsonException('Payload does not decode as JSON: Syntax error'),
            ),
            $now,
        );

        $this->assertStringContainsString('contained a failure in agent (chat)', $this->logged());
    }

    /**
     * Writes the same contained failure the given number of times.
     *
     * @param int $times Number of failures to hand the writer
     * @param float $now Time all of them arrive at
     */
    private function flood(int $times, float $now): void
    {
        for ($written = 0; $written < $times; $written++) {
            WorkerTickFailureLog::write(
                self::WORKER_INDEX,
                new ContainedFailure(
                    WorkerTickUnit::DAEMON_MESSAGE,
                    'agent_start',
                    new InvalidJsonException('Payload does not decode as JSON: Syntax error'),
                ),
                $now,
            );
        }
    }

    /**
     * @return string Everything the writer put in the journal
     */
    private function logged(): string
    {
        return (string)file_get_contents($this->logFile);
    }
}
