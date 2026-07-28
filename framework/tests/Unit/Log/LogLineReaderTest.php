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
 * Unit tests for the single-file log line read primitive (HIL-384).
 *
 * Drives a fixture log file mirroring real {@see Logger} output — timestamped INFO/ERROR/WARNING/DEBUG
 * entries, a wrapped stack trace, and an agent-pipe line — and locks: forward and backward pagination
 * cursors, the per-line level heuristic, continuation inheritance (so an ERROR filter also carries the
 * stack trace), the level and substring filters, and the traversal/availability guard.
 */
final class LogLineReaderTest extends TestCase
{
    /** Temp log root created per test; removed in {@see tearDown()}. */
    private string $root;

    /**
     * Fixture lines in file order; the text and the level each line must be classified as.
     *
     * @var list<array{text: string, level: string, continuation: bool}>
     */
    private const array FIXTURE = [
        ['text' => '[2026-07-28 12:00:00.001] server started', 'level' => Logger::LEVEL_INFO, 'continuation' => false],
        ['text' => '[2026-07-28 12:00:00.002] ERROR: connection failed', 'level' => Logger::LEVEL_ERROR, 'continuation' => false],
        ['text' => '#0 /app/foo.php(10): bar()', 'level' => Logger::LEVEL_ERROR, 'continuation' => true],
        ['text' => '#1 {main}', 'level' => Logger::LEVEL_ERROR, 'continuation' => true],
        ['text' => '[2026-07-28 12:00:00.003] WARNING: retrying', 'level' => Logger::LEVEL_WARNING, 'continuation' => false],
        ['text' => '[2026-07-28 12:00:00.004] DEBUG: tick', 'level' => Logger::LEVEL_DEBUG, 'continuation' => false],
        ['text' => '[AGENT_LOG]agent-7|ERROR|[2026-07-28 12:00:00.005] agent boom', 'level' => Logger::LEVEL_ERROR, 'continuation' => false],
        ['text' => '[2026-07-28 12:00:00.006] all good', 'level' => Logger::LEVEL_INFO, 'continuation' => false],
    ];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('hilos-log-', true);
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function testHeadReadsFirstLinesAndReportsMore(): void
    {
        $this->writeFixture('worker-1.log');
        $reader = new LogLineReader($this->root);

        $page = $reader->read('worker-1.log', new LogReadQuery(LogReadQuery::ANCHOR_HEAD, limit: 3));

        $this->assertTrue($page->readable);
        $this->assertSame(
            [self::FIXTURE[0]['text'], self::FIXTURE[1]['text'], self::FIXTURE[2]['text']],
            array_map(static fn (LogLine $line): string => $line->text, $page->lines),
        );
        $this->assertTrue($page->hasMore);
        $this->assertNotNull($page->nextCursor);
    }

    public function testHeadCursorWalksTheWholeFile(): void
    {
        $this->writeFixture('worker-1.log');
        $reader = new LogLineReader($this->root);

        $collected = [];
        $cursor = null;
        do {
            $page = $reader->read('worker-1.log', new LogReadQuery(LogReadQuery::ANCHOR_HEAD, cursor: $cursor, limit: 3));
            foreach ($page->lines as $line) {
                $collected[] = $line->text;
            }
            $cursor = $page->nextCursor;
        } while ($page->hasMore);

        $this->assertSame(array_column(self::FIXTURE, 'text'), $collected);
    }

    public function testTailReturnsLastLinesInFileOrder(): void
    {
        $this->writeFixture('worker-1.log');
        $reader = new LogLineReader($this->root);

        $page = $reader->read('worker-1.log', new LogReadQuery(LogReadQuery::ANCHOR_TAIL, limit: 3));

        $this->assertSame(
            [self::FIXTURE[5]['text'], self::FIXTURE[6]['text'], self::FIXTURE[7]['text']],
            array_map(static fn (LogLine $line): string => $line->text, $page->lines),
        );
        $this->assertTrue($page->hasMore);
    }

    public function testTailCursorWalksBackwardToStart(): void
    {
        $this->writeFixture('worker-1.log');
        $reader = new LogLineReader($this->root);

        $pages = [];
        $cursor = null;
        do {
            $page = $reader->read('worker-1.log', new LogReadQuery(LogReadQuery::ANCHOR_TAIL, cursor: $cursor, limit: 3));
            array_unshift($pages, array_map(static fn (LogLine $line): string => $line->text, $page->lines));
            $cursor = $page->nextCursor;
        } while ($page->hasMore);

        $this->assertSame(array_column(self::FIXTURE, 'text'), array_merge(...$pages));
    }

