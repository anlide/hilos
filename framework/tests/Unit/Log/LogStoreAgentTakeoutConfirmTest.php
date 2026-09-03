<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use DateTimeImmutable;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\LogRotationConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Page\DTO\PageActionErrorSignalData;
use Hilos\Core\Page\DTO\PageActionSuccessSignalData;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Log\DTO\LogsTakeoutConfirmSignalData;
use Hilos\Log\DTO\NodeLogIndexSignalData;
use Hilos\Log\LogBatchTakeoutMarker;
use Hilos\Log\LogStoreAgent;
use Hilos\Pages\Logs\DTO\LogsTakeoutConfirmReplyDTO;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The owner's half of the takeout confirmation (HIL-483).
 *
 * The agent is the LAST step of somebody else's action - the rotations page deferred its ack when
 * it handed the confirmation over - so what is judged here is that every ending produces one: a
 * stamp when the marker is written, the stamp already on disk when it was written before, and a
 * refusal a person can read when the batch is gone or has come back under protection.
 *
 * The frame that follows the write is judged too, and it is half the leaf: the operator who
 * clicked is looking at the row, and a confirmation that waited for the next scheduled walk would
 * leave them looking at it unchanged for a minute.
 *
 * The store underneath is a throwaway temp directory, the fixture shape borrowed from
 * {@see LogStoreAgentReadLinesTest}.
 */
final class LogStoreAgentTakeoutConfirmTest extends TestCase
{
    /** @var string Accept key of the connection waiting for the confirmation */
    private const string ACCEPT_KEY = 'ak-logs-rotations-1';

    /** @var string Request id of the tracked dispatch being answered */
    private const string REQUEST_ID = 'req-1';

    /** @var int User id the confirmation is attributed to */
    private const int USER_ID = 7;

    /** @var string Name of the rotated batch the archive fixture is written into */
    private const string BATCH_DIR = '2026-08-01-00-00-00';

    /** @var string Keep-count under which no batch is protected by being among the newest */
    private const string KEEP_NO_BATCHES = '0';

    /** @var string Max age past which a batch is recommended for takeout; the fixture is a month old */
    private const string EVICT_AFTER_A_SECOND = '1';

    /** @var string Keep-count that protects the fixture batch by being wider than the archive */
    private const string KEEP_MORE_THAN_THERE_ARE = '10';

    private string $dir = '';

    private string $logFile = '';

    private ?EnvAccessor $previousEnv = null;

