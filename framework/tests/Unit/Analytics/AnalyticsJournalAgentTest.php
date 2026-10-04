<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Analytics;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsJournalDirectory;
use Hilos\Core\Analytics\AnalyticsJournalRecord;
use Hilos\Core\Analytics\AnalyticsJournalLosses;
use Hilos\Core\Analytics\AnalyticsLossCount;
use Hilos\Core\Analytics\AnalyticsLossReason;
use Hilos\Core\Analytics\AnalyticsSettingsCatalog;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalLoadedSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalPortionSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadySignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The journal agent of a node (HIL-1154): a batch becomes lines of a file, a read is answered with
 * a portion, a confirmation deletes the file, and a stop either closes the journal or - under a
 * freeze - throws it away.
 */
final class AnalyticsJournalAgentTest extends TestCase
{
    private const string NODE = 'node-1';

    private const string SENDER = 'agent:hilos_analytics_writer';

    private string $root = '';

    private string $path = '';

    private string $logDir = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hilos-analytics-agent-' . bin2hex(random_bytes(6));
        $this->path = $this->root . '/test/' . self::NODE;
        // An agent writes its lines into a stream beside the process log, so the case reads the directory.
        $this->logDir = $this->root . '-log';
        mkdir($this->logDir);
        Logger::setLogFile($this->logDir . '/daemon.log');
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = null;
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$rt = null;
        Logger::resetLogFile();
        $this->removeTree($this->logDir);
        $this->removeTree($this->root);

