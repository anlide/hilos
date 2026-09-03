<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Log\LogLine;
use Hilos\Log\LogLinePage;
use Hilos\Log\LogLineReader;
use Hilos\Log\LogReadQuery;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the append-aware half of the single-file log reader (HIL-389).
 *
 * Where {@see LogLineReaderTest} locks paging over a file that sits still, this drives a file the way a
 * live tail meets one: read, let the writer append, read again from where the last read stopped. It locks
 * the properties a follower stands on — {@see LogLinePage::$endCursor} advances only over complete lines
 * so a half-written trailing line is never split in two, {@see LogLinePage::$endLevel} carries the
 * running entry level across the cut so a stack trace keeps its ERROR, {@see LogLineReader::size()}
 * answers through the same traversal guard as a read, and {@see LogLineReader::size()} reports growth
 * appended by another process — the master writes an agent's log — on the very next call rather than
 * from this process's stat cache (HIL-874).
 */
final class LogLineReaderAppendedTest extends TestCase
{
    /** Temp log root created per test; removed in {@see tearDown()}. */
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('hilos-log-tail-', true);
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testReadFromEndCursorReturnsExactlyTheAppendedLines(): void
    {
        $this->write('worker-1.log', "[2026-07-28 12:00:00.001] first\n[2026-07-28 12:00:00.002] second\n");
        $reader = new LogLineReader($this->root);

        $first = $reader->read('worker-1.log', new LogReadQuery(LogReadQuery::ANCHOR_HEAD));
        $this->append('worker-1.log', "[2026-07-28 12:00:00.003] third\n[2026-07-28 12:00:00.004] fourth\n");
        $second = $reader->read('worker-1.log', new LogReadQuery(LogReadQuery::ANCHOR_HEAD, cursor: $first->endCursor));

        $this->assertSame(
            ['[2026-07-28 12:00:00.003] third', '[2026-07-28 12:00:00.004] fourth'],
            self::texts($second),
        );
        $this->assertSame(filesize($this->root . DIRECTORY_SEPARATOR . 'worker-1.log'), $second->endCursor);
    }

    public function testHalfWrittenTrailingLineIsHeldBackAndDeliveredOnceComplete(): void
    {
        $this->write('worker-1.log', "[2026-07-28 12:00:00.001] first\n[2026-07-28 12:00:00.002] hal");
        $reader = new LogLineReader($this->root);

        $first = $reader->read('worker-1.log', new LogReadQuery(LogReadQuery::ANCHOR_HEAD));

        $this->assertSame(['[2026-07-28 12:00:00.001] first'], self::texts($first));
        $this->assertSame(strlen("[2026-07-28 12:00:00.001] first\n"), $first->endCursor);

        $this->append('worker-1.log', "f written\n");
        $second = $reader->read('worker-1.log', new LogReadQuery(LogReadQuery::ANCHOR_HEAD, cursor: $first->endCursor));

        $this->assertSame(['[2026-07-28 12:00:00.002] half written'], self::texts($second));
    }

    public function testStackTraceCutByAPageBoundaryKeepsItsErrorLevel(): void
    {
        $this->write(
            'worker-1.log',
            "[2026-07-28 12:00:00.001] ERROR: connection failed\n#0 /app/foo.php(10): bar()\n#1 {main}\n",
        );
        $reader = new LogLineReader($this->root);

        $first = $reader->read('worker-1.log', new LogReadQuery(
            LogReadQuery::ANCHOR_HEAD,
            limit: 1,
            levelFilter: Logger::LEVEL_ERROR,
        ));
        $second = $reader->read('worker-1.log', new LogReadQuery(
            LogReadQuery::ANCHOR_HEAD,
            cursor: $first->endCursor,
            levelFilter: Logger::LEVEL_ERROR,
            inheritedLevel: $first->endLevel,
        ));

        $this->assertSame(Logger::LEVEL_ERROR, $first->endLevel);
        $this->assertSame(['#0 /app/foo.php(10): bar()', '#1 {main}'], self::texts($second));
        $this->assertSame([true, true], array_map(static fn (LogLine $line): bool => $line->isContinuation, $second->lines));
    }

    public function testContinuationOpeningAPageFallsBackToInfoWithoutAnInheritedLevel(): void
    {
        $this->write(
            'worker-1.log',
            "[2026-07-28 12:00:00.001] ERROR: connection failed\n#0 /app/foo.php(10): bar()\n",
        );
        $reader = new LogLineReader($this->root);

        $first = $reader->read('worker-1.log', new LogReadQuery(LogReadQuery::ANCHOR_HEAD, limit: 1));
        $second = $reader->read('worker-1.log', new LogReadQuery(LogReadQuery::ANCHOR_HEAD, cursor: $first->endCursor));

        $this->assertSame(Logger::LEVEL_INFO, $second->lines[0]->detectedLevel);
    }

    public function testEndPositionIsFilledBothWhenTheLimitFillsUpAndWhenTheFileRunsOut(): void
    {
        $this->write(
            'worker-1.log',
            "[2026-07-28 12:00:00.001] first\n[2026-07-28 12:00:00.002] WARNING: second\n",
        );
        $reader = new LogLineReader($this->root);

        $limited = $reader->read('worker-1.log', new LogReadQuery(LogReadQuery::ANCHOR_HEAD, limit: 1));
        $whole = $reader->read('worker-1.log', new LogReadQuery(LogReadQuery::ANCHOR_HEAD));

        $this->assertSame(strlen("[2026-07-28 12:00:00.001] first\n"), $limited->endCursor);
        $this->assertSame(Logger::LEVEL_INFO, $limited->endLevel);
        $this->assertSame(filesize($this->root . DIRECTORY_SEPARATOR . 'worker-1.log'), $whole->endCursor);
        $this->assertSame(Logger::LEVEL_WARNING, $whole->endLevel);
    }

