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
use Hilos\Log\DTO\LogsTakeoutUndoSignalData;
use Hilos\Log\DTO\NodeLogIndexSignalData;
use Hilos\Log\LogBatchTakeoutMarker;
use Hilos\Log\LogStoreAgent;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * The owner's half of withdrawing a takeout confirmation (HIL-759).
 *
 * The mirror of {@see LogStoreAgentTakeoutConfirmTest}, and the fixture is its fixture. What is
 * judged here is that every ending produces an ack: the sentence when the marker goes, the same
 * sentence when there was none to begin with, and a refusal a person can read when the batch
 * itself has been carried away.
 *
 * Two things are judged beyond the ack, and both are the leaf rather than decoration. The
 * withdrawal has to reach the DELTA, because two walks either side of it differ in nothing but a
 * marker file the walk does not weigh — left out of the delta the frame would be judged empty and
 * never sent, and the screen would go on showing the batch as taken. And the frame has to go out
 * of turn, because the person who clicked is looking at that row now, not in a minute.
 */
final class LogStoreAgentTakeoutUndoTest extends TestCase
{
    /** @var string Accept key of the connection waiting for the withdrawal */
    private const string ACCEPT_KEY = 'ak-logs-rotations-1';

    /** @var string Request id of the tracked dispatch being answered */
    private const string REQUEST_ID = 'req-1';

    /** @var int User id the original confirmation was attributed to */
    private const int USER_ID = 7;

    /** @var string Name of the rotated batch the archive fixture is written into */
    private const string BATCH_DIR = '2026-08-01-00-00-00';

    /** @var string Keep-count under which no batch is protected by being among the newest */
    private const string KEEP_NO_BATCHES = '0';

    /** @var string Max age past which a batch is recommended for takeout; the fixture is a month old */
    private const string EVICT_AFTER_A_SECOND = '1';

    /** @var string Keep-count that protects the fixture batch by being wider than the archive */
    private const string KEEP_MORE_THAN_THERE_ARE = '10';

    /** @var string Undo window this node is configured with, in seconds */
    private const string UNDO_WINDOW = '3600';

    /** @var string Sentence the owner writes on every successful withdrawal */
    private const string SUCCESS_SENTENCE = 'Acknowledgement withdrawn — the batch is waiting to be carried off again.';

    private string $dir = '';

    private string $logFile = '';

    private ?EnvAccessor $previousEnv = null;

