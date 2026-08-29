<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use DateTimeImmutable;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\LogRotationConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Log\DTO\LogsReadLinesSignalData;
use Hilos\Log\LogStoreAgent;
use Hilos\Pages\Logs\DTO\LogsReadLinesActionDTO;
use Hilos\Pages\Logs\DTO\LogsReadLinesReplyDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Tests the owner's half of the log-reading channel (HIL-757).
 *
 * The agent is the LAST step of somebody else's action - the viewer page deferred its ack when it
 * handed the read over - so what is judged here is that every ending produces one: a page of lines
 * for a file that is there, a readable-false page for one that is not, and a failure ack rather
 * than silence when the read itself blows up. Silence would leave a browser on another machine
 * waiting out its own timeout with the reason recorded where nobody looking can see it.
 *
 * The store underneath is a throwaway temp directory, the fixture shape borrowed from
 * {@see LogStoreAgentIndexTest}.
 */
final class LogStoreAgentReadLinesTest extends TestCase
{
    /** @var string Accept key of the connection waiting for the page of lines */
    private const string ACCEPT_KEY = 'ak-logs-view-1';

    /** @var string Request id of the tracked dispatch being answered */
    private const string REQUEST_ID = 'req-1';

    /** @var string Name of the rotated batch the archive fixture is written into */
    private const string BATCH_DIR = '2026-08-01-00-00-00';

    private string $dir = '';

