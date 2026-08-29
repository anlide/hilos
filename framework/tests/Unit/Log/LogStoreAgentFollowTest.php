<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Log\DTO\LogsFollowStartSignalData;
use Hilos\Log\DTO\LogsFollowStopSignalData;
use Hilos\Log\DTO\LogsLinesAppendedSignalData;
use Hilos\Log\LogStoreAgent;
use Hilos\Pages\Logs\DTO\LogsReadLinesReplyDTO;
use Hilos\Runtime\State\Collection\HilosConnections as StateHilosConnections;
use Hilos\Runtime\State\Item\HilosConnection as StateHilosConnection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Tests the owner's half of following a live log file (HIL-389).
 *
 * The agent is the only process that may touch the file, so everything a follow means happens
 * here: it takes the end of the file as the position to continue from, and once a round it says
 * the ONE thing that happened since - lines arrived, the file was replaced, the viewer fell too
 * far behind, or the follow ended. A round with nothing to say sends nothing, which is why the
 * cases below assert absence as often as presence: a frame per second per viewer would be traffic
 * and blocking file I/O spent on saying that nothing occurred.
 *
 * The round is driven directly rather than through {@see LogStoreAgent::onTick()}, the way the
 * walks are: the tick is a clock, and a test of what a round reads should not have to wait one.
 */
final class LogStoreAgentFollowTest extends TestCase
{
    /** @var string Accept key of the viewer the frames are addressed to */
    private const string ACCEPT_KEY = 'ak-logs-follow-1';

    /** @var string Accept key of a second viewer of the same file */
    private const string OTHER_ACCEPT_KEY = 'ak-logs-follow-2';

    /** @var string Request id of the start, which is also the id of the follow */
    private const string REQUEST_ID = 'req-follow-1';

    /** @var string Request id of the second viewer's start */
    private const string OTHER_REQUEST_ID = 'req-follow-2';

    /** @var string Live file every case follows */
    private const string STREAM = 'worker-0.log';

    private string $dir = '';