    /** @var ?LogStoreAgent Owner under test, started on first use so its walk has the fixture in it */
    private ?LogStoreAgent $agent = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-logstore-undo-' . uniqid('', true);
        if (!mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            $this->fail("Could not create fixture directory: {$this->dir}");
        }
        // Outside the fixture on purpose: the agent logs into the very directory it owns.
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-logstore-undo-journal');
        Logger::setLogFile($this->logFile);

        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        Hilos::$env = new EnvAccessor();
        putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->dir . '/daemon.log');
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name . '=' . self::KEEP_NO_BATCHES);
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS->name . '=' . self::EVICT_AFTER_A_SECOND);
        putenv(EnvConstants::LOG_TAKEOUT_UNDO_WINDOW_SECONDS->name . '=' . self::UNDO_WINDOW);
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        putenv(EnvConstants::DAEMON_LOG_FILE->name);
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name);
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_MAX_AGE_SECONDS->name);
        putenv(EnvConstants::LOG_TAKEOUT_UNDO_WINDOW_SECONDS->name);
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

    public function testAWithdrawalTakesTheMarkerAwayAndTellsTheAskerSo(): void
    {
        $this->confirmedBatch();

        $this->undo();

        $this->assertNull(LogBatchTakeoutMarker::read($this->batchPath()));
        $this->assertSame(self::SUCCESS_SENTENCE, $this->ackedMessage());
    }

    /**
     * Two tabs, two administrators, one batch: the second withdrawal is not an error about a state
     * the person was right about. It answers the way the first was answered, and the disk is
     * already what both of them asked for.
     */
    public function testASecondWithdrawalIsTheSameSuccess(): void
    {
        $this->confirmedBatch();
        $this->undo();

        $this->undo();

        $this->assertSame(self::SUCCESS_SENTENCE, $this->ackedMessage());
    }

    /**
     * A batch that was never confirmed is the same case as one confirmed a moment ago and
     * withdrawn: what the asker wants is a batch without a marker, and it already is one.
     */
    public function testABatchThatWasNeverConfirmedIsTheSameSuccess(): void
    {
        $this->writeBatch('worker-0.log', "[2026-08-01 00:00:00.000] archived\n");

        $this->undo();

        $this->assertSame(self::SUCCESS_SENTENCE, $this->ackedMessage());
    }

    /**
     * The one refusal by substance: the pruner passed between the click and this frame, and there
     * is nothing left to put back on the list of batches waiting to be carried off.
     */
    public function testAVanishedBatchIsRefusedInTermsTheAskerCanRead(): void
    {
        $this->undo();

        $this->assertSame('The batch is no longer on this node.', $this->failed());
    }

    /**
     * The design decision this case exists for: the window judges the PRUNER, not the withdrawal.
     * A batch the retention rule has taken back under its protection is physically intact, so
     * there is nothing to refuse — and taking a confirmation away creates nothing to guard against.
     */
    public function testABatchBackUnderProtectionIsStillWithdrawable(): void
    {
        $this->confirmedBatch();
        putenv(EnvConstants::LOG_ARCHIVE_RETENTION_KEEP_BATCHES->name . '=' . self::KEEP_MORE_THAN_THERE_ARE);

        $this->undo();

        $this->assertNull(LogBatchTakeoutMarker::read($this->batchPath()));
        $this->assertSame(self::SUCCESS_SENTENCE, $this->ackedMessage());
    }

    /**
     * Without this line the whole leaf is invisible: a marker that went away moves nothing else
     * about the store, so a delta that did not name it would be judged empty and no frame would
     * leave the node at all.
     */
    public function testTheWithdrawalReachesTheDelta(): void
    {
        $this->confirmedBatch();

        $this->undo();

        $delta = $this->startedAgent()->lastDelta();
        $this->assertNotNull($delta);
        $this->assertSame([$this->batchTimestamp()], $delta->withdrawnBatchTimestamps);
        $this->assertFalse($delta->isEmpty(), 'A delta the sender judges empty is a frame nobody sends');
    }

    /**
     * The out-of-turn walk and frame, for the reason the confirmation has them: the operator is
     * looking at the row they just changed, and the ordinary schedule would leave it as it was.
     */
    public function testTheWithdrawalIsReportedToTheClusterWithoutWaitingForTheSchedule(): void
    {
        $this->confirmedBatch();

        $this->undo();

        $batches = $this->reportedIndex()->toIndex()->batches;
        $this->assertSame([$this->batchTimestamp()], array_column($batches, 'timestamp'));
        $this->assertNull($batches[0]->takenAt, 'The frame carries the fact that was just taken away');
    }

    /**
     * The window rides the index because it bottoms out in this node's own environment: a page
     * worker drawing the cluster picture knows its own and no other node's.
     */
    public function testTheReportedIndexCarriesThisNodesUndoWindow(): void
    {
        $this->confirmedBatch();

        $this->undo();

        $this->assertSame((int)self::UNDO_WINDOW, $this->reportedIndex()->toIndex()->takeoutUndoWindowSeconds);
    }

    public function testAnUntrackedWithdrawalRemovesNothingAndAnswersNobody(): void
    {
        $this->confirmedBatch();

        $this->undo(requestId: null);

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'There is no ack to correlate, so there is no ack');
        $this->assertNotNull(
            LogBatchTakeoutMarker::read($this->batchPath()),
            'A fact nobody is told about is not taken away either',
        );
    }

    /**
     * The owner, started over the fixture as it is at this moment.
     *
     * Started rather than merely constructed, because the withdrawal is judged against the index
     * the owner holds. The start frame is drained here, so a case reading the queue afterwards
     * reads what its own click sent.
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
     * Hands one withdrawal to the owner, as the rotations page forwards it.
     *
     * @param ?string $requestId Request id to answer on, or null for an untracked withdrawal
     */
    private function undo(?string $requestId = self::REQUEST_ID): void
    {
        $this->startedAgent()->onSignalAgent(
            new AgentSignalData(new LogsTakeoutUndoSignalData(
                nodeId: '',
                batchTimestamp: $this->batchTimestamp(),
                acceptKey: self::ACCEPT_KEY,
                action: HilosSignalConstants::LOGS_TAKEOUT_UNDO,
                requestId: $requestId,
            )),
            'agent',
            HilosSignalConstants::LOGS_AGENT_TAKEOUT_UNDO,
        );
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
        $this->assertSame(self::ACCEPT_KEY, $signal->data->targetAcceptKey);
        $this->assertInstanceOf(PageActionSuccessSignalData::class, $signal->data->data);
        $this->assertSame(HilosSignalConstants::LOGS_TAKEOUT_UNDO, $signal->data->data->action);
        $this->assertSame(self::REQUEST_ID, $signal->data->data->requestId);
        $this->assertNull($signal->data->data->reply, 'The withdrawal answers with a sentence and no data');

        return $signal->data->data->message;
    }

    /**
     * Reads back the refusal the owner sent, and the sentence it carries.
     *
     * @return string Reason as the person waiting for the withdrawal reads it
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
     * Reads back the index frame the withdrawal sent to the cluster aggregator.
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
     * Brings the fixture to the state these cases start from: a batch this owner has confirmed.
     *
     * Confirmed THROUGH the owner rather than by writing the marker beside it, because the index
     * the owner holds is part of the state: a marker that appeared behind its back would leave it
     * remembering a batch nobody had taken, and the withdrawal would then be judged against the
     * wrong picture. It is also the only order that happens in life — the confirmation goes
     * through this same agent.
     *
     * The queue is drained afterwards, so a case reading it reads what its own withdrawal sent.
     */
    private function confirmedBatch(): void
    {
        $this->writeBatch('worker-0.log', "[2026-08-01 00:00:00.000] archived\n");
        $this->startedAgent()->onSignalAgent(
            new AgentSignalData(new LogsTakeoutConfirmSignalData(
                nodeId: '',
                batchTimestamp: $this->batchTimestamp(),
                acceptKey: self::ACCEPT_KEY,
                action: HilosSignalConstants::LOGS_TAKEOUT_CONFIRM,
                requestId: self::REQUEST_ID,
                userId: self::USER_ID,
            )),
            'agent',
            HilosSignalConstants::LOGS_AGENT_TAKEOUT_CONFIRM,
        );
        $this->assertNotNull(
            LogBatchTakeoutMarker::read($this->batchPath()),
            'The fixture starts from a batch that IS confirmed',
        );
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
            // The confirmation acks and reports; the cases below are about the withdrawal.
        }
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