    private string $logFile = '';

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-logstore-read-' . uniqid('', true);
        if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            $this->fail("Could not create fixture directory: {$this->dir}");
        }
        // Outside the fixture on purpose: the agent logs into the very directory it reads.
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-logstore-read-journal');
        Logger::setLogFile($this->logFile);

        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->dir . '/daemon.log');
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        putenv(EnvConstants::DAEMON_LOG_FILE->name);
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        Hilos::$sr = null;
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    public function testALiveFileComesBackAsAPageOfLinesOnTheAskersAck(): void
    {
        $this->write('worker-0.log', "[2026-08-01 00:00:00.000] first\n[2026-08-01 00:00:01.000] ERROR: second\n");

        new LogStoreAgent()->onSignalAgent(
            new AgentSignalData($this->request(LogsReadLinesActionDTO::SOURCE_LIVE, null, 'worker-0.log')),
            'agent',
            HilosSignalConstants::LOGS_AGENT_READ_LINES,
        );

        $reply = $this->acked();
        $this->assertTrue($reply[LogsReadLinesReplyDTO::readable]);
        $this->assertSame(
            ['[2026-08-01 00:00:00.000] first', '[2026-08-01 00:00:01.000] ERROR: second'],
            array_column($reply[LogsReadLinesReplyDTO::lines], LogsReadLinesReplyDTO::text),
        );
    }

    /**
     * The one place the wire's unix stamp meets the directory name rotation writes.
     */
    public function testABatchIsReadFromTheDirectoryItsTimestampNames(): void
    {
        $this->writeBatch('worker-0.log', "[2026-08-01 00:00:00.000] archived\n");

        new LogStoreAgent()->onSignalAgent(
            new AgentSignalData($this->request(
                LogsReadLinesActionDTO::SOURCE_BATCH,
                $this->batchTimestamp(),
                'worker-0.log',
            )),
            'agent',
            HilosSignalConstants::LOGS_AGENT_READ_LINES,
        );

        $reply = $this->acked();
        $this->assertTrue($reply[LogsReadLinesReplyDTO::readable]);
        $this->assertSame(
            ['[2026-08-01 00:00:00.000] archived'],
            array_column($reply[LogsReadLinesReplyDTO::lines], LogsReadLinesReplyDTO::text),
        );
    }

    /**
     * A stack trace has no level of its own and belongs to the error above it. Filtering for
     * ERROR must therefore carry it along, or the operator gets the sentence without the trace.
     */
    public function testAFilteredReadCarriesTheContinuationLinesOfTheEntriesItKeeps(): void
    {
        $this->write(
            'worker-0.log',
            "[2026-08-01 00:00:00.000] quiet\n[2026-08-01 00:00:01.000] ERROR: boom\n    #0 somewhere.php\n",
        );

        new LogStoreAgent()->onSignalAgent(
            new AgentSignalData($this->request(
                LogsReadLinesActionDTO::SOURCE_LIVE,
                null,
                'worker-0.log',
                Logger::LEVEL_ERROR,
            )),
            'agent',
            HilosSignalConstants::LOGS_AGENT_READ_LINES,
        );

        $reply = $this->acked();
        $this->assertSame(
            ['[2026-08-01 00:00:01.000] ERROR: boom', '    #0 somewhere.php'],
            array_column($reply[LogsReadLinesReplyDTO::lines], LogsReadLinesReplyDTO::text),
        );
        $this->assertSame(
            [false, true],
            array_column($reply[LogsReadLinesReplyDTO::lines], LogsReadLinesReplyDTO::isContinuation),
        );
    }

    public function testAFileThatIsNotThereIsAnAnswerAndNotAFailure(): void
    {
        new LogStoreAgent()->onSignalAgent(
            new AgentSignalData($this->request(LogsReadLinesActionDTO::SOURCE_LIVE, null, 'never-existed.log')),
            'agent',
            HilosSignalConstants::LOGS_AGENT_READ_LINES,
        );

        $reply = $this->acked();
        $this->assertFalse($reply[LogsReadLinesReplyDTO::readable]);
        $this->assertSame([], $reply[LogsReadLinesReplyDTO::lines]);
    }

    public function testAPathClimbingOutOfTheLogRootIsRefusedAsUnreadable(): void
    {
        new LogStoreAgent()->onSignalAgent(
            new AgentSignalData($this->request(
                LogsReadLinesActionDTO::SOURCE_LIVE,
                null,
                '../' . basename($this->logFile),
            )),
            'agent',
            HilosSignalConstants::LOGS_AGENT_READ_LINES,
        );

        $this->assertFalse($this->acked()[LogsReadLinesReplyDTO::readable]);
    }

    /**
     * A batch naming no batch cannot be turned into a path at all. It still gets an ack, because
     * the browser is waiting on one either way.
     */
    public function testABatchReadCarryingNoTimestampIsAnsweredUnreadable(): void
    {
        new LogStoreAgent()->onSignalAgent(
            new AgentSignalData($this->request(LogsReadLinesActionDTO::SOURCE_BATCH, null, 'worker-0.log')),
            'agent',
            HilosSignalConstants::LOGS_AGENT_READ_LINES,
        );

        $this->assertFalse($this->acked()[LogsReadLinesReplyDTO::readable]);
    }

    public function testAnUntrackedReadIsNotReadAndNotAnswered(): void
    {
        $this->write('worker-0.log', "[2026-08-01 00:00:00.000] first\n");

        new LogStoreAgent()->onSignalAgent(
            new AgentSignalData($this->request(
                LogsReadLinesActionDTO::SOURCE_LIVE,
                null,
                'worker-0.log',
                null,
                null,
            )),
            'agent',
            HilosSignalConstants::LOGS_AGENT_READ_LINES,
        );

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'There is no ack to correlate, so there is no ack');
    }

    /**
     * Builds one read frame as the viewer page hands it over.
     *
     * @param string $source Which half of the store to read
     * @param ?int $batchTimestamp Unix timestamp of the batch, or null
     * @param string $stream File name of the stream inside the source
     * @param ?string $level Level filter, or null for any level
     * @param ?string $requestId Request id to answer on, or null for an untracked read
     * @return LogsReadLinesSignalData Frame the owner receives
     */
    private function request(
        string $source,
        ?int $batchTimestamp,
        string $stream,
        ?string $level = null,
        ?string $requestId = self::REQUEST_ID,
    ): LogsReadLinesSignalData {
        return new LogsReadLinesSignalData(
            nodeId: '',
            source: $source,
            batchTimestamp: $batchTimestamp,
            stream: $stream,
            level: $level,
            substring: null,
            cursor: null,
            acceptKey: self::ACCEPT_KEY,
            action: HilosSignalConstants::LOGS_READ_LINES,
            requestId: $requestId,
        );
    }

    /**
     * Reads back the success ack the owner sent, and nothing but it.
     *
     * @return array<string, mixed> Reply the ack carries
     */
    private function acked(): array
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal, 'The owner is the last step of the action and owes an ack');
        $this->assertSame(SignalConstants::ACTION_SUCCESS, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $signal->data->data);
        $this->assertSame(HilosSignalConstants::LOGS_READ_LINES, $signal->data->data->action);
        $this->assertSame(self::REQUEST_ID, $signal->data->data->requestId);
        $this->assertNotNull($signal->data->data->reply);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'One read is one ack');

        return $signal->data->data->reply;
    }

    /**
     * @return int Unix timestamp of the fixture batch, as the wire carries it
     */
    private function batchTimestamp(): int
    {
        $parsed = DateTimeImmutable::createFromFormat(LogRotationConstants::TIMESTAMP_FORMAT, self::BATCH_DIR);
        $this->assertNotFalse($parsed);

        return $parsed->getTimestamp();
    }

    /**
     * Writes one live log file at the log root.
     *
     * @param string $name Basename to write
     * @param string $contents File contents
     */
    private function write(string $name, string $contents): void
    {
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . $name, $contents);
    }

    /**
     * Writes one file into the fixture's rotated batch.
     *
     * @param string $name Basename to write
     * @param string $contents File contents
     */
    private function writeBatch(string $name, string $contents): void
    {
        $batch = $this->dir . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME
            . DIRECTORY_SEPARATOR . self::BATCH_DIR;
        if (!mkdir($batch, 0755, true) && !is_dir($batch)) {
            $this->fail("Could not create fixture batch: {$batch}");
        }
        file_put_contents($batch . DIRECTORY_SEPARATOR . $name, $contents);
    }

    /**
     * Removes the fixture directory and everything under it.
     *
     * @param string $path Directory to remove
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }

        rmdir($path);
    }
}