    public function testLevelIsDetectedPerLine(): void
    {
        $this->writeFixture('worker-1.log');
        $reader = new LogLineReader($this->root);

        $page = $reader->read('worker-1.log', new LogReadQuery(LogReadQuery::ANCHOR_HEAD, limit: 100));

        $this->assertSame(
            array_column(self::FIXTURE, 'level'),
            array_map(static fn (LogLine $line): string => $line->detectedLevel, $page->lines),
        );
        $this->assertSame(
            array_column(self::FIXTURE, 'continuation'),
            array_map(static fn (LogLine $line): bool => $line->isContinuation, $page->lines),
        );
    }

    public function testErrorFilterCarriesTheStackTrace(): void
    {
        $this->writeFixture('worker-1.log');
        $reader = new LogLineReader($this->root);

        $page = $reader->read(
            'worker-1.log',
            new LogReadQuery(LogReadQuery::ANCHOR_HEAD, limit: 100, levelFilter: Logger::LEVEL_ERROR),
        );

        // Lines 2 (ERROR), 3-4 (its continuation), 7 (agent ERROR) — the INFO/WARNING/DEBUG lines drop.
        $this->assertSame(
            [self::FIXTURE[1]['text'], self::FIXTURE[2]['text'], self::FIXTURE[3]['text'], self::FIXTURE[6]['text']],
            array_map(static fn (LogLine $line): string => $line->text, $page->lines),
        );
    }

    public function testSubstringFilterKeepsOnlyMatchingLines(): void
    {
        $this->writeFixture('worker-1.log');
        $reader = new LogLineReader($this->root);

        $page = $reader->read(
            'worker-1.log',
            new LogReadQuery(LogReadQuery::ANCHOR_HEAD, limit: 100, substring: 'retry'),
        );

        $this->assertSame(
            [self::FIXTURE[4]['text']],
            array_map(static fn (LogLine $line): string => $line->text, $page->lines),
        );
    }

    public function testTailWithLevelFilterKeepsLastMatches(): void
    {
        $this->writeFixture('worker-1.log');
        $reader = new LogLineReader($this->root);

        $page = $reader->read(
            'worker-1.log',
            new LogReadQuery(LogReadQuery::ANCHOR_TAIL, limit: 2, levelFilter: Logger::LEVEL_ERROR),
        );

        // The four ERROR lines are 2,3,4,7; the last two in file order are the continuation #1 and the agent line.
        $this->assertSame(
            [self::FIXTURE[3]['text'], self::FIXTURE[6]['text']],
            array_map(static fn (LogLine $line): string => $line->text, $page->lines),
        );
    }

    public function testMissingFileYieldsUnavailable(): void
    {
        $reader = new LogLineReader($this->root);

        $page = $reader->read('does-not-exist.log', new LogReadQuery(LogReadQuery::ANCHOR_HEAD));

        $this->assertFalse($page->readable);
        $this->assertSame([], $page->lines);
        $this->assertNull($page->nextCursor);
    }

    public function testPathTraversalIsRejected(): void
    {
        file_put_contents(dirname($this->root) . DIRECTORY_SEPARATOR . 'outside-secret.log', "secret\n");
        $reader = new LogLineReader($this->root);

        $page = $reader->read('../outside-secret.log', new LogReadQuery(LogReadQuery::ANCHOR_HEAD));

        unlink(dirname($this->root) . DIRECTORY_SEPARATOR . 'outside-secret.log');
        $this->assertFalse($page->readable);
    }

    public function testUnresolvedRootYieldsUnavailable(): void
    {
        $reader = new LogLineReader(null);

        $this->assertEquals(LogLinePage::unavailable(), $reader->read('worker-1.log', new LogReadQuery(LogReadQuery::ANCHOR_HEAD)));
    }

    /**
     * Write the fixture lines to a log file under the temp root.
     *
     * @param string $name File basename
     */
    private function writeFixture(string $name): void
    {
        $body = implode("\n", array_column(self::FIXTURE, 'text')) . "\n";
        file_put_contents($this->root . DIRECTORY_SEPARATOR . $name, $body);
    }
}
