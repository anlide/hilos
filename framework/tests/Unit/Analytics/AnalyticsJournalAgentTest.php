<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Analytics;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsJournalDirectory;
use Hilos\Core\Analytics\AnalyticsJournalRecord;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalLoadedSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalPortionSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadySignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
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
        $this->assertSame(['{"t":"a"}'], array_slice($portion->lines, 1));
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

        $this->assertSame(['{"t":"small"}'], array_slice($lines, 1));
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
     * @throws HilosException When a frame cannot be handled
     */
    public function testAgeRotationAnnouncesAReadyFile(): void
    {
        $journal = new AnalyticsJournalDirectory($this->path, self::NODE);
        $agent = new AnalyticsJournalAgent();
        $agent->openJournal($journal, self::NODE);
        $journal->append(['{"t":"a"}'], 1);

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
        $journal->append(['{"t":"a"}'], 1);
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
        $agent->openJournal(new AnalyticsJournalDirectory($this->path, self::NODE), self::NODE);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        unlink($this->root . '/test');
        $journal = new AnalyticsJournalDirectory($this->path, self::NODE);
        $journal->start();
        $journal->append(['{"t":"a"}'], 1);
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
        $this->append($agent, ['{"t":"b"}']);

        $this->freeze(ProtectedModeRuntime::PHASE_ACTIVATING);
        $agent->onStop();

        $this->assertSame([], $this->files());
        $this->assertStringContainsString('2 file(s) of this node thrown away', $this->agentLog());
    }

    /**
     * @return AnalyticsJournalAgent An agent over this case's journal, started
     */
    private function startedAgent(): AnalyticsJournalAgent
    {
        $agent = new AnalyticsJournalAgent();
        $agent->openJournal(new AnalyticsJournalDirectory($this->path, self::NODE), self::NODE);

        return $agent;
    }

    /**
     * @param AnalyticsJournalAgent $agent Agent to hand the batch to
     * @param list<string> $lines Lines of the batch
     * @throws HilosException When the frame cannot be handled
     */
    private function append(AnalyticsJournalAgent $agent, array $lines): void
    {
        $agent->onSignalAgent(
            new AgentSignalData(data: new AnalyticsJournalAppendSignalData($lines)),
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