    private string $logFile = '';

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-logstore-follow-' . uniqid('', true);
        if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            $this->fail("Could not create fixture directory: {$this->dir}");
        }
        // Outside the fixture on purpose: the agent logs into the very directory it reads.
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-logstore-follow-journal');
        Logger::setLogFile($this->logFile);

        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->dir . '/daemon.log');
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new FollowRtContext();
        Hilos::$rt->configure();
        $this->connect(self::ACCEPT_KEY);
    }

    protected function tearDown(): void
    {
        putenv(EnvConstants::DAEMON_LOG_FILE->name);
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        Hilos::$rt = null;
        Hilos::$sr = null;
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    public function testTheStartAnswersWithTheEndOfTheFileAndFollowsOnFromExactlyThere(): void
    {
        $this->write("[2026-08-01 00:00:00.000] before the follow\n");
        $agent = $this->following();

        $reply = $this->acked(self::REQUEST_ID);
        $this->assertTrue($reply[LogsReadLinesReplyDTO::readable]);
        $this->assertSame(
            ['[2026-08-01 00:00:00.000] before the follow'],
            array_column($reply[LogsReadLinesReplyDTO::lines], LogsReadLinesReplyDTO::text),
        );

        $this->append("[2026-08-01 00:00:01.000] after the follow\n");
        $agent->pushAppendedLines();

        $frame = $this->frame(self::ACCEPT_KEY);
        $this->assertSame(self::REQUEST_ID, $frame->followId, 'A frame is stamped with the id of its follow');
        $this->assertSame(
            ['[2026-08-01 00:00:01.000] after the follow'],
            array_column($frame->lines, LogsReadLinesReplyDTO::text),
        );
        $this->assertFalse($frame->rotated);
        $this->assertNull($frame->skippedBytes);
        $this->assertFalse($frame->stopped);
    }

    public function testAFileThatGainedNothingProducesNoFrameAtAll(): void
    {
        $this->write("[2026-08-01 00:00:00.000] quiet\n");
        $agent = $this->following();
        $this->acked(self::REQUEST_ID);

        $agent->pushAppendedLines();

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'Silence is the answer when nothing was written');
    }

    /**
     * Rotation renames the live file away whole and the writer opens a new one under the same
     * name. The follow goes on by NAME, so the viewer keeps its tail across the outage it is
     * probably watching - it just has to be told the ground moved.
     */
    public function testAFileCarriedOffByRotationRestartsTheFollowAtTheStartOfTheNewOne(): void
    {
        $this->write("[2026-08-01 00:00:00.000] before rotation\n");
        $agent = $this->following();
        $this->acked(self::REQUEST_ID);

        rename($this->path(), $this->dir . DIRECTORY_SEPARATOR . 'worker-0.log.rotated');
        $this->write("[2026-08-01 00:00:02.000] after rotation\n");
        $agent->pushAppendedLines();

        $rotated = $this->frame(self::ACCEPT_KEY);
        $this->assertTrue($rotated->rotated);
        $this->assertSame([], $rotated->lines, 'The rotation is its own frame; the new lines follow');

        $agent->pushAppendedLines();
        $this->assertSame(
            ['[2026-08-01 00:00:02.000] after rotation'],
            array_column($this->frame(self::ACCEPT_KEY)->lines, LogsReadLinesReplyDTO::text),
        );
    }

    public function testATruncatedFileIsReadTheSameWayAsARotatedOne(): void
    {
        $this->write("[2026-08-01 00:00:00.000] a long first line that will be cut away\n");
        $agent = $this->following();
        $this->acked(self::REQUEST_ID);

        $this->write("[2026-08-01 00:00:01.000] short\n");
        $agent->pushAppendedLines();

        $this->assertTrue($this->frame(self::ACCEPT_KEY)->rotated);
    }

    /**
     * A file that never appears is not a rotation happening once a second: nothing has moved, so
     * nothing is said.
     */
    public function testAFollowOfAFileThatIsNotThereSaysNothingUntilItAppears(): void
    {
        $agent = $this->following();
        $reply = $this->acked(self::REQUEST_ID);
        $this->assertFalse($reply[LogsReadLinesReplyDTO::readable], 'A missing file is an answer, not a refusal');

        $agent->pushAppendedLines();
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        $this->write("[2026-08-01 00:00:00.000] the worker finally started\n");
        $agent->pushAppendedLines();

        $this->assertSame(
            ['[2026-08-01 00:00:00.000] the worker finally started'],
            array_column($this->frame(self::ACCEPT_KEY)->lines, LogsReadLinesReplyDTO::text),
        );
    }

    /**
     * A tail showing the day before yesterday is lying about the word "now", and a queue of
     * unshown lines grows faster than a reader drains it.
     */
    public function testAViewerTooFarBehindIsJumpedToTheEndAndToldHowMuchItMissed(): void
    {
        $this->write("[2026-08-01 00:00:00.000] start\n");
        $agent = $this->following();
        $this->acked(self::REQUEST_ID);

        $flood = str_repeat("[2026-08-01 00:00:01.000] flooding the log\n", 30000);
        $this->append($flood);
        $agent->pushAppendedLines();

        $frame = $this->frame(self::ACCEPT_KEY);
        $this->assertSame(strlen($flood), $frame->skippedBytes);
        $this->assertSame([], $frame->lines, 'Skipping is the frame; the backlog is not shipped');

        $agent->pushAppendedLines();
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'The jump lands at the end, so nothing remains');
    }

    /**
     * Filtered-out lines are not news. That they still move the reading position is the reader's
     * property and is pinned in {@see LogLineReaderAppendedTest}; what matters here is that the
     * viewer is not woken for them, and that a line it did ask for still arrives exactly once.
     */
    public function testLinesTheFilterRejectsWakeNobodyAndDoNotHideTheNextMatchingOne(): void
    {
        $this->write("[2026-08-01 00:00:00.000] start\n");
        $agent = $this->following(level: Logger::LEVEL_ERROR);
        $this->acked(self::REQUEST_ID);

        $this->append("[2026-08-01 00:00:01.000] nothing to see\n[2026-08-01 00:00:02.000] still nothing\n");
        $agent->pushAppendedLines();
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        $this->append("[2026-08-01 00:00:03.000] ERROR: there it is\n");
        $agent->pushAppendedLines();

        $this->assertSame(
            ['[2026-08-01 00:00:03.000] ERROR: there it is'],
            array_column($this->frame(self::ACCEPT_KEY)->lines, LogsReadLinesReplyDTO::text),
        );
    }

    /**
     * The only thing that catches a viewer who left without a word. Asked BEFORE the file is
     * touched, because reading for somebody who has gone is exactly the work this leaf promised
     * not to do.
     */
    public function testAViewerGoneFromTheConnectionRosterIsDroppedWithoutReadingItsFile(): void
    {
        $this->write("[2026-08-01 00:00:00.000] start\n");
        $agent = $this->following();
        $this->acked(self::REQUEST_ID);

        $this->disconnect(self::ACCEPT_KEY);
        $this->append("[2026-08-01 00:00:01.000] nobody is listening\n");
        $agent->pushAppendedLines();

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'A departed viewer is sent nothing');

        // Reconnecting proves the follow was dropped rather than merely skipped for one round.
        $this->connect(self::ACCEPT_KEY);
        $agent->pushAppendedLines();
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testTheViewerAskingToStopIsDroppedAndReadNoFurther(): void
    {
        $this->write("[2026-08-01 00:00:00.000] start\n");
        $agent = $this->following();
        $this->acked(self::REQUEST_ID);

        $agent->onSignalAgent(
            new AgentSignalData(new LogsFollowStopSignalData('', self::ACCEPT_KEY)),
            'agent',
            HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP,
        );
        $this->append("[2026-08-01 00:00:01.000] after the stop\n");
        $agent->pushAppendedLines();

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * One pass per viewer, not one pass per file: two people looking at the same log through
     * different filters are two different questions about it.
     */
    public function testTwoViewersOfOneFileAreAnsweredEachThroughItsOwnFilter(): void
    {
        $this->connect(self::OTHER_ACCEPT_KEY);
        $this->write("[2026-08-01 00:00:00.000] start\n");
        $agent = $this->following(level: Logger::LEVEL_ERROR);
        $this->acked(self::REQUEST_ID);
        $agent->onSignalAgent(
            new AgentSignalData(new LogsFollowStartSignalData(
                nodeId: '',
                stream: self::STREAM,
                level: null,
                substring: null,
                acceptKey: self::OTHER_ACCEPT_KEY,
                action: HilosSignalConstants::LOGS_FOLLOW_START,
                requestId: self::OTHER_REQUEST_ID,
            )),
            'agent',
            HilosSignalConstants::LOGS_AGENT_FOLLOW_START,
        );
        $this->acked(self::OTHER_REQUEST_ID, self::OTHER_ACCEPT_KEY);

        $this->append("[2026-08-01 00:00:01.000] routine\n[2026-08-01 00:00:02.000] ERROR: not routine\n");
        $agent->pushAppendedLines();

        $filtered = $this->frame(self::ACCEPT_KEY);
        $this->assertSame(self::REQUEST_ID, $filtered->followId);
        $this->assertSame(
            ['[2026-08-01 00:00:02.000] ERROR: not routine'],
            array_column($filtered->lines, LogsReadLinesReplyDTO::text),
        );

        $unfiltered = $this->frame(self::OTHER_ACCEPT_KEY);
        $this->assertSame(self::OTHER_REQUEST_ID, $unfiltered->followId);
        $this->assertSame(
            ['[2026-08-01 00:00:01.000] routine', '[2026-08-01 00:00:02.000] ERROR: not routine'],
            array_column($unfiltered->lines, LogsReadLinesReplyDTO::text),
        );
    }

    /**
     * Starts a follow of the fixture file as the viewer page hands it over.
     *
     * @param ?string $level Level filter, or null for any level
     * @return LogStoreAgent Agent already following, its ack still queued
     */
    private function following(?string $level = null): LogStoreAgent
    {
        $agent = new LogStoreAgent();
        $agent->onSignalAgent(
            new AgentSignalData(new LogsFollowStartSignalData(
                nodeId: '',
                stream: self::STREAM,
                level: $level,
                substring: null,
                acceptKey: self::ACCEPT_KEY,
                action: HilosSignalConstants::LOGS_FOLLOW_START,
                requestId: self::REQUEST_ID,
            )),
            'agent',
            HilosSignalConstants::LOGS_AGENT_FOLLOW_START,
        );

        return $agent;
    }

    /**
     * Reads back the success ack the owner owes the browser for its start.
     *
     * @param string $requestId Request id the ack must quote
     * @param string $acceptKey Connection the ack must be addressed to
     * @return array<string, mixed> Reply the ack carries
     */
    private function acked(string $requestId, string $acceptKey = self::ACCEPT_KEY): array
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal, 'The owner is the last step of the start and owes an ack');
        $this->assertSame(SignalConstants::ACTION_SUCCESS, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame($acceptKey, $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $signal->data->data);
        $this->assertSame($requestId, $signal->data->data->requestId);
        $this->assertNotNull($signal->data->data->reply);

        return $signal->data->data->reply;
    }

    /**
     * Reads back the one appended-lines frame queued for a viewer.
     *
     * @param string $acceptKey Connection the frame must be addressed to
     * @return LogsLinesAppendedSignalData Frame the owner sent
     */
    private function frame(string $acceptKey): LogsLinesAppendedSignalData
    {
        $signal = Hilos::$sr?->getNextQueuedSignal();

        $this->assertNotNull($signal, 'Something happened to the file and nothing was sent');
        $this->assertSame(HilosSignalConstants::LOGS_LINES_APPENDED, $signal->signalName->getName());
        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame($acceptKey, $signal->data->targetAcceptKey);
        $this->assertInstanceOf(LogsLinesAppendedSignalData::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * Puts a connection row on the node's roster, the way a live handshake does.
     *
     * @param string $acceptKey Accept key of the connection
     */
    private function connect(string $acceptKey): void
    {
        Hilos::$rt?->connectionsSource()?->add(FollowConnection::create($acceptKey, null));
    }

    /**
     * Strikes a connection row off the roster, the way a closed socket is struck.
     *
     * @param string $acceptKey Accept key of the connection
     */
    private function disconnect(string $acceptKey): void
    {
        Hilos::$rt?->connectionsSource()?->remove($acceptKey);
    }

    /**
     * @return string Absolute path of the followed fixture file
     */
    private function path(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . self::STREAM;
    }

    /**
     * Writes the followed file from scratch.
     *
     * @param string $contents File contents
     */
    private function write(string $contents): void
    {
        file_put_contents($this->path(), $contents);
    }

    /**
     * Appends to the followed file, the way a running process writes its log.
     *
     * @param string $contents Bytes to append
     */
    private function append(string $contents): void
    {
        file_put_contents($this->path(), $contents, FILE_APPEND);
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

/**
 * Presence-stage row with nothing of its own, as the two simple demos have.
 */
final class FollowConnection extends StateHilosConnection
{
    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (nothing of its own to read)
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> Always empty: the row is the framework base
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Partial update (nothing of its own to apply)
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/**
 * @extends StateHilosConnections<FollowConnection>
 */
final class FollowConnections extends StateHilosConnections
{
    public const string STATE_CLASS = FollowConnection::class;
}

/**
 * Runtime context of a project that mounts connections, which is every project carrying a page.
 */
final class FollowRtContext extends RtContext
{
    public const string connections = 'followTestConnections';

    public function configure(): void
    {
        $this->_stateCollections[self::connections] = FollowConnections::init();
    }
}
