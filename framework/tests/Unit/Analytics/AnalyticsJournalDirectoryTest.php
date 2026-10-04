<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Analytics;

use Hilos\Core\Analytics\AnalyticsJournalDirectory;
use Hilos\Core\Analytics\AnalyticsJournalRecord;
use Hilos\Fs\FsException;
use PHPUnit\Framework\TestCase;

/**
 * The file half of a node's analytics journal (HIL-1154), on a real temporary directory.
 *
 * The clock is handed in, so every case drives time by numbers rather than by waiting.
 */
final class AnalyticsJournalDirectoryTest extends TestCase
{
    private const int T0 = 1_800_000_000_000;

    private const string NODE = 'node-1';

    private string $root = '';

    private string $path = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hilos-analytics-journal-' . bin2hex(random_bytes(6));
        $this->path = $this->root . '/test/' . self::NODE;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    /**
     * @throws FsException When the journal cannot be set up
     */
    public function testNoFileIsOpenedUntilSomethingIsWritten(): void
    {
        $journal = $this->startedJournal();

        $this->assertNull($journal->tick(self::T0 + AnalyticsJournalDirectory::ROTATE_AGE_MS * 3));
        $this->assertNull($journal->append([], 0, self::T0));
        $this->assertNull($journal->rotate(self::T0));
        $this->assertSame([], $this->files());
    }

    /**
     * @throws FsException When the journal cannot be written
     */
    public function testTheFirstLineOfAFileIsItsHeaderAndTheFileIsTheOwnersAlone(): void
    {
        $journal = $this->startedJournal();

        $journal->append(['{"t":"a"}', '{"t":"b"}'], 2, self::T0);

        $files = $this->files();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^000000000001-[0-9a-f]{16}\.open$/', $files[0]);
        $lines = explode("\n", rtrim((string)file_get_contents($this->path . '/' . $files[0]), "\n"));
        $this->assertSame(AnalyticsJournalRecord::journal(self::NODE, self::T0), json_decode($lines[0], true));
        $this->assertSame(['{"t":"a"}', '{"t":"b"}'], array_slice($lines, 1));
        $this->assertSame(0600, fileperms($this->path . '/' . $files[0]) & 0777);
        $this->assertSame(0700, fileperms($this->path) & 0777);
        $this->assertSame(filesize($this->path . '/' . $files[0]), $journal->bytes());
    }

    /**
     * @throws FsException When the journal cannot be written
     */
    public function testAFileRotatesTenSecondsAfterItOpened(): void
    {
        $journal = $this->startedJournal();
        $journal->append(['{"t":"a"}'], 1, self::T0);

        $this->assertNull($journal->tick(self::T0 + AnalyticsJournalDirectory::ROTATE_AGE_MS - 1));
        $ready = $journal->tick(self::T0 + AnalyticsJournalDirectory::ROTATE_AGE_MS);

        $this->assertNotNull($ready);
        $this->assertTrue(AnalyticsJournalDirectory::isReadyName($ready));
        $this->assertSame([$ready], $this->files());
        $this->assertSame(1, $this->records($ready)[2][AnalyticsJournalRecord::KEY_EVENTS]);
    }

    /**
     * @throws FsException When the journal cannot be written
     */
    public function testAFileRotatesOnceItHoldsAMebibyte(): void
    {
        $journal = $this->startedJournal();
        $line = '{"t":"' . str_repeat('x', 1000) . '"}';

        $ready = null;
        $writes = 0;
        while ($ready === null) {
            $ready = $journal->append([$line], 1, self::T0);
            $writes++;
        }

        $this->assertGreaterThanOrEqual(AnalyticsJournalDirectory::ROTATE_BYTES, filesize($this->path . '/' . $ready));
        $this->assertLessThan(AnalyticsJournalDirectory::ROTATE_BYTES + strlen($line) + 1, filesize($this->path . '/' . $ready));
        $this->assertSame(1, preg_match('/^000000000001-/', $ready));

        $journal->append([$line], 1, self::T0);
        $this->assertSame(1, count(array_filter($this->files(), static fn(string $file): bool => str_starts_with($file, '000000000002-'))));
        $this->assertGreaterThan(1000, $writes);
        $this->assertSame($writes, $this->records($ready)[array_key_last($this->records($ready))][AnalyticsJournalRecord::KEY_EVENTS]);
    }

