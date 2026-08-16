<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Utils;

use Hilos\Core\Exception\InvalidJsonException;
use Hilos\Core\Exception\NonArrayPayloadException;
use Hilos\Socket\SocketException;
use Hilos\Utils\ClientReadFailureLog;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the shared writer of the two client readers puts in the journal (HIL-601).
 *
 * It answers three questions the readers used to answer apart, or not at all: what the
 * line says, which level it goes in at, and what happens when the same refusal arrives
 * without stopping. The wording is the one both readers already wrote and is pinned
 * character for character here — an operator greps the journal for it, and a rewrite
 * that only looks equivalent breaks that quietly.
 */
final class ClientReadFailureLogTest extends TestCase
{
    /** Server name the assertions look for in the written line */
    private const string SERVER = 'websocket-test';

    /** Temporary main log file the assertions read the written lines back from */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-client-read-failure-log');
        Logger::setLogFile($this->logFile);
        ClientReadFailureLog::reset();
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        ClientReadFailureLog::reset();

        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        parent::tearDown();
    }

    public function testTheLineNamesTheReaderTheServerTheClassAndWhereItCameFrom(): void
    {
        $failure = new InvalidJsonException('Payload does not decode as JSON: Syntax error');

        ClientReadFailureLog::write(self::SERVER, ClientReadFailureLog::READER_TICK, $failure, microtime(true));

        $this->assertStringContainsString(
            sprintf(
                'Error in client tick for %s: %s in %s:%d - %s',
                self::SERVER,
                InvalidJsonException::class,
                basename($failure->getFile()),
                $failure->getLine(),
                'Payload does not decode as JSON: Syntax error',
            ),
            $this->logged(),
        );
    }

    public function testTheEventLoopReaderNamesItselfInTheSameLine(): void
    {
        ClientReadFailureLog::write(
            self::SERVER,
            ClientReadFailureLog::READER_EVENT_LOOP,
            new InvalidJsonException('Payload does not decode as JSON: Syntax error'),
            microtime(true),
        );

        $this->assertStringContainsString('Error in client read handler for ' . self::SERVER, $this->logged());
    }

    public function testInputThatCouldNotBeParsedIsAWarningAndABrokenNodeIsAnError(): void
    {
        ClientReadFailureLog::write(
            self::SERVER,
            ClientReadFailureLog::READER_TICK,
            new InvalidJsonException('Payload does not decode as JSON: Syntax error'),
            microtime(true),
        );
        ClientReadFailureLog::write(
            self::SERVER,
            ClientReadFailureLog::READER_TICK,
            new SocketException('Connection reset by peer'),
            microtime(true),
        );
        ClientReadFailureLog::write(
            self::SERVER,
            ClientReadFailureLog::READER_TICK,
            new RuntimeException('worker table is out of shape'),
            microtime(true),
        );

        $logged = $this->logged();
        $this->assertSame(2, substr_count($logged, 'WARNING:'));
        $this->assertSame(1, substr_count($logged, 'ERROR:'));
        $this->assertStringContainsString('ERROR: Error in client tick for ' . self::SERVER, $logged);
    }

    public function testAKeyIsWrittenInFullForTheFirstFewFailuresAndCountedAfterThat(): void
    {
        $this->flood(ClientReadFailureLog::BURST_LINES + 4);

        $this->assertSame(ClientReadFailureLog::BURST_LINES, substr_count($this->logged(), 'Syntax error'));
    }

    public function testTheClosingWindowSaysHowManyLinesItHeldBack(): void
    {
        $this->flood(ClientReadFailureLog::BURST_LINES + 4);

        ClientReadFailureLog::flushClosedWindows(microtime(true) + ClientReadFailureLog::WINDOW_SECONDS);

        $this->assertStringContainsString(
            sprintf(
                'Suppressed %d more %s failures for %s in the last %d seconds',
                4,
                InvalidJsonException::class,
                self::SERVER,
                (int)ClientReadFailureLog::WINDOW_SECONDS,
            ),
            $this->logged(),
        );
    }

    public function testAWindowThatHeldNothingBackClosesWithoutASummary(): void
    {
        $this->flood(ClientReadFailureLog::BURST_LINES);

        ClientReadFailureLog::flushClosedWindows(microtime(true) + ClientReadFailureLog::WINDOW_SECONDS);

        $this->assertStringNotContainsString('Suppressed', $this->logged());
    }

    public function testAWindowStillRunningIsNotClosed(): void
    {
        $this->flood(ClientReadFailureLog::BURST_LINES + 1);

        ClientReadFailureLog::flushClosedWindows(microtime(true));

        $this->assertStringNotContainsString('Suppressed', $this->logged());
    }

    public function testTheNextWindowWritesInFullAgain(): void
    {
        $started = microtime(true);
        $this->flood(ClientReadFailureLog::BURST_LINES + 1, $started);

        ClientReadFailureLog::write(
            self::SERVER,
            ClientReadFailureLog::READER_TICK,
            new InvalidJsonException('Payload does not decode as JSON: Syntax error'),
            $started + ClientReadFailureLog::WINDOW_SECONDS,
        );

        $logged = $this->logged();
        $this->assertSame(ClientReadFailureLog::BURST_LINES + 1, substr_count($logged, 'Syntax error'));
        $this->assertStringContainsString('Suppressed 1 more ' . InvalidJsonException::class, $logged);
    }

    /**
     * A limit shared by everything a node refuses would let one loud peer silence the
     * rest, so a window belongs to one exception class on one server.
     */
    public function testEachClassAndServerIsCountedOnItsOwn(): void
    {
        $now = microtime(true);
        $this->flood(ClientReadFailureLog::BURST_LINES + 1, $now);

        ClientReadFailureLog::write(
            self::SERVER,
            ClientReadFailureLog::READER_TICK,
            new NonArrayPayloadException('Payload decodes into int, not an array'),
            $now,
        );
        ClientReadFailureLog::write(
            'peer-test',
            ClientReadFailureLog::READER_TICK,
            new InvalidJsonException('Payload does not decode as JSON: Syntax error'),
            $now,
        );

        $logged = $this->logged();
        $this->assertStringContainsString('not an array', $logged);
        $this->assertStringContainsString('Error in client tick for peer-test', $logged);
    }

    /**
     * The node breaking is what an operator came to the journal for, so the limiter
     * never reaches the error level, however often the same failure repeats.
     */
    public function testFailuresOfTheNodeItselfAreNeverHeldBack(): void
    {
        $now = microtime(true);
        for ($written = 0; $written < ClientReadFailureLog::BURST_LINES + 4; $written++) {
            ClientReadFailureLog::write(
                self::SERVER,
                ClientReadFailureLog::READER_EVENT_LOOP,
                new RuntimeException('worker table is out of shape'),
                $now,
            );
        }

        $this->assertSame(
            ClientReadFailureLog::BURST_LINES + 4,
            substr_count($this->logged(), 'worker table is out of shape'),
        );
    }

    /**
     * Writes the same refusal the given number of times.
     *
     * @param int $times Number of failures to hand the writer
     * @param ?float $now Time all of them arrive at, or null for the current one
     */
    private function flood(int $times, ?float $now = null): void
    {
        $now ??= microtime(true);
        for ($written = 0; $written < $times; $written++) {
            ClientReadFailureLog::write(
                self::SERVER,
                ClientReadFailureLog::READER_TICK,
                new InvalidJsonException('Payload does not decode as JSON: Syntax error'),
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
