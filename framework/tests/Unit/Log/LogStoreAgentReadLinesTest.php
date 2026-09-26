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

    /** @var int Bytes of unstamped lines put between an entry and the end of its file, past the 8 MiB an anchored read searches back */
    private const int PAST_THE_ANCHOR_SEARCH_BYTES = 9 * 1024 * 1024;

    /** @var int Lines in a file one line longer than the page the owner reads */
    private const int ONE_PAST_A_PAGE_LINES = 205;

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
        putenv(EnvConstants::DAEMON_ERROR_LOG_FILE->name);
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
     * A row of the recent-failures panel opens the file on the entry it names: that line comes
     * first and what was written after it follows, and the page's cursor is the ordinary one, so
     * the Earlier button reads what came before the entry (HIL-868).
     */
    public function testAnAnchoredReadOpensThePageOnTheEntryAndReadsOnFromIt(): void
    {
        $lines = [
            '[2026-08-01 00:00:00.000] booted',
            '[2026-08-01 00:00:01.000] served',
            '[2026-08-01 00:00:02.500] ERROR: the entry the row names',
            '#0 /app/a.php(7): a()',
            '[2026-08-01 00:00:03.000] served again',
        ];
        $this->write('worker-0.log', implode("\n", $lines) . "\n");

        $this->read($this->request(
            LogsReadLinesActionDTO::SOURCE_LIVE,
            null,
            'worker-0.log',
            anchorAtMs: $this->milliseconds('2026-08-01 00:00:02', 500),
        ));

        $reply = $this->acked();
        $this->assertTrue($reply[LogsReadLinesReplyDTO::anchorFound]);
        $this->assertSame(
            array_slice($lines, 2),
            array_column($reply[LogsReadLinesReplyDTO::lines], LogsReadLinesReplyDTO::text),
        );
        $this->assertSame(strlen($lines[0]) + strlen($lines[1]) + 2, $reply[LogsReadLinesReplyDTO::nextCursor]);
        $this->assertTrue($reply[LogsReadLinesReplyDTO::hasMore]);

        $this->read($this->request(
            LogsReadLinesActionDTO::SOURCE_LIVE,
            null,
            'worker-0.log',
            cursor: $reply[LogsReadLinesReplyDTO::nextCursor],
        ));

        $earlier = $this->acked();
        $this->assertSame(
            array_slice($lines, 0, 2),
            array_column($earlier[LogsReadLinesReplyDTO::lines], LogsReadLinesReplyDTO::text),
        );
        $this->assertNull($earlier[LogsReadLinesReplyDTO::anchorFound]);
    }

    public function testAnAnchoredReadOfTheDaemonErrorStreamTagsTheEntryError(): void
    {
        putenv(EnvConstants::DAEMON_ERROR_LOG_FILE->name . '=' . $this->dir . '/daemon-error.log');
        $this->write('daemon-error.log', "[2026-08-01 00:00:02.500] failure\n");

        $this->read($this->request(
            LogsReadLinesActionDTO::SOURCE_LIVE,
            null,
            'daemon-error.log',
            anchorAtMs: $this->milliseconds('2026-08-01 00:00:02', 500),
        ));

        $reply = $this->acked();
        $this->assertTrue($reply[LogsReadLinesReplyDTO::anchorFound]);
        $this->assertSame(
            Logger::LEVEL_ERROR,
            $reply[LogsReadLinesReplyDTO::lines][0][LogsReadLinesReplyDTO::level],
        );
    }

    /**
     * A rotation started this file after the entry had left: every line is later than the moment,
     * and the answer is the tail with the flag down rather than the first line passed off as the place.
     */
    public function testAnAnchorTheFileDoesNotHoldAnswersTheTailAndSaysSo(): void
    {
        $lines = ['[2026-08-01 00:00:05.000] first after the rotation', '[2026-08-01 00:00:06.000] next'];
        $this->write('worker-0.log', implode("\n", $lines) . "\n");

        $this->read($this->request(
            LogsReadLinesActionDTO::SOURCE_LIVE,
            null,
            'worker-0.log',
            anchorAtMs: $this->milliseconds('2026-08-01 00:00:02', 500),
        ));

        $reply = $this->acked();
        $this->assertFalse($reply[LogsReadLinesReplyDTO::anchorFound]);
        $this->assertTrue($reply[LogsReadLinesReplyDTO::readable]);
        $this->assertSame($lines, array_column($reply[LogsReadLinesReplyDTO::lines], LogsReadLinesReplyDTO::text));
    }

    /**
     * The search is one read on a click and has a ceiling: an entry further back than that is
     * answered like one that is not there.
     */
    public function testAnAnchorBeyondTheSearchAnswersTheTailToo(): void
    {
        $last = '[2026-08-01 00:00:09.000] the last line';
        // Written a block at a time: the file is bigger than what a test may hold in memory at once.
        $handle = fopen($this->dir . DIRECTORY_SEPARATOR . 'worker-0.log', 'wb');
        $this->assertNotFalse($handle);
        fwrite($handle, "[2026-08-01 00:00:02.500] ERROR: the entry the row names\n");
        $block = str_repeat("#0 /app/src/Service/Handler.php(120): Handler->handle()\n", 1000);
        for ($written = 0; $written < self::PAST_THE_ANCHOR_SEARCH_BYTES; $written += strlen($block)) {
            fwrite($handle, $block);
        }
        fwrite($handle, $last . "\n");
        fclose($handle);

        $this->read($this->request(
            LogsReadLinesActionDTO::SOURCE_LIVE,
            null,
            'worker-0.log',
            anchorAtMs: $this->milliseconds('2026-08-01 00:00:02', 500),
        ));

        $reply = $this->acked();
        $this->assertFalse($reply[LogsReadLinesReplyDTO::anchorFound]);
        $texts = array_column($reply[LogsReadLinesReplyDTO::lines], LogsReadLinesReplyDTO::text);
        $this->assertSame($last, $texts[count($texts) - 1]);
    }

    /**
     * Without an anchor nothing about the read moved: backwards from the tail, the cursor of the
     * page before it, and no word about an anchor at all — the Earlier button depends on exactly this.
     */
    public function testAReadWithoutAnAnchorIsTheOrdinaryTailRead(): void
    {
        $lines = [];
        for ($index = 0; $index < self::ONE_PAST_A_PAGE_LINES; $index++) {
            $lines[] = "[2026-08-01 00:00:00.000] line {$index}";
        }
        $this->write('worker-0.log', implode("\n", $lines) . "\n");
        $firstShown = self::ONE_PAST_A_PAGE_LINES - 200;

        $this->read($this->request(LogsReadLinesActionDTO::SOURCE_LIVE, null, 'worker-0.log'));

        $reply = $this->acked();
        $this->assertSame(
            array_slice($lines, $firstShown),
            array_column($reply[LogsReadLinesReplyDTO::lines], LogsReadLinesReplyDTO::text),
        );
        $this->assertTrue($reply[LogsReadLinesReplyDTO::hasMore]);
        $this->assertSame(strlen(implode("\n", array_slice($lines, 0, $firstShown))) + 1, $reply[LogsReadLinesReplyDTO::nextCursor]);
        $this->assertNull($reply[LogsReadLinesReplyDTO::anchorFound]);
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
     * @param ?int $cursor Byte offset to continue from, or null for the first page
     * @param ?int $anchorAtMs Unix milliseconds of the entry to open the file on, or null for a read from the tail
     * @return LogsReadLinesSignalData Frame the owner receives
     */
    private function request(
        string $source,
        ?int $batchTimestamp,
        string $stream,
        ?string $level = null,
        ?string $requestId = self::REQUEST_ID,
        ?int $cursor = null,
        ?int $anchorAtMs = null,
    ): LogsReadLinesSignalData {
        return new LogsReadLinesSignalData(
            nodeId: '',
            source: $source,
            batchTimestamp: $batchTimestamp,
            stream: $stream,
            level: $level,
            substring: null,
            cursor: $cursor,
            anchorAtMs: $anchorAtMs,
            acceptKey: self::ACCEPT_KEY,
            action: HilosSignalConstants::LOGS_READ_LINES,
            requestId: $requestId,
        );
    }

    /**
     * Hands one read to a fresh owner, the way the viewer page's frame reaches it.
     *
     * @param LogsReadLinesSignalData $request Frame the owner receives
     */
    private function read(LogsReadLinesSignalData $request): void
    {
        new LogStoreAgent()->onSignalAgent(
            new AgentSignalData($request),
            'agent',
            HilosSignalConstants::LOGS_AGENT_READ_LINES,
        );
    }

    /**
     * The unix milliseconds a line stamped with this local time is read as.
     *
     * @param string $stamp Local time without milliseconds
     * @param int $milliseconds Millisecond part of the stamp
     * @return int Unix milliseconds
     */
    private function milliseconds(string $stamp, int $milliseconds): int
    {
        return strtotime($stamp) * 1000 + $milliseconds;
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