    /**
     * The sync is a second's business, not a write's: a tick inside the second does not ask the
     * disk, the first tick past it does, and a tick with nothing new written does not either.
     * What a sync does to the disk cannot be seen from here; the clock it keeps can.
     *
     * @throws FsException When the journal cannot be written or synced
     */
    public function testTheSyncFollowsTheClockAndNotTheWrites(): void
    {
        $journal = $this->startedJournal();
        $journal->append(['{"t":"a"}'], 1, self::T0);

        $this->assertNull($journal->tick(self::T0 + AnalyticsJournalDirectory::SYNC_INTERVAL_MS));
        $this->assertNull($journal->tick(self::T0 + AnalyticsJournalDirectory::SYNC_INTERVAL_MS + 10));
        $journal->append(['{"t":"b"}'], 1, self::T0 + AnalyticsJournalDirectory::SYNC_INTERVAL_MS + 20);
        $this->assertNull($journal->tick(self::T0 + 2 * AnalyticsJournalDirectory::SYNC_INTERVAL_MS + 20));

        $this->assertCount(1, $this->files());
    }

    /**
     * @throws FsException When the journal cannot be set up
     */
    public function testAStartClosesTheFileAPreviousLifeLeftOpenAndContinuesTheNumbers(): void
    {
        $journal = $this->startedJournal();
        $journal->append(['{"t":"a"}'], 1, self::T0);
        $journal->rotate(self::T0);
        $journal->append(['{"t":"b"}'], 1, self::T0);

        $next = new AnalyticsJournalDirectory($this->path, self::NODE);
        $this->assertSame(1, $next->start());
        $next->append(['{"t":"c"}'], 1, self::T0);
        $next->rotate(self::T0);

        $files = $this->files();
        $this->assertCount(3, $files);
        foreach ($files as $index => $file) {
            $this->assertTrue(AnalyticsJournalDirectory::isReadyName($file), $file);
            $this->assertStringStartsWith(sprintf('%012d-', $index + 1), $file);
        }
        $this->assertSame($files[0], $next->oldestReady());
        $this->assertSame(1, $this->records($files[1])[array_key_last($this->records($files[1]))][AnalyticsJournalRecord::KEY_EVENTS]);
    }

    /**
     * @throws FsException When the journal cannot be read
     */
    public function testAPortionSkipsAnOversizedLineAndAdvancesPastIt(): void
    {
        $journal = $this->startedJournal();
        $short = '{"t":"' . str_repeat('s', 40_000) . '"}';
        $long = '{"t":"' . str_repeat('l', AnalyticsJournalDirectory::PORTION_BYTES + 10) . '"}';
        $journal->append([$short, $short, $short, $short, $long, $short], 6, self::T0);
        $ready = (string)$journal->rotate(self::T0);

        $read = [];
        $offset = 0;
        $portions = 0;
        $passedOver = 0;
        do {
            $portion = $journal->readPortion($ready, $offset);
            $this->assertNotNull($portion);
            $read = [...$read, ...$portion->lines];
            $offset = $portion->nextOffset;
            $passedOver += $portion->passedOver;
            $portions++;
        } while (!$portion->complete);

        $this->assertSame([$short, $short, $short, $short, $short], array_slice($read, 1, -1));
        $this->assertSame(1, $passedOver);
        $this->assertSame(filesize($this->path . '/' . $ready), $offset);
        $this->assertGreaterThanOrEqual(3, $portions);
    }