    public function testEndPositionStandsStillWhenNothingWasAppended(): void
    {
        $this->write('worker-1.log', "[2026-07-28 12:00:00.001] first\n");
        $reader = new LogLineReader($this->root);
        $size = filesize($this->root . DIRECTORY_SEPARATOR . 'worker-1.log');

        $page = $reader->read('worker-1.log', new LogReadQuery(
            LogReadQuery::ANCHOR_HEAD,
            cursor: $size,
            inheritedLevel: Logger::LEVEL_ERROR,
        ));

        $this->assertSame([], $page->lines);
        $this->assertSame($size, $page->endCursor);
        $this->assertSame(Logger::LEVEL_ERROR, $page->endLevel);
    }

    public function testLinesFilteredOutStillAdvanceTheEndCursor(): void
    {
        $this->write(
            'worker-1.log',
            "[2026-07-28 12:00:00.001] first\n[2026-07-28 12:00:00.002] second\n",
        );
        $reader = new LogLineReader($this->root);

        $page = $reader->read('worker-1.log', new LogReadQuery(
            LogReadQuery::ANCHOR_HEAD,
            levelFilter: Logger::LEVEL_ERROR,
        ));

        $this->assertSame([], $page->lines);
        $this->assertSame(filesize($this->root . DIRECTORY_SEPARATOR . 'worker-1.log'), $page->endCursor);
    }

    public function testSizeAnswersThroughTheTraversalGuard(): void
    {
        $this->write('worker-1.log', "[2026-07-28 12:00:00.001] first\n");
        file_put_contents(dirname($this->root) . DIRECTORY_SEPARATOR . 'outside-secret.log', "secret\n");
        $reader = new LogLineReader($this->root);

        $inside = $reader->size('worker-1.log');
        $outside = $reader->size('../outside-secret.log');
        $missing = $reader->size('does-not-exist.log');

        unlink(dirname($this->root) . DIRECTORY_SEPARATOR . 'outside-secret.log');
        $this->assertSame(strlen("[2026-07-28 12:00:00.001] first\n"), $inside);
        $this->assertNull($outside);
        $this->assertNull($missing);
        $this->assertNull((new LogLineReader(null))->size('worker-1.log'));
    }

    /**
     * Nothing may sit between the foreign append and the second {@see LogLineReader::size()} — a PHPUnit
     * assertion in that gap drops the stat cache by itself and the case goes green on the broken code.
     */
    public function testSizeSeesGrowthAppendedByAnotherProcess(): void
    {
        $this->write('worker-1.log', "[2026-07-28 12:00:00.001] first\n");
        $reader = new LogLineReader($this->root);
        $path = $this->root . DIRECTORY_SEPARATOR . 'worker-1.log';

        $warmed = $reader->size('worker-1.log');
        $this->appendFromAnotherProcess('worker-1.log', "[2026-07-28 12:00:00.002] second\n");
        $grown = $reader->size('worker-1.log');

        clearstatcache(true, $path);
        $this->assertSame(strlen("[2026-07-28 12:00:00.001] first\n"), $warmed);
        $this->assertGreaterThan($warmed, filesize($path), 'The other process must really have appended');
        $this->assertSame(filesize($path), $grown);
    }

    /**
     * Create a log file with the given body under the temp root.
     *
     * @param string $name File basename
     * @param string $body Raw file content
     */
    private function write(string $name, string $body): void
    {
        file_put_contents($this->root . DIRECTORY_SEPARATOR . $name, $body);
    }

    /**
     * Append raw bytes to an existing log file under the temp root, as a writing daemon would.
     *
     * @param string $name File basename
     * @param string $body Raw bytes to append
     */
    private function append(string $name, string $body): void
    {
        file_put_contents($this->root . DIRECTORY_SEPARATOR . $name, $body, FILE_APPEND);
    }

    /**
     * Append raw bytes to a log file from a separate process, the way the master appends an agent's log.
     *
     * The defect the case above locks is a cross-process one: PHP refreshes its per-path stat cache only
     * for changes made from the running process, so the in-process {@see append()} could never reproduce
     * it. Three details of this helper are load-bearing rather than taste, all three measured on PHP
     * 8.4.24 (HIL-874): the child is spawned through `proc_open()` and not `exec()`/`popen()`, which talk
     * to the child over a plain-file stream and drop the stat cache on the way; it is given no pipes, for
     * the same reason; and it is not asserted about here, because a PHPUnit assertion drops the cache too
     * and the caller has not asked its question yet. A spawn that fails is caught in place; a spawn that
     * writes nothing is caught by the caller, which asserts the file really grew before it compares the
     * reader's answer with it — without that the case would go green on the broken reader, both sides
     * holding the old size. `printf` takes the format as its own argument and the body as the next one,
     * or a percent sign inside a logged line would be eaten by it.
     *
     * @param string $name File basename
     * @param string $body Raw bytes to append
     */
    private function appendFromAnotherProcess(string $name, string $body): void
    {
        $pipes = [];
        $process = proc_open(
            ['sh', '-c', 'printf %s "$1" >> "$2"', 'sh', $body, $this->root . DIRECTORY_SEPARATOR . $name],
            [],
            $pipes,
        );
        if ($process === false) {
            self::fail('Spawning the process that appends to the log must succeed');
        }

        proc_close($process);
    }

    /**
     * Line texts of a page, in file order.
     *
     * @param LogLinePage $page Page to read
     *
     * @return list<string> Text of every line the page carries
     */
    private static function texts(LogLinePage $page): array
    {
        return array_map(static fn (LogLine $line): string => $line->text, $page->lines);
    }
}
