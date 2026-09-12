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
 * stack trace), the level and substring filters, and the traversal/availability guard. Also locks the
 * window ceiling a backward scan may be given, the stamp of a line, and the search for the line an entry
 * was written on (HIL-868).
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

    /**
     * Bytes of non-matching padding written past the matches in the window-growth test.
     *
     * Above the reader's own 64 KiB window step, so the backward scan has to take more than one of them before it
     * can say whether an older match remains.
     */
    private const int PADDING_BYTES = 70000;

    /**
     * Ceiling put on the backward reads of the window-ceiling and anchor-search tests.
     *
     * Below the reader's own 64 KiB window step, so the ceiling rather than the step decides where the first window ends.
     */
    private const int CEILING_BYTES = 4096;

    /** The reader's own window step, which the chunk-edge test has to know to place a line across it. */
    private const int WINDOW_STEP_BYTES = 65536;

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

    public function testTailLastPageOfAFilteredSetPromisesNoMore(): void
    {
        $this->writeLines('worker-1.log', [
            '[2026-07-28 12:00:00.001] unrelated',
            '[2026-07-28 12:00:00.002] unrelated',
            '[2026-07-28 12:00:00.003] unrelated',
            '[2026-07-28 12:00:00.004] needle 1',
            '[2026-07-28 12:00:00.005] needle 2',
            '[2026-07-28 12:00:00.006] needle 3',
            '[2026-07-28 12:00:00.007] needle 4',
        ]);
        $reader = new LogLineReader($this->root);

        $first = $reader->read(
            'worker-1.log',
            new LogReadQuery(LogReadQuery::ANCHOR_TAIL, limit: 3, substring: 'needle'),
        );

        $this->assertSame(
            ['[2026-07-28 12:00:00.005] needle 2', '[2026-07-28 12:00:00.006] needle 3', '[2026-07-28 12:00:00.007] needle 4'],
            array_map(static fn (LogLine $line): string => $line->text, $first->lines),
        );
        $this->assertTrue($first->hasMore);

        // The page that carries the last match answers that none is left, so the viewer is not invited to ask again.
        $last = $reader->read(
            'worker-1.log',
            new LogReadQuery(LogReadQuery::ANCHOR_TAIL, cursor: $first->nextCursor, limit: 3, substring: 'needle'),
        );

        $this->assertSame(
            ['[2026-07-28 12:00:00.004] needle 1'],
            array_map(static fn (LogLine $line): string => $line->text, $last->lines),
        );
        $this->assertFalse($last->hasMore);
        $this->assertNull($last->nextCursor);
    }

    public function testTailSetSizedExactlyToThePagePromisesNoMore(): void
    {
        $this->writeLines('worker-1.log', [
            '[2026-07-28 12:00:00.001] unrelated',
            '[2026-07-28 12:00:00.002] unrelated',
            '[2026-07-28 12:00:00.003] needle 1',
            '[2026-07-28 12:00:00.004] needle 2',
            '[2026-07-28 12:00:00.005] needle 3',
        ]);
        $reader = new LogLineReader($this->root);

        // A set sized to a whole number of pages is the second way to reach the same defect: the first page is
        // already the last one, and the non-matching bytes in front of it must not promise an older page.
        $page = $reader->read(
            'worker-1.log',
            new LogReadQuery(LogReadQuery::ANCHOR_TAIL, limit: 3, substring: 'needle'),
        );

        $this->assertSame(
            ['[2026-07-28 12:00:00.003] needle 1', '[2026-07-28 12:00:00.004] needle 2', '[2026-07-28 12:00:00.005] needle 3'],
            array_map(static fn (LogLine $line): string => $line->text, $page->lines),
        );
        $this->assertFalse($page->hasMore);
        $this->assertNull($page->nextCursor);
    }

    public function testTailGrowsThroughChunksToAnswerHasMore(): void
    {
        $lines = [
            '[2026-07-28 12:00:00.001] unrelated',
            '[2026-07-28 12:00:00.002] needle 1',
            '[2026-07-28 12:00:00.003] needle 2',
            '[2026-07-28 12:00:00.004] needle 3',
        ];
        $written = 0;
        while ($written < self::PADDING_BYTES) {
            $padding = '[2026-07-28 12:00:01.000] unrelated ' . count($lines);
            $lines[] = $padding;
            $written += strlen($padding) + 1;
        }
        $this->writeLines('worker-1.log', $lines);
        $reader = new LogLineReader($this->root);

        // Every match sits behind more than one window step, so the answer can only come from growing the window
        // to the start of the file — the branch the first two tests never reach.
        $page = $reader->read(
            'worker-1.log',
            new LogReadQuery(LogReadQuery::ANCHOR_TAIL, limit: 3, substring: 'needle'),
        );

        $this->assertSame(
            ['[2026-07-28 12:00:00.002] needle 1', '[2026-07-28 12:00:00.003] needle 2', '[2026-07-28 12:00:00.004] needle 3'],
            array_map(static fn (LogLine $line): string => $line->text, $page->lines),
        );
        $this->assertFalse($page->hasMore);
        $this->assertNull($page->nextCursor);
    }

    public function testTailStoppedByItsCeilingReadsNoFurtherAndHandsBackTheWindowBoundary(): void
    {
        $lines = $this->needlesAroundPadding();
        $this->writeLines('worker-1.log', $lines);
        $reader = new LogLineReader($this->root);

        $page = $reader->read(
            'worker-1.log',
            new LogReadQuery(LogReadQuery::ANCHOR_TAIL, limit: 3, substring: 'needle', maxWindowBytes: self::CEILING_BYTES),
        );

        // The old needle lies behind the ceiling, so the scan cannot tell whether an older match is there: it says
        // "maybe" and names the first line it read whole as the place to go on from.
        $this->assertSame(
            [$lines[count($lines) - 1]],
            array_map(static fn (LogLine $line): string => $line->text, $page->lines),
        );
        $this->assertTrue($page->hasMore);
        $windowStart = filesize($this->root . DIRECTORY_SEPARATOR . 'worker-1.log') - self::CEILING_BYTES;
        $this->assertSame(
            min(array_filter($this->lineOffsets($lines), static fn (int $offset): bool => $offset > $windowStart)),
            $page->nextCursor,
        );

        $rest = $reader->read(
            'worker-1.log',
            new LogReadQuery(LogReadQuery::ANCHOR_TAIL, cursor: $page->nextCursor, limit: 3, substring: 'needle'),
        );

        $this->assertSame([$lines[0]], array_map(static fn (LogLine $line): string => $line->text, $rest->lines));
        $this->assertFalse($rest->hasMore);
    }

    public function testTailWithoutACeilingStillAnswersByTheMatchPastThePage(): void
    {
        $lines = $this->needlesAroundPadding();
        $this->writeLines('worker-1.log', $lines);
        $reader = new LogLineReader($this->root);

        // The ceiling is a third reason to stop, not a replacement for the rule HIL-882 put in: without one the
        // window still grows to the start of the file and answers "no older page" exactly.
        $page = $reader->read(
            'worker-1.log',
            new LogReadQuery(LogReadQuery::ANCHOR_TAIL, limit: 3, substring: 'needle'),
        );

        $this->assertSame(
            [$lines[0], $lines[count($lines) - 1]],
            array_map(static fn (LogLine $line): string => $line->text, $page->lines),
        );
        $this->assertFalse($page->hasMore);
        $this->assertNull($page->nextCursor);
    }

    public function testStampMillisecondsReadsTheStampALineOpensWith(): void
    {
        $this->assertSame(
            $this->milliseconds('2026-07-28 12:00:00', 250),
            LogLineReader::stampMilliseconds('[2026-07-28 12:00:00.250] WARNING: retrying'),
        );
        // A continuation and an agent-pipe line do not open with a stamp, so nothing orders them.
        $this->assertNull(LogLineReader::stampMilliseconds(self::FIXTURE[2]['text']));
        $this->assertNull(LogLineReader::stampMilliseconds(self::FIXTURE[6]['text']));
    }

    public function testTextAfterLevelTakesOffEitherFormOfTheLevelPrefixAndNothingElse(): void
    {
        $this->assertSame('retrying', LogLineReader::textAfterLevel('[WARNING] retrying'));
        $this->assertSame('retrying', LogLineReader::textAfterLevel('WARNING: retrying'));
        $this->assertSame('connection failed', LogLineReader::textAfterLevel('ERROR: connection failed'));
        // Text that only mentions a level opens with no prefix, and a line without one stays as it is.
        $this->assertSame('WARNING retrying', LogLineReader::textAfterLevel('WARNING retrying'));
        $this->assertSame('server started', LogLineReader::textAfterLevel('server started'));
    }

    public function testLocateFindsTheLineAnEntryWasWrittenOn(): void
    {
        $this->writeFixture('worker-1.log');
        $reader = new LogLineReader($this->root);

        // The WARNING entry is stamped .003 and follows an ERROR whose trace runs over two unstamped lines.
        $this->assertSame(
            $this->lineOffsets(array_column(self::FIXTURE, 'text'))[4],
            $reader->locate('worker-1.log', $this->milliseconds('2026-07-28 12:00:00', 3), self::CEILING_BYTES),
        );
    }

    public function testLocateBetweenTwoLinesLandsOnTheLaterOne(): void
    {
        $lines = ['[2026-07-28 12:00:00.001] before', '[2026-07-28 12:00:00.009] after'];
        $this->writeLines('worker-1.log', $lines);
        $reader = new LogLineReader($this->root);

        // The earlier line inside the window proves the boundary, so the first line past it is the place.
        $this->assertSame(
            $this->lineOffsets($lines)[1],
            $reader->locate('worker-1.log', $this->milliseconds('2026-07-28 12:00:00', 5), self::CEILING_BYTES),
        );
    }

    public function testLocateFindsAPlaceSeveralWindowStepsBack(): void
    {
        $lines = ['[2026-07-28 12:00:00.000] before', ...$this->needlesAroundPadding()];
        $this->writeLines('worker-1.log', $lines);
        $reader = new LogLineReader($this->root);
        $offsets = $this->lineOffsets($lines);

        // The old needle is the first line not earlier than .001, the first padding line the first not earlier than
        // 12:00:01 — both more than one window step from the end, each found past the boundary the walk reaches.
        $this->assertSame($offsets[1], $reader->locate('worker-1.log', $this->milliseconds('2026-07-28 12:00:00', 1), self::PADDING_BYTES * 2));
        $this->assertSame($offsets[2], $reader->locate('worker-1.log', $this->milliseconds('2026-07-28 12:00:01', 0), self::PADDING_BYTES * 2));
    }

    public function testLocateReadsWholeTheLineAWindowStepCutsInTwo(): void
    {
        $lines = $this->needlesAroundPadding();
        $offsets = $this->lineOffsets($lines);
        $fileSize = $offsets[count($offsets) - 1] + strlen($lines[count($lines) - 1]) + 1;
        $cut = max(array_keys(array_filter($offsets, static fn (int $offset): bool => $offset < $fileSize - self::WINDOW_STEP_BYTES)));
        foreach ($lines as $index => $line) {
            if ($index === $cut) {
                $lines[$index] = str_replace('12:00:01.000]', '12:00:01.500]', $line);
            } elseif ($index > $cut) {
                $lines[$index] = str_replace('12:00:01.000]', '12:00:01.900]', $line);
            }
        }
        $this->writeLines('worker-1.log', $lines);
        $reader = new LogLineReader($this->root);

        // The entry's line starts before the edge of the first chunk and ends after it: dropped there as a fragment,
        // it has to be read again whole with the next chunk, or the place would land one line off.
        $this->assertSame(
            $offsets[$cut],
            $reader->locate('worker-1.log', $this->milliseconds('2026-07-28 12:00:01', 500), self::PADDING_BYTES * 2),
        );
    }

    public function testLocateAnswersNullForAMomentNewerThanTheLastLine(): void
    {
        $this->writeFixture('worker-1.log');
        $reader = new LogLineReader($this->root);

        $this->assertNull($reader->locate('worker-1.log', $this->milliseconds('2026-07-28 12:00:00', 7), self::CEILING_BYTES));
    }

    public function testLocateAnswersNullWhenThePlaceLiesBeyondTheCeiling(): void
    {
        $this->writeLines('worker-1.log', $this->needlesAroundPadding());
        $reader = new LogLineReader($this->root);
        $atMs = $this->milliseconds('2026-07-28 12:00:00', 1);

        // Within reach of a generous ceiling the old needle is found at the start of the file; behind a tight one the
        // window holds only later lines, which prove nothing, so no place is claimed.
        $this->assertSame(0, $reader->locate('worker-1.log', $atMs, self::PADDING_BYTES * 2));
        $this->assertNull($reader->locate('worker-1.log', $atMs, self::CEILING_BYTES));
    }

    public function testLocateAtTheStartOfTheFileClaimsOnlyTheExactMoment(): void
    {
        $this->writeLines('worker-1.log', [
            '[2026-07-28 12:00:00.005] first line after rotation',
            '[2026-07-28 12:00:00.006] next',
        ]);
        $reader = new LogLineReader($this->root);

        // No earlier line is left to prove a boundary: an entry older than the file went away with the rotated one,
        // and opening the viewer on the first line would claim that line is the place.
        $this->assertNull($reader->locate('worker-1.log', $this->milliseconds('2026-07-28 12:00:00', 2), self::CEILING_BYTES));
        $this->assertSame(0, $reader->locate('worker-1.log', $this->milliseconds('2026-07-28 12:00:00', 5), self::CEILING_BYTES));
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
     * Write the given lines to a log file under the temp root.
     *
     * @param string $name File basename
     * @param list<string> $lines Lines in file order, each written with a trailing newline
     */
    private function writeLines(string $name, array $lines): void
    {
        file_put_contents($this->root . DIRECTORY_SEPARATOR . $name, implode("\n", $lines) . "\n");
    }

    /**
     * An old needle at the start of a file, more than one window step of later padding, and a recent needle at its end.
     *
     * @return list<string> Lines in file order
     */
    private function needlesAroundPadding(): array
    {
        $lines = ['[2026-07-28 12:00:00.001] needle old'];
        $written = 0;
        while ($written < self::PADDING_BYTES) {
            $padding = '[2026-07-28 12:00:01.000] unrelated ' . count($lines);
            $lines[] = $padding;
            $written += strlen($padding) + 1;
        }
        $lines[] = '[2026-07-28 12:00:02.000] needle recent';

        return $lines;
    }

    /**
     * Byte offset each line starts at once written by {@see writeLines()}.
     *
     * @param list<string> $lines Lines in file order
     *
     * @return list<int> Start offset of each line, in the same order
     */
    private function lineOffsets(array $lines): array
    {
        $offsets = [];
        $offset = 0;
        foreach ($lines as $line) {
            $offsets[] = $offset;
            $offset += strlen($line) + 1;
        }

        return $offsets;
    }

    /**
     * The unix milliseconds a line stamped with this local time must be read as.
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
