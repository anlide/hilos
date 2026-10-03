<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Cluster\NodeRole;
use Hilos\Core\Analytics\AnalyticsJournalRecord;
use Hilos\Core\Analytics\AnalyticsWriterAgent;
use Hilos\Core\Analytics\DTO\AnalyticsJournalLoadedSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalPortionSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadySignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\State\Item\HilosClusterNode;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Logger;

/**
 * The cluster's analytics writer (HIL-1154), driven by the frames it would get from a journal agent.
 *
 * The clock is handed in: every case sends the writer's tick and the portions it reads at chosen
 * moments, and reads what the writer queued in answer.
 */
final class AnalyticsWriterAgentIntegrationTest extends AnalyticsSchemaIntegrationTestCase
{
    private const int T0 = 1_800_000_000_000;

    private const string FILE = '000000000001-0123456789abcdef.jsonl';

    private const string SECOND_FILE = '000000000002-0123456789abcdef.jsonl';

    private const string WORKER_KEY = '0123456789abcdef0123456789abcdef';

    private string $logDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        // An agent writes its lines into a stream beside the process log, so the case reads the directory.
        $this->logDir = sys_get_temp_dir() . '/hilos-analytics-writer-' . bin2hex(random_bytes(6));
        mkdir($this->logDir);
        Logger::setLogFile($this->logDir . '/daemon.log');
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new AnalyticsWriterTestRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::registerDaemon(HilosClusterNode::RT_COLLECTION);
        $this->publishMembership(HilosClusterNode::STANDALONE_NODE_ID, true, 1.0);
    }

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        RtTruthSourceRegistry::unregisterDaemon(HilosClusterNode::RT_COLLECTION);
        Hilos::$rt = null;
        Logger::resetLogFile();
        foreach (array_diff((array)scandir($this->logDir), ['.', '..']) as $entry) {
            unlink($this->logDir . '/' . $entry);
        }
        rmdir($this->logDir);

        parent::tearDown();
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testAnEmptyNodeSleepsUntilAReadyNotice(): void
    {
        $writer = $this->startedWriter();

        $writer->step(self::T0);
        $this->assertRead('', 0);

        $writer->applyPortion($this->noReadyFile(), self::T0 + 10);
        $writer->step(self::T0 + AnalyticsWriterAgent::READ_TIMEOUT_MS);
        $this->assertNothingSent();

        $writer->applyReady(new AnalyticsJournalReadySignalData(null), self::T0);
        $writer->step(self::T0);
        $this->assertRead('', 0);
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testPortionsAddUpToTheFileWhichIsLoadedAndConfirmed(): void
    {
        $writer = $this->startedWriter();
        $writer->step(self::T0);
        $this->assertRead('', 0);

        $writer->applyPortion($this->portion(self::FILE, 0, 50, [
            $this->line(AnalyticsJournalRecord::journal('', self::T0)),
            $this->line(AnalyticsJournalRecord::workerSession(self::WORKER_KEY, 1, false, self::T0)),
        ], false), self::T0);
        $this->assertRead(self::FILE, 50);
        $this->assertSame([['0']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_worker_session`'));

        $writer->applyPortion($this->portion(self::FILE, 50, 90, [
            $this->line(AnalyticsJournalRecord::workerSessionStop(self::WORKER_KEY, self::T0 + 5)),
        ], true), self::T0);

        $this->assertLoaded(self::FILE);
        $this->assertSame(
            [[(string)(self::T0 + 5)]],
            $this->rows('SELECT `stopped_ts` FROM `hilos_analytics_worker_session`'),
        );

        // The next file is asked for at once, without waiting out the poll interval.
        $writer->step(self::T0 + 1);
        $this->assertRead('', 0);
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testAFileLoadedBeforeIsOnlyConfirmedAgain(): void
    {
        Database::sql(
            'INSERT INTO `hilos_analytics_journal_file` (`node_id`, `file_name`, `record_count`, `loaded_ts`) VALUES (?, ?, 1, ?)',
            ['', self::FILE, self::T0],
        );
        $writer = $this->startedWriter();
        $writer->step(self::T0);
        $this->assertRead('', 0);

        $writer->applyPortion($this->portion(self::FILE, 0, 50, [
            $this->line(AnalyticsJournalRecord::workerSession(self::WORKER_KEY, 1, false, self::T0)),
        ], true), self::T0);

        $this->assertLoaded(self::FILE);
        $this->assertSame([['0']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_worker_session`'));
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testADatabaseFailurePausesAndTheSameFileComesFirstAfterwards(): void
    {
        $writer = $this->startedWriter();
        $writer->step(self::T0);
        $this->assertRead('', 0);
        $lines = [$this->line(AnalyticsJournalRecord::workerSession(self::WORKER_KEY, 1, false, self::T0))];

        Database::sql('RENAME TABLE `hilos_analytics_journal_file` TO `hilos_analytics_journal_file_away`');
        try {
            $writer->applyPortion($this->portion(self::FILE, 0, 50, $lines, true), self::T0);
        } finally {
            Database::sql('RENAME TABLE `hilos_analytics_journal_file_away` TO `hilos_analytics_journal_file`');
        }
        $this->assertNothingSent();
        $this->assertStringContainsString('loading ' . self::FILE . ' failed', $this->agentLog());

        $writer->step(self::T0 + AnalyticsWriterAgent::RETRY_MIN_MS - 1);
        $this->assertNothingSent();

        $writer->step(self::T0 + AnalyticsWriterAgent::RETRY_MIN_MS);
        $this->assertRead(self::FILE, 0);
        $writer->applyPortion($this->portion(self::FILE, 0, 50, $lines, true), self::T0 + AnalyticsWriterAgent::RETRY_MIN_MS);

        $this->assertLoaded(self::FILE);
        $this->assertSame([['1']], $this->rows('SELECT COUNT(*) FROM `hilos_analytics_worker_session`'));
        $this->assertStringContainsString('takes files again', $this->agentLog());
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testAFileThatIsGoneLeavesTheWriterFree(): void
    {
        $writer = $this->startedWriter();
        $writer->step(self::T0);
        $this->assertRead('', 0);
        $writer->applyPortion($this->portion(self::FILE, 0, 50, [$this->line(AnalyticsJournalRecord::journal('', self::T0))], false), self::T0);
        $this->assertRead(self::FILE, 50);

        $writer->applyPortion(new AnalyticsJournalPortionSignalData(null, self::FILE, 50, 50, [], false, true), self::T0);

        $this->assertNothingSent();
        $writer->step(self::T0 + 1);
        $this->assertRead('', 0);
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testASilenceOfTenSecondsKeepsTheNodeWaitingAndDropsALateAnswer(): void
    {
        $writer = $this->startedWriter();
        $writer->step(self::T0);
        $this->assertRead('', 0);
        $writer->applyPortion($this->portion(self::FILE, 0, 50, [$this->line(AnalyticsJournalRecord::journal('', self::T0))], false), self::T0);
        $this->assertRead(self::FILE, 50);

        $writer->step(self::T0 + AnalyticsWriterAgent::READ_TIMEOUT_MS - 1);
        $this->assertNothingSent();
        $writer->step(self::T0 + AnalyticsWriterAgent::READ_TIMEOUT_MS);
        $this->assertRead('', 0);

        // An answer to some other file or offset is not the one asked for.
        $writer->applyPortion($this->portion(self::SECOND_FILE, 50, 60, [], true), self::T0);
        $writer->applyPortion($this->portion(self::FILE, 50, 60, [], false), self::T0);
        $this->assertNothingSent();
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testTwoNodesAreReadInRoundsAndMarkedUnderTheirOwnIds(): void
    {
        $this->publishMembership('', false, 2.0);
        $this->publishMembership('a', true, 1.0);
        $this->publishMembership('b', true, 1.0);
        $this->publishMembership('c', false, 1.0);
        $writer = $this->startedWriter();

        $writer->step(self::T0);
        $this->assertRead('', 0, 'a');
        $writer->applyPortion($this->portion(self::FILE, 0, 50, [
            $this->line(AnalyticsJournalRecord::journal('a', self::T0)),
        ], true, 'a'), self::T0);
        $this->assertLoaded(self::FILE, 'a');

        $writer->step(self::T0 + 1);
        $this->assertRead('', 0, 'b');
        $writer->applyPortion($this->portion(self::FILE, 0, 50, [
            $this->line(AnalyticsJournalRecord::journal('b', self::T0)),
        ], true, 'b'), self::T0 + 1);
        $this->assertLoaded(self::FILE, 'b');

        $writer->step(self::T0 + 2);
        $this->assertRead('', 0, 'a');
        $this->assertSame(
            [['a', self::FILE], ['b', self::FILE]],
            $this->rows('SELECT `node_id`, `file_name` FROM `hilos_analytics_journal_file` ORDER BY `node_id`'),
        );
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testAnOfflineNodeDropsItsReadAndRestartsFromZeroOnReturn(): void
    {
        $this->publishMembership('', false, 2.0);
        $this->publishMembership('a', true, 1.0);
        $this->publishMembership('b', true, 1.0);
        $writer = $this->startedWriter();
        $writer->step(self::T0);
        $this->assertRead('', 0, 'a');
        $writer->applyPortion($this->portion(self::FILE, 0, 50, ['first'], false, 'a'), self::T0);
        $this->assertRead(self::FILE, 50, 'a');

        $this->publishMembership('a', false, 2.0);
        $writer->step(self::T0 + 1);
        $this->assertRead('', 0, 'b');
        $writer->applyPortion($this->portion(self::FILE, 50, 100, ['late'], true, 'a'), self::T0 + 1);
        $this->assertNothingSent();

        $writer->applyPortion($this->noReadyFile('b'), self::T0 + 2);
        $this->publishMembership('a', true, 3.0);
        $writer->step(self::T0 + 3);
        $this->assertRead('', 0, 'a');
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testSilenceLetsAnotherNodeProceedAndRecoveryIsLoggedOnce(): void
    {
        $this->publishMembership('', false, 2.0);
        $this->publishMembership('a', true, 1.0);
        $this->publishMembership('b', true, 1.0);
        $writer = $this->startedWriter();
        $writer->step(self::T0);
        $this->assertRead('', 0, 'a');

        $writer->step(self::T0 + AnalyticsWriterAgent::READ_TIMEOUT_MS);
        $this->assertRead('', 0, 'b');
        $this->assertSame(1, substr_count($this->agentLog(), 'node a does not answer'));
        $writer->applyPortion($this->noReadyFile('b'), self::T0 + AnalyticsWriterAgent::READ_TIMEOUT_MS);
        $writer->step(self::T0 + AnalyticsWriterAgent::READ_TIMEOUT_MS + 1);
        $this->assertRead('', 0, 'a');
        $writer->applyPortion($this->noReadyFile('a'), self::T0 + AnalyticsWriterAgent::READ_TIMEOUT_MS + 2);
        $this->assertSame(1, substr_count($this->agentLog(), 'node a answers again'));
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testReadyWhileReadingRepeatsTheSameRequest(): void
    {
        $writer = $this->startedWriter();
        $writer->step(self::T0);
        $this->assertRead('', 0);
        $writer->applyPortion($this->portion(self::FILE, 0, 50, ['first'], false), self::T0);
        $this->assertRead(self::FILE, 50);

        $writer->applyReady(new AnalyticsJournalReadySignalData(null), self::T0 + 1);
        $this->assertRead(self::FILE, 50);
    }

    /**
     * @throws HilosException When a frame cannot be handled
     */
    public function testDatabaseFailurePausesOnlyTheFileNodesQueue(): void
    {
        $this->publishMembership('', false, 2.0);
        $this->publishMembership('a', true, 1.0);
        $this->publishMembership('b', true, 1.0);
        $writer = $this->startedWriter();
        $writer->step(self::T0);
        $this->assertRead('', 0, 'a');

        Database::sql('RENAME TABLE `hilos_analytics_journal_file` TO `hilos_analytics_journal_file_away`');
        try {
            $writer->applyPortion($this->portion(self::FILE, 0, 50, [
                $this->line(AnalyticsJournalRecord::journal('a', self::T0)),
            ], true, 'a'), self::T0);
        } finally {
            Database::sql('RENAME TABLE `hilos_analytics_journal_file_away` TO `hilos_analytics_journal_file`');
        }

        $writer->step(self::T0 + 1);
        $this->assertRead('', 0, 'b');
        $writer->applyPortion($this->noReadyFile('b'), self::T0 + 1);
        $writer->step(self::T0 + AnalyticsWriterAgent::RETRY_MIN_MS - 1);
        $this->assertNothingSent();
        $writer->step(self::T0 + AnalyticsWriterAgent::RETRY_MIN_MS);
        $this->assertRead(self::FILE, 0, 'a');
    }

    /**
     * @return AnalyticsWriterAgent A writer started off a cluster
     */
    private function startedWriter(): AnalyticsWriterAgent
    {
        $writer = new AnalyticsWriterAgent();
        $writer->onStart();

        return $writer;
    }

    /**
     * @param array<string, mixed> $record Record
     * @return string Its line
     */
    private function line(array $record): string
    {
        return (string)AnalyticsJournalRecord::encode($record);
    }

    /**
     * @param string $file Ready file
     * @param int $offset Offset of the portion
     * @param int $nextOffset Offset after it
     * @param list<string> $lines Lines of the portion
     * @param bool $complete Whether the file ends here
     * @return AnalyticsJournalPortionSignalData Portion as the journal agent would send it
     */
    private function portion(
        string $file,
        int $offset,
        int $nextOffset,
        array $lines,
        bool $complete,
        ?string $node = null,
    ): AnalyticsJournalPortionSignalData
    {
        return new AnalyticsJournalPortionSignalData($node, $file, $offset, $nextOffset, $lines, $complete, false);
    }

    /**
     * @return AnalyticsJournalPortionSignalData The answer of a journal with no ready file
     */
    private function noReadyFile(?string $node = null): AnalyticsJournalPortionSignalData
    {
        return new AnalyticsJournalPortionSignalData($node, '', 0, 0, [], false, false);
    }

    /**
     * @param string $file File the read must name
     * @param int $offset Offset it must name
     */
    private function assertRead(string $file, int $offset, ?string $node = null): void
    {
        $data = $this->nextFrame(HilosSignalConstants::ANALYTICS_JOURNAL_READ);
        $this->assertInstanceOf(AnalyticsJournalReadSignalData::class, $data);
        $this->assertSame($node, $data->nodeId);
        $this->assertSame([$file, $offset], [$data->file, $data->offset]);
        $this->assertNothingSent();
    }

    /**
     * @param string $file File the confirmation must name
     */
    private function assertLoaded(string $file, ?string $node = null): void
    {
        $data = $this->nextFrame(HilosSignalConstants::ANALYTICS_JOURNAL_LOADED);
        $this->assertInstanceOf(AnalyticsJournalLoadedSignalData::class, $data);
        $this->assertSame($node, $data->nodeId);
        $this->assertSame($file, $data->file);
        $this->assertNothingSent();
    }

    /**
     * @param string $name Name the next queued frame must carry
     * @return mixed Its inner payload
     */
    private function nextFrame(string $name): mixed
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal, "Nothing was sent where {$name} was due");
        $this->assertSame($name, $signal->signalName->getName());
        $this->assertInstanceOf(AgentSignalData::class, $signal->data);

        return $signal->data->data;
    }

    /**
     * @return string Every line the writer logged, whatever stream file it went to
     */
    private function agentLog(): string
    {
        $log = '';
        foreach (array_diff((array)scandir($this->logDir), ['.', '..']) as $entry) {
            $log .= (string)file_get_contents($this->logDir . '/' . $entry);
        }

        return $log;
    }

    private function assertNothingSent(): void
    {
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * @param string $sql Query
     * @return list<list<?string>> Every row as a list of its values
     * @throws DatabaseException When the query fails
     */
    private function rows(string $sql): array
    {
        Database::sql($sql);

        $rows = [];
        foreach (Database::rows() as $row) {
            $rows[] = array_map(static fn(mixed $value): ?string => $value === null ? null : (string)$value, array_values($row));
        }

        return $rows;
    }

    /**
     * Publishes one row as the master would, with a controlled change stamp.
     *
     * @param string $node Node id
     * @param bool $online Whether the node is reachable
     * @param float $seen Last meaningful membership change
     */
    private function publishMembership(string $node, bool $online, float $seen): void
    {
        Hilos::$rt?->hilosClusterNodes->actions->publish($node, NodeRole::Master->value, [], null, $online, $seen);
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertContains($signal->signalName->getName(), [SignalConstants::RT_SYNC_CREATED, SignalConstants::RT_SYNC_UPDATED]);
        }
    }
}

/** Framework runtime mounts, including the cluster register, for the writer's tests. */
final class AnalyticsWriterTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}
