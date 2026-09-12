<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\ErrorConstants;
use Hilos\Log\LogErrorEntry;
use Hilos\Log\LogErrorTailReader;
use Hilos\Log\LogLineReader;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the tail read that feeds the recent-errors panel (HIL-867).
 *
 * Drives fixture files in the shape {@see Logger} writes into an error stream — `[stamp] text`
 * followed by the JSON context carrying the stack trace — and locks what the panel depends on:
 * the newest entries come back newest first, a line no one stamped is dropped instead of being
 * given a time, the trace turns into a frame count, the message is cut on the node, and a file
 * that cannot be read is silence rather than a refusal. Fixture shape borrowed from
 * {@see LogLineReaderTest}.
 */
final class LogErrorTailReaderTest extends TestCase
{
    /** Message cut applied by the reader under test unless a case needs a shorter one. */
    private const int MESSAGE_MAX_CHARS = 300;

    /** Stream the fixture lines are written to. */
    private const string STREAM = 'worker-monopolistic-5.error.log';

    /** Temp log root created per test; removed in {@see tearDown()}. */
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('hilos-error-tail-', true);
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testTailReturnsTheLastEntriesNewestFirst(): void
    {
        $this->write([
            $this->entryLine('2026-09-06 10:00:00.001', 'first'),
            $this->entryLine('2026-09-06 10:00:00.002', 'second'),
            $this->entryLine('2026-09-06 10:00:00.003', 'third'),
            $this->entryLine('2026-09-06 10:00:00.004', 'fourth'),
        ]);

        $entries = $this->reader()->readStream(self::STREAM, 3);

        $this->assertSame(
            ['fourth', 'third', 'second'],
            array_map(static fn (LogErrorEntry $entry): string => $entry->message, $entries),
        );
        $this->assertSame(self::STREAM, $entries[0]->stream);
        $this->assertSame($this->milliseconds('2026-09-06 10:00:00', 4), $entries[0]->atMs);
        $this->assertSame($this->milliseconds('2026-09-06 10:00:00', 2), $entries[2]->atMs);
    }

    public function testALineWithoutATimestampIsDropped(): void
    {
        $this->write([
            $this->entryLine('2026-09-06 10:00:00.001', 'stamped'),
            'PHP Fatal error: printed straight past the Logger',
        ]);

        $entries = $this->reader()->readStream(self::STREAM, 10);

        // Nothing orders such a line and nothing cuts it off by the panel's window, so the panel
        // would have to make a time up to show it at all.
        $this->assertCount(1, $entries);
        $this->assertSame('stamped', $entries[0]->message);
    }

    public function testTheContextTraceBecomesAFrameCountAndAnEntryWithoutOneCarriesNull(): void
    {
        $this->write([
            $this->entryLine('2026-09-06 10:00:00.001', 'plain failure'),
            $this->entryLine('2026-09-06 10:00:00.002', 'thrown failure', "#0 /app/a.php(7): a()\n#1 /app/b.php(3): b()\n#2 {main}"),
            $this->entryLine('2026-09-06 10:00:00.003', 'failure with context, no trace', null, ['attempt' => 3]),
        ]);

        $entries = $this->reader()->readStream(self::STREAM, 10);

        $this->assertSame('failure with context, no trace', $entries[0]->message);
        $this->assertNull($entries[0]->traceFrames);
        $this->assertSame('thrown failure', $entries[1]->message);
        $this->assertSame(3, $entries[1]->traceFrames);
        $this->assertSame('plain failure', $entries[2]->message);
        $this->assertNull($entries[2]->traceFrames);
    }

    public function testTheMessageIsCutAndTheCutDoesNotCostTheTrace(): void
    {
        $message = str_repeat('a', 40) . ' {not json after all}';
        $this->write([$this->entryLine('2026-09-06 10:00:00.001', $message, "#0 /app/a.php(7): a()\n#1 {main}")]);

        $entries = (new LogErrorTailReader(new LogLineReader($this->root), 20))->readStream(self::STREAM, 10);

        // The context is taken apart before the cut: the full text weighs kilobytes — the trace
        // rides inside the same line — and the badge has to survive dropping it.
        $this->assertSame(str_repeat('a', 20), $entries[0]->message);
        $this->assertSame(2, $entries[0]->traceFrames);
    }

    public function testAnUnreadableFileIsAnEmptyTailRatherThanARefusal(): void
    {
        $this->assertSame([], $this->reader()->readStream('never-written.error.log', 10));
    }

    /**
     * Reader over the fixture log root with the node's own message cut.
     *
     * @return LogErrorTailReader Reader bound to the temp log root
     */
    private function reader(): LogErrorTailReader
    {
        return new LogErrorTailReader(new LogLineReader($this->root), self::MESSAGE_MAX_CHARS);
    }

    /**
     * Writes the fixture lines into {@see STREAM} under the log root.
     *
     * @param list<string> $lines Lines in file order, without their trailing newline
     */
    private function write(array $lines): void
    {
        file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . self::STREAM,
            implode("\n", $lines) . "\n",
        );
    }

    /**
     * One line in the shape {@see Logger} appends to an error stream.
     *
     * @param string $stamp Local time of the entry, milliseconds included
     * @param string $message Message text
     * @param ?string $trace Stack trace as `Throwable::getTraceAsString()` renders it, or null for none
     * @param array<string, mixed> $context Further context keys carried beside the trace
     *
     * @return string Line text without its trailing newline
     */
    private function entryLine(string $stamp, string $message, ?string $trace = null, array $context = []): string
    {
        if ($trace !== null) {
            $context[ErrorConstants::CONTEXT_KEY_TRACE] = $trace;
        }
        if ($context === []) {
            return "[{$stamp}] {$message}";
        }

        return "[{$stamp}] {$message} " . json_encode($context);
    }

    /**
     * The unix milliseconds an entry stamped with this local time must be read as.
     *
     * @param string $stamp Local time without milliseconds
     * @param int $milliseconds Millisecond part of the stamp
     *
     * @return int Unix milliseconds
     */
    private function milliseconds(string $stamp, int $milliseconds): int
    {
        return strtotime($stamp) * 1000 + $milliseconds;
    }
}