    /**
     * @throws FsException When the journal cannot be read
     */
    public function testTheHalfLineAMachineCrashLeftComesAsALine(): void
    {
        $journal = $this->startedJournal();
        $journal->append(['{"t":"a"}'], 1, self::T0);
        $ready = (string)$journal->rotate(self::T0);
        file_put_contents($this->path . '/' . $ready, '{"t":"b', FILE_APPEND);

        $whole = $journal->readPortion($ready, 0);
        $this->assertNotNull($whole);
        $this->assertFalse($whole->complete);
        $this->assertSame(['{"t":"a"}'], array_slice($whole->lines, 1, -1));

        $tail = $journal->readPortion($ready, $whole->nextOffset);
        $this->assertNotNull($tail);
        $this->assertTrue($tail->complete);
        $this->assertSame(['{"t":"b'], $tail->lines);
    }

    /**
     * @throws FsException When the journal cannot be read or deleted
     */
    public function testANameOffThePatternIsNoFileAndDeletingTwiceIsNoError(): void
    {
        $journal = $this->startedJournal();
        $journal->append(['{"t":"a"}'], 1, self::T0);
        $ready = (string)$journal->rotate(self::T0);

        $this->assertNull($journal->readPortion('../' . $ready, 0));
        $this->assertNull($journal->readPortion(str_replace('.jsonl', '.open', $ready), 0));
        $this->assertFalse(AnalyticsJournalDirectory::isReadyName('../' . $ready));
        $journal->delete('../' . $ready);
        $this->assertSame([$ready], $this->files());

        $journal->delete($ready);
        $journal->delete($ready);
        $this->assertSame([], $this->files());
        $this->assertNull($journal->readPortion($ready, 0));
        $this->assertNull($journal->oldestReady());
    }

    /**
     * @throws FsException When the journals cannot be set up or read
     */
    public function testAnotherEnvironmentsJournalIsNotSeen(): void
    {
        $other = new AnalyticsJournalDirectory($this->root . '/prod/' . self::NODE, self::NODE);
        $other->start();
        $other->append(['{"t":"a"}'], 1, self::T0);
        $other->rotate(self::T0);

        $journal = $this->startedJournal();

        $this->assertNull($journal->oldestReady());
    }

    /**
     * @throws FsException When the journal cannot be set up or emptied
     */
    public function testDiscardingThrowsTheWholeJournalAwayAndTheNextFileStartsAfresh(): void
    {
        $journal = $this->startedJournal();
        $journal->append(['{"t":"a"}'], 1, self::T0);
        $journal->rotate(self::T0);
        $journal->append(['{"t":"b"}'], 1, self::T0);

        $discarded = $journal->discardAll();
        $this->assertSame(2, $discarded->files);
        $this->assertSame(2, $discarded->events);
        $this->assertSame(self::T0, $discarded->oldestOpenedTs);
        $this->assertSame(0, $journal->bytes());
        $this->assertSame([], $this->files());
        $this->assertNull($journal->rotate(self::T0));

        $next = new AnalyticsJournalDirectory($this->path, self::NODE);
        $next->start();
        $next->append(['{"t":"c"}'], 1, self::T0);
        $this->assertStringStartsWith('000000000001-', $this->files()[0]);
    }

    /**
     * @throws FsException When a journal write or delete fails
     */
    public function testBytesFollowAppendRotationAndConfirmation(): void
    {
        $journal = $this->startedJournal();
        $journal->append(['{"t":"a"}'], 1, self::T0);
        $ready = (string)$journal->rotate(self::T0 + 1);
        $readyBytes = filesize($this->path . '/' . $ready);
        $journal->append(['{"t":"b"}'], 1, self::T0 + 2);
        $open = array_values(array_filter($this->files(), static fn(string $file): bool => str_ends_with($file, '.open')))[0];
        self::assertSame($readyBytes + filesize($this->path . '/' . $open), $journal->bytes());

        $journal->delete($ready);
        self::assertSame(filesize($this->path . '/' . $open), $journal->bytes());
        $journal->discardAll();
        self::assertSame(0, $journal->bytes());
    }