    /** @var ?LogStoreAgent Owner under test, started on first use so its walk has the fixture in it */
    private ?LogStoreAgent $agent = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-logstore-takeout-' . uniqid('', true);
        if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            $this->fail("Could not create fixture directory: {$this->dir}");
        }
        // Outside the fixture on purpose: the agent logs into the very directory it owns.
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-logstore-takeout-journal');
        Logger::setLogFile($this->logFile);

        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->dir . '/daemon.log');
        // Nothing protected by count and anything older than a second overdue: the fixture batch is
        // from 2026, so every case here starts from "the policy recommends carrying this one off".
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name . '=' . self::KEEP_NO_BATCHES);
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS->name . '=' . self::EVICT_AFTER_A_SECOND);
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        putenv(EnvConstants::DAEMON_LOG_FILE->name);
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name);
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS->name);
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        Hilos::$sr = null;
        $this->agent = null;
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        $this->removeTree($this->dir);

        parent::tearDown();
    }

    public function testAConfirmedBatchGetsItsMarkerAndTheAskerGetsTheStamp(): void
    {
        $this->writeBatch('worker-0.log', "[2026-08-01 00:00:00.000] archived\n");
        $before = time();

        $this->confirm();

        $takenAt = $this->acked()[LogsTakeoutConfirmReplyDTO::takenAt];
        $this->assertGreaterThanOrEqual($before, $takenAt);
        $this->assertSame($takenAt, LogBatchTakeoutMarker::read($this->batchPath()));
    }

    /**
     * The row the operator is looking at does not repaint on the ack — it repaints when this
     * node's next index reaches the mirror — so the ack carries the sentence that says the click
     * landed. Silence here would leave a modal closing over an unchanged badge.
     */
    public function testTheSuccessCarriesTheSentenceTheBrowserToasts(): void
    {
        $this->writeBatch('worker-0.log', "[2026-08-01 00:00:00.000] archived\n");

        $this->confirm();

        $this->assertSame('The batch is recorded as carried off.', $this->ackedMessage());
    }

    /**
     * Two tabs, two administrators, one batch: the second confirmation is not an error about a
     * fact the person was right about. It answers with what is on disk, which is also what the
     * first of them was told.
     */
    public function testAsecondConfirmationAnswersWithTheStampAlreadyOnDisk(): void
    {
        $this->writeBatch('worker-0.log', "[2026-08-01 00:00:00.000] archived\n");
        $this->confirm();
        $first = $this->acked()[LogsTakeoutConfirmReplyDTO::takenAt];

        $this->confirm();

        $this->assertSame($first, $this->acked()[LogsTakeoutConfirmReplyDTO::takenAt]);
    }

    /**
     * The one case the whole out-of-turn walk exists for: the operator is looking at the row they
     * just changed, and the ordinary schedule would leave it as it was for up to a minute.
     */
    public function testTheConfirmationIsReportedToTheClusterWithoutWaitingForTheSchedule(): void
    {
        $this->writeBatch('worker-0.log', "[2026-08-01 00:00:00.000] archived\n");

        $this->confirm();

        $frame = $this->reportedIndex();
        $this->assertSame(
            [$this->batchTimestamp()],
            array_column($frame->toIndex()->batches, 'timestamp'),
        );
        $this->assertNotNull($frame->toIndex()->batches[0]->takenAt, 'The frame carries the fact that was just written');
    }

    /**
     * The index also names the directory it measured, because the screen that offers the copy
     * command runs on a machine that knows its own log root and nobody else's.
     */
    public function testTheReportedIndexNamesTheDirectoryItMeasured(): void
    {
        $this->writeBatch('worker-0.log', "[2026-08-01 00:00:00.000] archived\n");

        $this->confirm();

        $this->assertSame($this->dir, $this->reportedIndex()->logDirectory);
    }

    /**
     * A batch a cleanup carried away between the click and the frame gets a refusal a person can
     * read, not a marker in a directory that is not there.
     */
    public function testAVanishedBatchIsRefusedInTermsTheAskerCanRead(): void
    {
        $this->confirm();

        $this->assertSame('This batch is no longer on the node', $this->failed());
    }

    /**
     * The race the design names: the modal was opened while the batch was recommended, and by the
     * time the button was pressed an administrator had raised the retention period. Confirming
     * only what is recommended is a hard rule, so this is a refusal.
     */
    public function testABatchBackUnderProtectionIsRefused(): void
    {
        $this->writeBatch('worker-0.log', "[2026-08-01 00:00:00.000] archived\n");
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name . '=' . self::KEEP_MORE_THAN_THERE_ARE);

        $this->confirm();

        $this->assertSame('The batch is protected again', $this->failed());
        $this->assertNull(LogBatchTakeoutMarker::read($this->batchPath()), 'A refusal writes nothing');
    }

    /**
     * The same race read from the other side, now that the node publishes its verdict (HIL-871):
     * the guard re-judges with a fresh clock and a fresh resolver instead of reading the list the
     * last walk left in the index. One judge means one place in the CODE, not one value over
     * time - the index goes on carrying the verdict of the walk that measured it, and the click
     * is still refused.
     */
    public function testTheGuardRejudgesInsteadOfReadingTheVerdictInTheIndex(): void
    {
        $this->writeBatch('worker-0.log', "[2026-08-01 00:00:00.000] archived\n");
        $agent = $this->startedAgent();
        $this->assertSame([$this->batchTimestamp()], $agent->index()->dueBatchTimestamps);

        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name . '=' . self::KEEP_MORE_THAN_THERE_ARE);
        $this->confirm();

        $this->assertSame('The batch is protected again', $this->failed());
        $this->assertSame(
            [$this->batchTimestamp()],
            $agent->index()->dueBatchTimestamps,
            'The refusal judged afresh; the index still carries what the last walk measured',
        );
    }

    /**
     * The guard is about what has NOT been confirmed yet: a batch that is already taken stays
     * taken when the rule moves back over it, and says so idempotently.
     */
    public function testAConfirmedBatchStaysConfirmedWhenTheRuleMovesBackOverIt(): void
    {
        $this->writeBatch('worker-0.log', "[2026-08-01 00:00:00.000] archived\n");
        $this->confirm();
        $first = $this->acked()[LogsTakeoutConfirmReplyDTO::takenAt];
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name . '=' . self::KEEP_MORE_THAN_THERE_ARE);

        $this->confirm();

        $this->assertSame($first, $this->acked()[LogsTakeoutConfirmReplyDTO::takenAt]);
    }

    public function testAnUntrackedConfirmationWritesNothingAndAnswersNobody(): void
    {
        $this->writeBatch('worker-0.log', "[2026-08-01 00:00:00.000] archived\n");

        $this->confirm(requestId: null);

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'There is no ack to correlate, so there is no ack');
        $this->assertNull(LogBatchTakeoutMarker::read($this->batchPath()), 'A fact nobody is told about is not written');
    }

    /**
     * The owner, started over the fixture as it is at this moment.
     *
     * Started rather than merely constructed, because the confirmation is judged against the index
     * the owner holds: a batch it has never walked is a batch it cannot be asked about. The start
     * frame is drained here, so a case reading the queue afterwards reads what its own click sent.
     *
     * @return LogStoreAgent Owner whose first walk has already happened
     */
    private function startedAgent(): LogStoreAgent
    {
        if ($this->agent === null) {
            $this->agent = new LogStoreAgent();
            $this->agent->onStart();
            while (Hilos::$sr?->getNextQueuedSignal() !== null) {
                // The start reports the index once; the cases below are about what a click sends.
            }
        }

        return $this->agent;
    }

    /**
     * Hands one confirmation to the owner, as the rotations page forwards it.
     *
     * @param ?string $requestId Request id to answer on, or null for an untracked confirmation
     */
    private function confirm(?string $requestId = self::REQUEST_ID): void
    {
        $this->startedAgent()->onSignalAgent(
            new AgentSignalData(new LogsTakeoutConfirmSignalData(
                nodeId: '',
                batchTimestamp: $this->batchTimestamp(),
                acceptKey: self::ACCEPT_KEY,
                action: HilosSignalConstants::LOGS_TAKEOUT_CONFIRM,
                requestId: $requestId,
                userId: self::USER_ID,
            )),
            'agent',
            HilosSignalConstants::LOGS_AGENT_TAKEOUT_CONFIRM,
        );
    }

    /**
     * Reads back the success ack the owner sent, past the index frame that precedes it.
     *
     * @return array<string, mixed> Reply the ack carries
     */
    private function acked(): array
    {
        $signal = $this->queuedSignalNamed(SignalConstants::ACTION_SUCCESS);

        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $signal->data->data);
        $this->assertSame(HilosSignalConstants::LOGS_TAKEOUT_CONFIRM, $signal->data->data->action);
        $this->assertSame(self::REQUEST_ID, $signal->data->data->requestId);
        $this->assertNotNull($signal->data->data->reply);

        return $signal->data->data->reply;
    }

    /**
     * Reads back the sentence the success ack carries, which is what the browser toasts.
     *
     * @return ?string Backend-authored success sentence, or null when the ack carried none
     */
    private function ackedMessage(): ?string
    {
        $signal = $this->queuedSignalNamed(SignalConstants::ACTION_SUCCESS);

        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $signal->data->data);

        return $signal->data->data->message;
    }

    /**
     * Reads back the refusal the owner sent, and the sentence it carries.
     *
     * @return string Reason as the person waiting for the confirmation reads it
     */
    private function failed(): string
    {
        $signal = $this->queuedSignalNamed(SignalConstants::ACTION_ERROR);

        $this->assertInstanceOf(WebSocketSignalData::class, $signal->data);
        $this->assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageActionErrorSignalData::class, $signal->data->data);
        $this->assertSame(self::REQUEST_ID, $signal->data->data->requestId);

        return $signal->data->data->reason;
    }

    /**
     * Reads back the index frame the confirmation sent to the cluster aggregator.
     *
     * @return NodeLogIndexSignalData Frame the owner reported out of turn
     */
    private function reportedIndex(): NodeLogIndexSignalData
    {
        $signal = $this->queuedSignalNamed(HilosSignalConstants::LOGS_NODE_INDEX_REPORT);

        $this->assertInstanceOf(AgentSignalData::class, $signal->data);
        $this->assertInstanceOf(NodeLogIndexSignalData::class, $signal->data->data);

        return $signal->data->data;
    }

    /**
     * Drains the queue up to and including the first signal of the given name.
     *
     * The confirmation sends two things and the case usually cares about one of them, so the
     * frames before it are walked past rather than asserted about: which of them comes first is
     * the agent's business, and a case pinned to that order would break on a rearrangement that
     * changed nothing anybody can see.
     *
     * @param string $name Signal name to stop at
     * @return object Queued signal carrying that name
     */
    private function queuedSignalNamed(string $name): object
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalName->getName() === $name) {
                return $signal;
            }
        }

        $this->fail("The owner is the last step of the action and owes a {$name}");
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
     * @return string Absolute path of the fixture's rotated batch
     */
    private function batchPath(): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . LogRotationConstants::LOG_ARCHIVE_SUBDIR_NAME
            . DIRECTORY_SEPARATOR . self::BATCH_DIR;
    }

    /**
     * Writes one file into the fixture's rotated batch.
     *
     * @param string $name Basename to write
     * @param string $contents File contents
     */
    private function writeBatch(string $name, string $contents): void
    {
        $batch = $this->batchPath();
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