        parent::tearDown();
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testABatchBecomesLinesOfTheOpenFileAndBrokenLinesAreDropped(): void
    {
        $agent = $this->startedAgent();

        $this->append($agent, ['{"t":"a"}', '', "{\"t\":\n\"b\"}", '{"t":"c"}']);

        $files = $this->files();
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.open', $files[0]);
        $lines = explode("\n", rtrim((string)file_get_contents($this->path . '/' . $files[0]), "\n"));
        $this->assertSame(['{"t":"a"}', '{"t":"c"}'], array_slice($lines, 1));
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testAReadIsAnsweredWithTheOldestReadyFileAndAConfirmationDeletesIt(): void
    {
        $agent = $this->startedAgent();
        $this->append($agent, ['{"t":"a"}']);
        $agent->onStop();

        $this->read($agent, '', 0);
        $portion = $this->portion();
        $this->assertSame(self::NODE, $portion->nodeId);
        $this->assertTrue(AnalyticsJournalDirectory::isReadyName($portion->file));
        $this->assertSame(['{"t":"a"}'], array_slice($portion->lines, 1, -1));
        $this->assertSame(AnalyticsJournalRecord::TYPE_JOURNAL_END, json_decode($portion->lines[array_key_last($portion->lines)], true)['t']);
        $this->assertTrue($portion->complete);
        $this->assertFalse($portion->gone);

        $agent->onSignalAgent(
            new AgentSignalData(data: new AnalyticsJournalLoadedSignalData(self::NODE, $portion->file)),
            self::SENDER,
            HilosSignalConstants::ANALYTICS_JOURNAL_LOADED,
        );
        $this->assertSame([], $this->files());

        $this->read($agent, $portion->file, 0);
        $this->assertTrue($this->portion()->gone);

        $this->read($agent, '', 0);
        $empty = $this->portion();
        $this->assertSame('', $empty->file);
        $this->assertFalse($empty->gone);
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testAnOversizedLineIsOmittedAndWarnedAboutOncePerPortion(): void
    {
        $agent = $this->startedAgent();
        $long = str_repeat('x', AnalyticsJournalRecord::MAX_LINE_BYTES + 1);
        $this->append($agent, [$long, '{"t":"small"}']);
        $agent->onStop();

        $this->read($agent, '', 0);
        $portion = $this->portion();
        $lines = $portion->lines;
        while (!$portion->complete) {
            $this->read($agent, $portion->file, $portion->nextOffset);
            $portion = $this->portion();
            $lines = [...$lines, ...$portion->lines];
        }

        $this->assertSame(['{"t":"small"}'], array_slice($lines, 1, -1));
        $warning = 'passed over 1 line(s) longer than ' . AnalyticsJournalRecord::MAX_LINE_BYTES;
        $this->assertStringContainsString($warning . " bytes in {$portion->file}", $this->agentLog());
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testAnOrdinaryStopLeavesTheJournalReadyForTheWriter(): void
    {
        $agent = $this->startedAgent();
        $this->append($agent, ['{"t":"a"}']);

        $agent->onStop();

        $files = $this->files();
        $this->assertCount(1, $files);
        $this->assertTrue(AnalyticsJournalDirectory::isReadyName($files[0]));
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testSizeRotationAnnouncesAReadyFile(): void
    {
        $agent = $this->startedAgent();
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->append($agent, array_fill(0, 9, str_repeat('x', 120_000)));
        $this->assertReadyNotice();
    }

    /**
     * A failed rename follows a successful append: the bytes remain in the open file and
     * must not also become a journal_unwritable loss episode.
     *
     * @throws HilosException When a frame or ready notice cannot be handled
     */
    public function testFailedRotationDoesNotCountAnAlreadyWrittenBatchAsLost(): void
    {
        $agent = $this->startedAgent();
        $this->append($agent, ['{"t":"first"}'], 1);
        $open = $this->files()[0];
        $readyPath = $this->path . '/' . substr($open, 0, -strlen('.open')) . '.jsonl';
        mkdir($readyPath);
        try {
            $large = '{"t":"' . str_repeat('x', 120_000) . '"}';
            $this->append($agent, array_fill(0, 9, $large), 9);
            self::assertStringContainsString('cannot sync or rotate', $this->agentLog());
        } finally {
            rmdir($readyPath);
        }

        $agent->step((int)floor(microtime(true) * 1000));
        $records = $this->journalRecords();
        self::assertCount(10, array_filter($records, static fn(array $record): bool => !in_array(
            $record[AnalyticsJournalRecord::KEY_TYPE],
            [AnalyticsJournalRecord::TYPE_JOURNAL, AnalyticsJournalRecord::TYPE_JOURNAL_END],
            true,
        )));
        self::assertNotContains(AnalyticsJournalRecord::TYPE_LOSS, array_column($records, AnalyticsJournalRecord::KEY_TYPE));
        self::assertSame(10, $records[array_key_last($records)][AnalyticsJournalRecord::KEY_EVENTS]);
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testAgeRotationAnnouncesAReadyFile(): void
    {
        $journal = new AnalyticsJournalDirectory($this->path, self::NODE);
        $agent = new AnalyticsJournalAgent();
        $agent->openJournal($journal, self::NODE, AnalyticsSettingsCatalog::DEFAULT_JOURNAL_MAX_BYTES);
        $journal->append(['{"t":"a"}'], 1, 1);

        $agent->onTick();
        $this->assertReadyNotice();
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testStartAnnouncesFilesLeftReadyByAnEarlierLife(): void
    {
        $journal = new AnalyticsJournalDirectory($this->path, self::NODE);
        $journal->start();
        $journal->append(['{"t":"a"}'], 1, 1);
        $journal->rotate(1);

        $this->startedAgent();
        $this->assertReadyNotice();
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testAFailedStartRetriesAndAnnouncesTheFilesItThenFinds(): void
    {
        mkdir($this->root);
        file_put_contents($this->root . '/test', 'blocks the journal directory');
        $agent = new AnalyticsJournalAgent();
        $agent->openJournal(new AnalyticsJournalDirectory($this->path, self::NODE), self::NODE, AnalyticsSettingsCatalog::DEFAULT_JOURNAL_MAX_BYTES);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        unlink($this->root . '/test');
        $journal = new AnalyticsJournalDirectory($this->path, self::NODE);
        $journal->start();
        $journal->append(['{"t":"a"}'], 1, 1);
        $journal->rotate(1);

        $agent->onTick();
        $this->assertReadyNotice();
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testAStopUnderTheFreezeThrowsTheWholeJournalAway(): void
    {
        $agent = $this->startedAgent();
        $this->append($agent, ['{"t":"a"}']);
        $agent->onStop();
        $agent = $this->startedAgent();
        $this->append($agent, ['{"t":"b"}'], 1, [
            new AnalyticsLossCount(AnalyticsLossReason::PAYLOAD_DROPPED, 1, 10, 20),
        ]);

        $this->freeze(ProtectedModeRuntime::PHASE_ACTIVATING);
        $agent->onStop();

        $files = $this->files();
        $this->assertCount(1, $files);
        $this->assertTrue(AnalyticsJournalDirectory::isReadyName($files[0]));
        $records = array_map(
            static fn(string $line): array => (array)json_decode($line, true),
            array_filter(explode("\n", (string)file_get_contents($this->path . '/' . $files[0]))),
        );
        $this->assertSame([
            AnalyticsJournalRecord::TYPE_JOURNAL,
            AnalyticsJournalRecord::TYPE_LOSS,
            AnalyticsJournalRecord::TYPE_LOSS,
            AnalyticsJournalRecord::TYPE_JOURNAL_END,
        ], array_column($records, AnalyticsJournalRecord::KEY_TYPE));
        $this->assertSame(2, $records[1][AnalyticsJournalRecord::KEY_EVENTS]);
        $this->assertSame('restore', $records[1][AnalyticsJournalRecord::KEY_REASON]);
        $this->assertSame('payload_dropped', $records[2][AnalyticsJournalRecord::KEY_REASON]);
        $this->assertFileDoesNotExist($this->path . '/losses.json');
        $this->assertStringContainsString('2 file(s) of this node thrown away', $this->agentLog());
    }

    /**
     * @throws HilosException When a frame or ready notice cannot be handled
     */
    public function testCeilingDropsNewBatchesButAClosedLossWritesPastIt(): void
    {
        $agent = $this->startedAgent(1);
        $this->append($agent, ['{"t":"first"}'], 1);
        $this->append($agent, ['{"t":"second"}'], 1, [
            new AnalyticsLossCount(AnalyticsLossReason::PAYLOAD_DROPPED, 1, 10, 10),
        ]);
        $this->append($agent, ['{"t":"third"}'], 1);

        $nowMs = (int)floor(microtime(true) * 1000);
        $agent->step($nowMs + AnalyticsJournalLosses::QUIET_MS + 1);
        $agent->onStop();

        $records = $this->journalRecords();
        self::assertContains('first', array_column($records, AnalyticsJournalRecord::KEY_TYPE));
        self::assertNotContains('second', array_column($records, AnalyticsJournalRecord::KEY_TYPE));
        self::assertNotContains('third', array_column($records, AnalyticsJournalRecord::KEY_TYPE));
        $losses = array_values(array_filter($records, static fn(array $record): bool => $record['t'] === AnalyticsJournalRecord::TYPE_LOSS));
        $counts = array_map(static fn(array $record): array => [$record['reason'], $record['events']], $losses);
        sort($counts);
        self::assertSame([
            ['journal_full', 2],
            ['payload_dropped', 1],
        ], $counts);
        self::assertSame(1, substr_count($this->agentLog(), 'at its ceiling of 1 bytes'));
        self::assertStringContainsString('closed with 2 event(s)', $this->agentLog());
        self::assertFileDoesNotExist($this->path . '/losses.json');
    }

    /**
     * @throws HilosException When a saved count cannot be written or reopened
     */
    public function testStartClosesEpisodesSavedByThePreviousLife(): void
    {
        $journal = new AnalyticsJournalDirectory($this->path, self::NODE);
        $journal->start();
        $losses = new AnalyticsJournalLosses();
        $losses->add(new AnalyticsLossCount(AnalyticsLossReason::JOURNAL_UNWRITABLE, 3, 10, 20), 20);
        $journal->writeLossState($losses->toJson());

        $agent = $this->startedAgent();
        self::assertFileDoesNotExist($this->path . '/losses.json');
        $agent->onStop();

        $records = $this->journalRecords();
        $closed = array_values(array_filter($records, static fn(array $record): bool => $record['t'] === AnalyticsJournalRecord::TYPE_LOSS));
        self::assertSame('journal_unwritable', $closed[0]['reason']);
        self::assertSame(3, $closed[0]['events']);
    }

    /**
     * @throws HilosException When a failed start or later write cannot be handled
     */
    public function testFailedDirectoryStartCountsTheBatchItCouldNotWrite(): void
    {
        mkdir($this->root);
        file_put_contents($this->root . '/test', 'blocks the journal directory');
        $agent = new AnalyticsJournalAgent();
        $agent->openJournal(new AnalyticsJournalDirectory($this->path, self::NODE), self::NODE, 1);
        $this->append($agent, ['{"t":"lost"}'], 1);

        unlink($this->root . '/test');
        $nowMs = (int)floor(microtime(true) * 1000);
        $agent->step($nowMs + AnalyticsJournalLosses::QUIET_MS + 1);
        $agent->onStop();

        $records = $this->journalRecords();
        $closed = array_values(array_filter($records, static fn(array $record): bool => $record['t'] === AnalyticsJournalRecord::TYPE_LOSS));
        self::assertSame('journal_unwritable', $closed[0]['reason']);
        self::assertSame(1, $closed[0]['events']);
    }

    /**
     * @throws HilosException When the journal cannot be opened or ticked
     */
    public function testUnreadableCeilingRetainsTheLastValueAndReportsOnce(): void
    {
        $previous = Hilos::$setting;
        $settings = new class(AnalyticsSettingsCatalog::class) extends SettingsAccessor {
            public int $value = AnalyticsJournalDirectory::ROTATE_BYTES;

            public function effectiveValueFor(string $key): mixed
            {
                return $this->value;
            }
        };
        Hilos::$setting = $settings;
        try {
            $agent = new AnalyticsJournalAgent();
            $agent->openJournal(new AnalyticsJournalDirectory($this->path, self::NODE), self::NODE);
            $settings->value = 1;
            $nowMs = (int)floor(microtime(true) * 1000);
            $agent->step($nowMs + 5000);
            $agent->step($nowMs + 10000);
            self::assertSame(1, substr_count($this->agentLog(), 'cannot read its ceiling'));
            $this->append($agent, ['{"t":"kept"}'], 1);
            self::assertContains('kept', array_column($this->journalRecords(), AnalyticsJournalRecord::KEY_TYPE));
        } finally {
            Hilos::$setting = $previous;
        }
    }

    /**
     * @return AnalyticsJournalAgent An agent over this case's journal, started
     */
    private function startedAgent(?int $ceilingBytes = null): AnalyticsJournalAgent
    {
        $agent = new AnalyticsJournalAgent();
        $agent->openJournal(
            new AnalyticsJournalDirectory($this->path, self::NODE),
            self::NODE,
            $ceilingBytes ?? AnalyticsSettingsCatalog::DEFAULT_JOURNAL_MAX_BYTES,
        );

        return $agent;
    }

    /**
     * @param AnalyticsJournalAgent $agent Agent to hand the batch to
     * @param list<string> $lines Lines of the batch
     * @param ?int $events Event count when not equal to the line count
     * @param list<AnalyticsLossCount> $losses Source losses
     * @throws HilosException When the frame cannot be handled
     */
    private function append(AnalyticsJournalAgent $agent, array $lines, ?int $events = null, array $losses = []): void
    {
        $agent->onSignalAgent(
            new AgentSignalData(data: new AnalyticsJournalAppendSignalData($lines, $events ?? count($lines), $losses)),
            'worker',
            HilosSignalConstants::ANALYTICS_JOURNAL_APPEND,
        );
    }

    /**
     * @param AnalyticsJournalAgent $agent Agent to ask
     * @param string $file File to read, '' for the oldest
     * @param int $offset Offset to read from
     * @throws HilosException When the frame cannot be handled
     */
    private function read(AnalyticsJournalAgent $agent, string $file, int $offset): void
    {
        $agent->onSignalAgent(
            new AgentSignalData(data: new AnalyticsJournalReadSignalData(self::NODE, $file, $offset)),
            self::SENDER,
            HilosSignalConstants::ANALYTICS_JOURNAL_READ,
        );
    }

    /**
     * @return AnalyticsJournalPortionSignalData The answer the agent queued to the writer
     */
    private function portion(): AnalyticsJournalPortionSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal, 'The read was not answered');
        $this->assertSame(HilosSignalConstants::ANALYTICS_JOURNAL_PORTION, $signal->signalName->getName());
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $this->assertInstanceOf(AnalyticsJournalPortionSignalData::class, $signal->data->data);

        return $signal->data->data;
    }

    private function assertReadyNotice(): void
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertSame(HilosSignalConstants::ANALYTICS_JOURNAL_READY, $signal->signalName->getName());
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $this->assertInstanceOf(AnalyticsJournalReadySignalData::class, $signal->data->data);
        $this->assertSame(self::NODE, $signal->data->data->nodeId);
        $this->assertSame(self::NODE, AnalyticsJournalReadySignalData::fromArray($signal->data->data->toArray())->nodeId);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * @return string Every line the agent logged, whatever stream file it went to
     */
    private function agentLog(): string
    {
        $log = '';
        foreach (array_diff((array)scandir($this->logDir), ['.', '..']) as $entry) {
            $log .= (string)file_get_contents($this->logDir . '/' . $entry);
        }

        return $log;
    }

    /**
     * @param string $phase Freeze phase to mount
     */
    private function freeze(string $phase): void
    {
        Hilos::$rt = new AnalyticsJournalAgentTestRtContext();
        Hilos::$rt->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, ProtectedModeRuntime::fromRow([
            ProtectedModeRuntime::phase => $phase,
            ProtectedModeRuntime::passHashes => [],
            ProtectedModeRuntime::admittedSessionTokenHashes => [],
            ProtectedModeRuntime::circleSessionTokenHashes => [],
            ProtectedModeRuntime::circleNamedCount => 0,
        ]));
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
     * @return list<array<string, mixed>> Decoded records of this node's open and ready files
     */
    private function journalRecords(): array
    {
        $records = [];
        foreach ($this->files() as $file) {
            if (!str_ends_with($file, '.open') && !AnalyticsJournalDirectory::isReadyName($file)) {
                continue;
            }

            foreach (array_filter(explode("\n", (string)file_get_contents($this->path . '/' . $file))) as $line) {
                $records[] = (array)json_decode($line, true);
            }
        }

        return $records;
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

/**
 * Runtime context that registers no project state: the framework mount supplies the freeze row.
 */
final class AnalyticsJournalAgentTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}