    /**
     * @throws FsException When the leftover file cannot be closed
     */
    public function testARecoveredOpenFileSeparatesItsBrokenTailFromItsSummary(): void
    {
        $journal = $this->startedJournal();
        $journal->append(['{"t":"a"}'], 1, self::T0);
        $open = $this->files()[0];
        file_put_contents($this->path . '/' . $open, '{"t":"broken', FILE_APPEND);

        $next = new AnalyticsJournalDirectory($this->path, self::NODE);
        self::assertSame(1, $next->start());
        $ready = $this->files()[0];
        $lines = explode("\n", rtrim((string)file_get_contents($this->path . '/' . $ready), "\n"));
        self::assertSame('{"t":"broken', $lines[2]);
        self::assertSame(AnalyticsJournalRecord::TYPE_JOURNAL_END, json_decode($lines[3], true)[AnalyticsJournalRecord::KEY_TYPE]);
        self::assertSame(1, json_decode($lines[3], true)[AnalyticsJournalRecord::KEY_EVENTS]);
        self::assertSame(filesize($this->path . '/' . $ready), $next->bytes());
    }

    /**
     * @throws FsException When a file cannot be read or discarded
     */
    public function testDiscardUsesSummariesAndCountsAnOlderFileWithoutOne(): void
    {
        $journal = $this->startedJournal();
        $journal->append(['{"t":"a"}', '{"t":"b"}'], 2, self::T0);
        $old = (string)$journal->rotate(self::T0);
        $content = (string)file_get_contents($this->path . '/' . $old);
        file_put_contents($this->path . '/' . $old, substr($content, 0, (int)strrpos($content, '{"t":"journal_end"')));
        $journal->append(['{"t":"c"}'], 1, self::T0 + 1);
        $journal->rotate(self::T0 + 1);
        $journal->append(['{"t":"d"}'], 1, self::T0 + 2);

        $discarded = $journal->discardAll();
        self::assertSame(3, $discarded->files);
        self::assertSame(4, $discarded->events);
        self::assertSame(self::T0, $discarded->oldestOpenedTs);
        self::assertSame([], $this->files());
    }

    /**
     * @throws FsException When the loss state cannot be written, read or deleted
     */
    public function testLossStateIsReplacedAtomicallyAndNeverListedAsJournal(): void
    {
        $journal = $this->startedJournal();
        $journal->writeLossState('{"v":1}');
        $journal->writeLossState('{"v":2}');
        self::assertSame('{"v":2}', $journal->readLossState());
        self::assertSame(0600, fileperms($this->path . '/losses.json') & 0777);
        self::assertSame(['losses.json'], $this->files());
        self::assertNull($journal->oldestReady());
        self::assertSame(0, $journal->bytes());

        $journal->deleteLossState();
        self::assertNull($journal->readLossState());
        self::assertSame([], $this->files());
    }

    /**
     * @return AnalyticsJournalDirectory A journal over this case's directory, set up
     * @throws FsException When the journal cannot be set up
     */
    private function startedJournal(): AnalyticsJournalDirectory
    {
        $journal = new AnalyticsJournalDirectory($this->path, self::NODE);
        $journal->start();

        return $journal;
    }

    /**
     * @return list<string> Files of the journal's subdirectory, sorted
     */
    private function files(): array
    {
        $files = array_values(array_diff((array)scandir($this->path), ['.', '..']));
        sort($files);

        return $files;
    }

    /**
     * @param string $file Ready file name
     * @return list<array<string, mixed>> Its decoded records
     */
    private function records(string $file): array
    {
        $lines = array_filter(explode("\n", (string)file_get_contents($this->path . '/' . $file)));

        return array_values(array_map(static fn(string $line): array => (array)json_decode($line, true), $lines));
    }

    /**
     * @param string $path Directory to remove with everything under it
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff((array)scandir($path), ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }

        rmdir($path);
    }
}
