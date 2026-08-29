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
 * the three properties a follower stands on — {@see LogLinePage::$endCursor} advances only over complete
 * lines so a half-written trailing line is never split in two, {@see LogLinePage::$endLevel} carries the
 * running entry level across the cut so a stack trace keeps its ERROR, and {@see LogLineReader::size()}
 * answers through the same traversal guard as a read.
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
