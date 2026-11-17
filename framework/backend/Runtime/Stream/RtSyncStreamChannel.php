<?php

declare(strict_types=1);

namespace Hilos\Runtime\Stream;

use Closure;
use Generator;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\Snapshot\RtSnapshotScope;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Utils\Helpers\RandomHelper;
use Hilos\Utils\Logger;
use LogicException;
use SplQueue;
use Throwable;

/**
 * Both directions of the ordinary RT stream on one channel instance - a worker's link to its
 * master, or one peer link - and the acknowledgement automaton of each.
 *
 * Sending: an operation is cut into parts of at most {@see RtSyncStreamFrame::MAX_PART_BYTES}
 * bytes, and a non-final part is followed by the next only after the receiver answered it
 * `next` - one part of credit, never more. A new operation starts once the previous one's final
 * part is out, while fewer than {@see self::WINDOW_OPERATIONS} operations wait for their final
 * answer. Writing to the socket is not an acknowledgement: an operation is done when the
 * receiver answers `applied`, and `refused` or `gap` is logged as the loss it is.
 *
 * The link carries other frames too, and the host says which of them keep their order against
 * the operations - the signals to agents and other frames the far end reads against its RT copy:
 * such a frame, written while an operation the host gave the channel before is not out to its
 * final part, waits here, and goes right behind that final part - ahead of any operation given
 * after it ({@see self::writeInTurn()}). Without that, a signal written after a write would reach
 * the other end between the write's parts, or ahead of its first one while the pass had no time
 * to cut it. The channel's own parts and answers are written at once.
 *
 * The host may wait for the answers ({@see self::afterAnswered()}). A wait counts the operations
 * taken before it, whatever their kind - they share one revision - and holds until each of them is
 * answered: `applied`, `refused` or `gap`, before its end or to its final part; one closed with an
 * empty final part counts once that part is answered, and one dropped before its first part counts
 * at once. Writing a part is no answer, and an operation taken after the wait holds nothing. A link
 * that closes or drains without a successor ends its waits ({@see self::endAnswerWaits()}). Peer
 * replacement moves unfinished ordinary writes and their wait boundaries to the successor instead;
 * fully sent old operations end their obligations without replay or proof of apply. The channel
 * keeps no timer of its own - the current link's liveness bounds a wait.
 *
 * Receiving: only the expected part of the expected sequence moves the position. An old
 * generation (another stream id), a repeated or earlier part, a part from ahead and an answer
 * nobody waits for leave it where it is; a repeated final is never applied again. A part that
 * does not read is refused and closes its sequence. A final part is answered once the host has
 * applied its operation and queued its further delivery, through the settle callback. A host that
 * applies in portions hands the apply back as work: the channel advances it on the budget of its
 * pumps, the host answers at its end, and the next operation is read only after it.
 *
 * A snapshot of a collection is an operation of the same stream ({@see self::sendSnapshot()}): cut
 * when the channel takes it, it is read on the other end behind every write taken before it and
 * ahead of every write taken after it. The receiving host is handed it at its head, before its
 * rows, and hands its staging back as work; each row reaches it once the part holding it is read,
 * and the next part is read only once the host has taken the rows before it, so neither end holds
 * the snapshot whole. The host answers once it has published or refused the snapshot; a refusal
 * before the end gives up the rest of it, and the next operation is read only after the answer.
 * A master streams them to its workers, and a node hands the areas it owns of a collection to a
 * linked node's master through them.
 *
 * A refused operation holds its row: the row's later operations would land on a base that missed
 * it, so they are refused unread until the row has a base again - a snapshot of its collection
 * published on this end ({@see self::recover()}) or a new channel instance. Operations of other
 * rows go on. Meanwhile this end's copy of the row is frozen, and says so: the hold marks it stale
 * from the moment it began, and the recovery lifts the mark - in this process ({@see RtStaleness}),
 * or through the host where others read this end's copy: a master tells the workers that read it.
 *
 * Framework-first: the positions, credit and queues - the frames of another kind waiting for their
 * turn and the waits for answers among them - are process-local state of this channel instance, as
 * a socket's write buffer is - not a Hilos::$rt collection. Only the local host reads this bookkeeping.
 * Peer replacement transfers logical work and its frame/answer boundaries; wire positions and credit
 * stay with the old channel. The stream id is minted per instance, so a replaced channel is a new
 * epoch. The id only has to not collide with the previous epoch of the
 * same link - nobody gains anything by guessing it - so it is drawn from the tolerant random axis.
 */
final class RtSyncStreamChannel
{
    /** @var int Operations whose final part is out and whose answer is still awaited */
    public const int WINDOW_OPERATIONS = 64;
    /** @var int Operations taken but not begun, beyond which the channel has no room for more */
    public const int OUTBOX_OPERATIONS = 128;

    /** Stream id of this instance's sending direction */
    public readonly string $streamId;

    /** @var SplQueue<RtSyncStreamOperation|RtSyncStreamSnapshot> Operations taken and not begun */
    private SplQueue $outbox;
    private int $revision = 0;
    private int $nextSequence = 1;
    private ?RtSyncStreamEncoder $encoder = null;
    /** Logical operation retained until its final part, so an ordinary write can restart in a new epoch */
    private RtSyncStreamOperation|RtSyncStreamSnapshot|null $sending = null;
    /** Revision of the operation the encoder cuts */
    private int $sendingRevision = 0;
    /**
     * @var SplQueue<array{int, Closure(): void}> Frames of another kind not written yet, each with the revision of the
     *      last operation taken before it, in the order the host wrote them
     */
    private SplQueue $behind;
    private int $sendingSequence = 0;
    private int $sentPart = -1;
    private bool $awaitingNext = false;
    /** @var array<int, int> Final part of each operation sent whole, by sequence, until it is answered */
    private array $awaiting = [];
    /** @var array<int, int> Revision of each operation sent whole, by sequence, until it is answered */
    private array $awaitingRevisions = [];
    /**
     * @var SplQueue<array{int, Closure(): void}> Waits for answers, each with the revision of the last operation taken
     *      before it, in the order the host asked
     */
    private SplQueue $answerWaits;

    private ?string $incomingStreamId = null;
    private int $expectedSequence = 1;
    private int $expectedPart = 0;
    private int $lastRevision = 0;
    /** @var array<string, array<string, true>> Rows of a refused operation, by collection and state id, until recovery */
    private array $heldRows = [];
    /** @var array<int, ?object> Host receipts of final parts accepted and not yet settled, by sequence */
    private array $receipts = [];
    /** @var SplQueue<RtSyncStreamFrame> Parts accepted and not yet read */
    private SplQueue $incoming;
    private ?RtSyncStreamDecoder $decoder = null;
    /** @var ?Generator<int, RtWork, int, mixed> Reading of the part at the head of the incoming queue */
    private ?Generator $step = null;
    /** @var ?Generator<int, RtWork, int, mixed> A whole operation's apply the host handed back, until it ends */
    private ?Generator $delivering = null;
    /** @var ?Closure(bool): void Answer of the operation being applied, for an apply that raised */
    private ?Closure $deliveringAnswer = null;
    /** Snapshot handed to the host at its head, until it is answered or given up */
    private ?RtSyncStreamSnapshot $receiving = null;
    /** The host's answer to that snapshot, null until it gave one */
    private ?bool $receivingVerdict = null;
    /** Final part of that snapshot once it was read, null while its parts are still read */
    private ?RtSyncStreamFrame $receivingFinal = null;

    private bool $pumping = false;
    private bool $pumpAgain = false;
    /**
     * Whether the host's link closed, so no part comes any more ({@see self::close()}). Framework-first: state of
     * this channel instance, as {@see $pumping} and {@see $emitting} are; only the channel reads it, and it ends
     * with the channel - the link that owns it.
     */
    private bool $closed = false;
    /** Whether the channel is writing a frame of its own */
    private bool $emitting = false;

    /**
     * @param Closure(RtSyncStreamFrame): void $write Writes one frame to the channel
     * @param Closure(RtSyncStreamOperation|RtSyncStreamSnapshot, Closure(bool): void, ?object): ?Generator $deliver Applies
     *        a received operation and queues its further delivery, then settles it: true once applied, false when
     *        refused; a host applying in portions returns that work, yielding RtWork, and settles at its end. The third
     *        argument is the host's receipt of the operation's final part, null without one. A snapshot comes at its
     *        head with no receipt yet ({@see RtSyncStreamSnapshot::receipt()}); its rows follow as they are read, so
     *        its host returns its staging as work, and one that returns none refuses a snapshot not read whole
     * @param ?Closure(self): void $wake Schedules {@see self::pump()} on the host's RT pass; null pumps at once
     * @param ?Closure(): void $readAll Told when a pump has read every part received and handed on their operations,
     *        for a host that holds its other input behind them ({@see self::isReading()})
     * @param ?Closure(): ?object $receipt Takes the host's receipt of a final part when it is accepted, before it
     *        waits to be read: what the host must know as of the operation's arrival rather than of its apply
     * @param ?Closure(string, list<string>, ?float): void $staleness Marks this end's copy of held rows of a
     *        collection frozen since the given moment, or current again with null; null marks them in this
     *        process alone ({@see RtStaleness})
     */
    public function __construct(
        private readonly Closure $write,
        private readonly Closure $deliver,
        private readonly ?Closure $wake = null,
        private readonly ?Closure $readAll = null,
        private readonly ?Closure $receipt = null,
        private readonly ?Closure $staleness = null,
    ) {
        $this->streamId = RandomHelper::hex(RtSyncStreamFrame::STREAM_ID_BYTES);
        $this->outbox = new SplQueue();
        $this->behind = new SplQueue();
        $this->answerWaits = new SplQueue();
        $this->incoming = new SplQueue();
    }

    /**
     * Takes one committed RT write for this channel's sending direction.
     *
     * @param RtSyncSignalDataInterface $data RT sync carrier
     * @param ?string $originNodeId Peer node the write happened on, or null on worker IPC
     * @param bool $partialOwner Whether the origin holds only part of the right over the collection
     * @throws InvalidFormatException When the carrier is no RT sync one, or its row holds a value a runtime row cannot carry
     * @throws LogicException When a segmented lease already closed
     * @throws InvalidArgumentException Never: a fresh allowance is valid
     */
    public function send(RtSyncSignalDataInterface $data, ?string $originNodeId = null, bool $partialOwner = false): void
    {
        $this->outbox->enqueue(RtSyncStreamOperation::fromSignalData($data, ++$this->revision, $originNodeId, $partialOwner));
        $this->wakeUp();
    }

    /**
     * Takes one snapshot of a collection for this channel's sending direction, cut here and now: every
     * write taken before it reaches the other end ahead of it, and every write taken after it is read
     * there only behind it. Its rows are read out of the cut as its parts are written.
     *
     * @param string $collectionKey Collection to cut
     * @param RtSnapshotScope $scope Rows the snapshot covers
     * @param ?string $originNodeId Peer node the rows come from, or null on worker IPC
     * @param bool $partialOwner Whether the origin holds only part of the right over the collection
     * @param array<string, float> $staleRows Moments this end's copy of rows froze, by id; only a master's stream to its
     *     worker carries any
     * @param ?int $announcement Number of the worker's interest report the snapshot answers; only a master's stream
     *     to its worker carries one
     * @throws InvalidFormatException When a standalone row's set field is malformed
     * @throws LogicException When the source's version pages have closed
     * @throws InvalidArgumentException Never: a fresh allowance is valid
     */
    public function sendSnapshot(
        string $collectionKey,
        RtSnapshotScope $scope,
        ?string $originNodeId = null,
        bool $partialOwner = false,
        array $staleRows = [],
        ?int $announcement = null,
    ): void {
        $this->outbox->enqueue(
            RtSyncStreamSnapshot::cut(++$this->revision, $collectionKey, $scope, $originNodeId, $partialOwner, $staleRows, $announcement),
        );
        $this->wakeUp();
    }

    /**
     * Writes a frame of another kind to this channel's link in its turn: at once when every
     * operation taken before it is out to its final part, otherwise right behind the final part of
     * the last of them, before any operation taken after it - the frame never waits for those. An
     * operation dropped or answered before its end is out as far as it goes. A host writes through
     * here every frame of its own the other end must read behind the writes sent before it - the
     * signals to agents, the access re-decisions of pages, a worker's word that an agent's work is
     * done, the response to a browser's handshake - so the other
     * end reads a write whole before them; its other frames it writes at once, and a tail of one
     * agent's writes never holds them back. A host may route this channel's own writer through here as well: a part or
     * an answer the channel is writing goes at once.
     *
     * @param Closure(): void $write Writes the frame
     */
    public function writeInTurn(Closure $write): void
    {
        if ($this->emitting || ($this->behind->isEmpty() && $this->encoder === null && $this->outbox->isEmpty())) {
            $write();

            return;
        }
        $this->behind->enqueue([$this->revision, $write]);
    }

    /**
     * @internal Moves unfinished ordinary writes and their exact frame/answer boundaries to an unused sending
     * direction. Fully sent operations are not replayed; their old answer obligations end. Snapshots are canceled,
     * including queued cuts: the host must renew their interest/debt and close the old transport so remote staging
     * cancels. Frame callbacks must already route through the successor; this door only preserves their boundaries.
     * Original revisions and their high-water mark survive, so later writes do not extend transferred waits.
     * A callback failure is rethrown after the other ready callbacks and the successor's wake.
     *
     * @param self $replacement Successor whose incoming direction may already be in use
     * @param Closure(RtSyncStreamSnapshot): void $cancelSnapshot Reports each canceled cut, without claiming delivery
     * @throws LogicException When the successor is this channel, closed, has used its sending direction, or a lease/work protocol broke
     * @throws InvalidFormatException Never: the successor writes valid parts and answers
     * @throws InvalidArgumentException Never: a fresh allowance is valid
     */
    public function moveUnsentTo(self $replacement, Closure $cancelSnapshot): void
    {
        if ($replacement === $this) {
            throw new LogicException('An RT stream cannot transfer its sending direction to itself');
        }
        if (!$this->isWriting() && $this->awaiting === [] && $this->behind->isEmpty() && $this->answerWaits->isEmpty()) {
            return;
        }
        if ($replacement->closed || $replacement->revision !== 0 || $replacement->nextSequence !== 1
            || $replacement->isWriting() || $replacement->awaiting !== []
            || !$replacement->behind->isEmpty() || !$replacement->answerWaits->isEmpty()) {
            throw new LogicException('An RT stream successor must have an unused sending direction');
        }

        $canceled = new SplQueue();
        $replacement->revision = $this->revision;
        if ($this->sending instanceof RtSyncStreamOperation) {
            $replacement->sending = $this->sending;
            $replacement->sendingRevision = $this->sendingRevision;
            $replacement->encoder = new RtSyncStreamEncoder($this->sending);
        } elseif ($this->sending instanceof RtSyncStreamSnapshot) {
            $canceled->enqueue($this->sending);
        }
        while (!$this->outbox->isEmpty()) {
            $operation = $this->outbox->dequeue();
            if ($operation instanceof RtSyncStreamSnapshot) {
                $canceled->enqueue($operation);
            } else {
                $replacement->outbox->enqueue($operation);
            }
        }
        $replacement->behind = $this->behind;
        $replacement->answerWaits = $this->answerWaits;
        $this->behind = new SplQueue();
        $this->answerWaits = new SplQueue();
        $this->encoder = null;
        $this->sending = null;
        $this->awaitingNext = false;
        $this->awaiting = [];
        $this->awaitingRevisions = [];

        // Commit every ownership change before any callback can pump or transfer again.
        $failure = null;
        while (!$canceled->isEmpty()) {
            try {
                $cancelSnapshot($canceled->dequeue());
            } catch (Throwable $raised) {
                $failure ??= $raised;
            }
        }
        try {
            $replacement->writeBehind();
        } catch (Throwable $raised) {
            $failure ??= $raised;
        }
        try {
            $replacement->endAnsweredAndWake();
        } catch (Throwable $raised) {
            $failure ??= $raised;
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Writes at once every frame of another kind still waiting for its turn, in the order the host
     * wrote them: the host's link carries no more of this channel's parts and has no successor to
     * carry its unfinished work. Peer replacement transfers the waiting frames through moveUnsentTo()
     * instead, preserving each frame's boundary on the new route.
     */
    public function releaseWaiting(): void
    {
        while (!$this->behind->isEmpty()) {
            [, $write] = $this->behind->dequeue();
            $write();
        }
    }

    /**
     * Takes the closure of the host's link: no part comes any more. A snapshot whose parts are still
     * being read is given up once the parts that did arrive are read and its staging waits for the
     * next one: the snapshot is lost, and the staging the host handed back runs on to its end and
     * cancels what it staged, instead of standing until the stream is let go of. A snapshot read
     * whole goes on to its answer, and so does an operation whose final part arrived. The host's RT
     * pass is woken for it; a host that pumps the stream at once, with no pass to wake, reads on at
     * its next pump.
     */
    public function close(): void
    {
        $this->closed = true;
        if ($this->readsSnapshot() && $this->wake !== null) {
            ($this->wake)($this);
        }
    }

    /**
     * Tells the host once every operation this channel took before the call is answered - `applied`,
     * `refused` or `gap` - or dropped before its first part, at once when none is left. Operations
     * are bounded by the revision captured when this wait is enqueued; later operations hold nothing,
     * including after transfer to a successor. Waits end in the order the host asked for them. $then may
     * raise: what it raises reaches whoever ended the wait - this call when nothing is left, the
     * answer read, or the end of every wait ({@see self::endAnswerWaits()}) - once the other waits ready
     * on that same answer or at that end are told all the same.
     *
     * @param Closure(): void $then Told the operations are answered
     */
    public function afterAnswered(Closure $then): void
    {
        $this->answerWaits->enqueue([$this->revision, $then]);
        $this->endAnswered();
    }

    /**
     * Ends every wait for answers still held here, in the order the host asked for them: the host's
     * link closed, drains without a successor, or has only fully sent old work left on replacement.
     * Unfinished ordinary writes and their waits move through moveUnsentTo() instead; ending the
     * donor's remaining waits cannot end a transferred wait. Unlike {@see self::releaseWaiting()}
     * it writes no frame. A wait that raises does not keep the others from being told, nor those
     * asked from inside: the first failure is raised once the queue is empty, to whoever ended the waits.
     */
    public function endAnswerWaits(): void
    {
        $failure = null;
        while (!$this->answerWaits->isEmpty()) {
            [, $then] = $this->answerWaits->dequeue();
            try {
                $then();
            } catch (Throwable $raised) {
                $failure ??= $raised;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Takes one frame the other end wrote: a part for this end to read, or an answer to a part
     * this end sent.
     *
     * @param RtSyncStreamFrame $frame Received frame
     * @throws InvalidFormatException Never: the answers this end writes are within their domain
     * @throws LogicException When a segmented lease closed or a work protocol broke
     * @throws InvalidArgumentException Never: a fresh allowance is valid
     */
    public function receive(RtSyncStreamFrame $frame): void
    {
        if ($frame->isPart()) {
            $this->accept($frame);
        } else {
            $this->answered($frame);
        }
    }

    /**
     * Advances reading and sending as far as the budget and the credit allow.
     *
     * @param RtWorkBudget $budget Allowance of the current RT pass
     * @return bool Whether work is left that only a next allowance can advance
     * @throws InvalidFormatException Never: the answers this end writes are within their domain
     * @throws LogicException When a segmented lease closed or a work protocol broke
     * @throws InvalidArgumentException Never: a work request is never negative
     */
    public function pump(RtWorkBudget $budget): bool
    {
        return $this->pumpIncoming($budget) || $this->pumpOutgoing($budget);
    }

    /**
     * Lifts the hold of a collection's rows: a snapshot of the collection published on this end
     * gave each of them a base again, so their later operations are applied and their copy here is
     * no longer frozen by the hold.
     *
     * @param string $collectionKey Collection whose snapshot was published
     */
    public function recover(string $collectionKey): void
    {
        $held = $this->heldRows($collectionKey);
        unset($this->heldRows[$collectionKey]);
        $this->markHeld($collectionKey, $held, null);
    }

    /**
     * @param string $collectionKey Collection to ask about
     * @return list<string> Rows of it this end holds after a refused operation, until a recovery
     */
    public function heldRows(string $collectionKey): array
    {
        return array_map(strval(...), array_keys($this->heldRows[$collectionKey] ?? []));
    }

    /** @return bool Whether this channel takes more operations without growing past its bound */
    public function hasRoom(): bool
    {
        return $this->outbox->count() < self::OUTBOX_OPERATIONS;
    }

    /**
     * @return bool Whether a received part still waits to be read, its operation to be applied, or a
     *     snapshot handed to the host its later parts: a host reading other frames from the same link
     *     holds them back meanwhile, so the operation the part closes is handed on ahead of what the
     *     other end sent after it
     */
    public function isReading(): bool
    {
        return !$this->incoming->isEmpty() || $this->delivering !== null || $this->readsSnapshot();
    }

    /**
     * @return bool Whether an operation this channel took is not out to its final part yet: queued, or being cut.
     *     One whose final part went out and that waits for its answer is not among them - it is on the wire
     */
    public function isWriting(): bool
    {
        return $this->encoder !== null || !$this->outbox->isEmpty();
    }

    /** @return bool Whether nothing is queued, in flight, being applied or awaiting an answer in either direction */
    public function isIdle(): bool
    {
        return $this->outbox->isEmpty() && $this->encoder === null && $this->awaiting === [] && $this->incoming->isEmpty()
            && $this->delivering === null && $this->receiving === null;
    }

    /**
     * @param RtSyncStreamFrame $part Received part
     * @throws InvalidFormatException Never: the answers this end writes are within their domain
     * @throws LogicException When a segmented lease closed or a work protocol broke
     * @throws InvalidArgumentException Never: a fresh allowance is valid
     */
    private function accept(RtSyncStreamFrame $part): void
    {
        if ($this->incomingStreamId === null) {
            if ($part->sequence !== 1 || $part->part !== 0) {
                $this->refuse($part, RtSyncStreamStatus::Gap, 'a stream must open at its first part');

                return;
            }
            $this->incomingStreamId = $part->streamId;
        }
        if ($part->streamId !== $this->incomingStreamId) {
            Logger::warning("Ignoring an RT stream part of another generation [stream={$part->streamId}]");

            return;
        }
        if ($part->sequence < $this->expectedSequence
            || ($part->sequence === $this->expectedSequence && $part->part < $this->expectedPart)) {
            Logger::warning("Ignoring a repeated RT stream part [sequence={$part->sequence}] [part={$part->part}]");

            return;
        }
        if ($part->sequence !== $this->expectedSequence || $part->part !== $this->expectedPart) {
            $this->refuse($part, RtSyncStreamStatus::Gap, "expected sequence {$this->expectedSequence} part {$this->expectedPart}");

            return;
        }
        if ($part->final) {
            ++$this->expectedSequence;
            $this->expectedPart = 0;
            if ($this->receipt !== null) {
                $this->receipts[$part->sequence] = ($this->receipt)();
            }
        } else {
            ++$this->expectedPart;
        }
        $this->incoming->enqueue($part);
        $this->wakeUp();
    }

    /**
     * @param RtSyncStreamFrame $answer Received answer
     * @throws InvalidFormatException Never: the answers this end writes are within their domain
     * @throws LogicException When a segmented lease closed or a work protocol broke
     * @throws InvalidArgumentException Never: a fresh allowance is valid
     */
    private function answered(RtSyncStreamFrame $answer): void
    {
        $status = $answer->status;
        if ($answer->streamId === $this->streamId && $this->encoder !== null && $this->awaitingNext
            && $answer->sequence === $this->sendingSequence && $answer->part === $this->sentPart
            && $status !== RtSyncStreamStatus::Applied) {
            $this->awaitingNext = false;
            if ($status !== RtSyncStreamStatus::Next) {
                $this->encoder = null;
                $this->sending = null;
                Logger::warning("An RT stream operation was answered {$status->value} before its end [sequence={$answer->sequence}]");
                $this->endAnsweredAndWake();
            } else {
                $this->wakeUp();
            }

            return;
        }
        if ($answer->streamId === $this->streamId && ($this->awaiting[$answer->sequence] ?? null) === $answer->part
            && $status !== RtSyncStreamStatus::Next) {
            unset($this->awaiting[$answer->sequence], $this->awaitingRevisions[$answer->sequence]);
            if ($status !== RtSyncStreamStatus::Applied) {
                Logger::warning("An RT stream operation was not applied: {$status->value} [sequence={$answer->sequence}]");
            }
            $this->endAnsweredAndWake();

            return;
        }
        Logger::warning("Ignoring an RT stream answer nobody awaits [sequence={$answer->sequence}] [part={$answer->part}]");
    }

    /**
     * @param RtWorkBudget $budget Allowance of the current RT pass
     * @return bool Whether the budget ran out with parts left to read
     * @throws InvalidFormatException Never: the answers this end writes are within their domain
     * @throws LogicException When a segmented lease closed or a work protocol broke
     * @throws InvalidArgumentException Never: a work request is never negative
     */
    private function pumpIncoming(RtWorkBudget $budget): bool
    {
        if (!$this->isReading()) {
            return false;
        }
        while ($this->isReading()) {
            if ($this->step === null) {
                if ($this->readsSnapshot() && $this->receivingVerdict === false && !$this->incoming->isEmpty()) {
                    $this->giveUp($this->incoming->dequeue(), 'its snapshot was refused before its end');
                    continue;
                }
                if ($this->delivering !== null && !$this->waitsForSnapshotPart()) {
                    if (!$this->advanceDelivery($budget)) {
                        return true;
                    }
                    continue;
                }
                if ($this->closed && $this->incoming->isEmpty() && $this->readsSnapshot()) {
                    // The rest of the snapshot will never come: its staging cancels what it staged.
                    $this->receiving->lose();
                    $this->endSnapshot();
                    continue;
                }
                if ($this->incoming->isEmpty() || ($this->readsSnapshot() && $this->receiving->hasRows())) {
                    return false;
                }
            }
            $part = $this->incoming->bottom();
            try {
                $this->step ??= $this->read($part);
                if (!self::advance($this->step, $budget)) {
                    return true;
                }
                $read = $this->step->getReturn();
            } catch (InvalidFormatException $malformed) {
                $this->giveUp($this->incoming->dequeue(), $malformed->getMessage());
                continue;
            }
            $this->incoming->dequeue();
            $this->step = null;
            if ($read instanceof RtSyncStreamSnapshot) {
                $this->deliverSnapshot($read);
            }
            if ($this->readsSnapshot()) {
                $this->answerSnapshotPart($part);
                continue;
            }
            if (!$part->final) {
                $this->emit(RtSyncStreamFrame::ack($part->streamId, $part->sequence, $part->part, RtSyncStreamStatus::Next));
                continue;
            }
            $this->decoder = null;
            $this->settle($part, $read);
        }
        if ($this->readAll !== null) {
            ($this->readAll)();
        }

        return false;
    }

    /**
     * Cuts and writes the parts of the operations taken, one sequence at a time. A sequence number
     * is issued with an operation's first part, so an operation that cannot be written before it
     * leaves no number behind; one that fails after parts went out closes its sequence with an
     * empty final part, which the receiver cannot read and refuses, and the stream goes on. Frames
     * of another kind whose operations are out go between two operations.
     *
     * @param RtWorkBudget $budget Allowance of the current RT pass
     * @return bool Whether the budget ran out before a part was whole
     * @throws LogicException When a segmented lease closed or a work protocol broke
     * @throws InvalidFormatException Never: the parts this end writes are within their domain
     * @throws InvalidArgumentException Never: a work request is never negative
     */
    private function pumpOutgoing(RtWorkBudget $budget): bool
    {
        while (true) {
            $this->writeBehind();
            if ($this->encoder === null) {
                if ($this->outbox->isEmpty() || count($this->awaiting) >= self::WINDOW_OPERATIONS) {
                    return false;
                }
                $operation = $this->outbox->dequeue();
                $this->sending = $operation;
                $this->sendingRevision = $operation->revision;
                $this->encoder = new RtSyncStreamEncoder($operation);
                $this->sentPart = -1;
            }
            if ($this->awaitingNext) {
                return false;
            }
            try {
                $bytes = $this->encoder->nextPart($budget);
            } catch (InvalidFormatException $unwritable) {
                $this->encoder = null;
                $this->sending = null;
                if ($this->sentPart < 0) {
                    Logger::warning('An RT stream operation cannot be written and is dropped before its first part: '
                        . $unwritable->getMessage());
                    $this->endAnswered();
                    continue;
                }
                Logger::warning("An RT stream operation cannot be written past part {$this->sentPart}; its sequence"
                    . " {$this->sendingSequence} closes with a final part the receiver refuses: " . $unwritable->getMessage());
                $this->writePart(true, '');
                continue;
            }
            if ($bytes === null) {
                return true;
            }
            if ($this->sentPart < 0) {
                $this->sendingSequence = $this->nextSequence++;
            }
            $this->writePart($this->encoder->isFinished(), $bytes);
        }
    }

    /**
     * Writes the next part of the sequence being sent: a final one waits for its answer in the
     * window, any other for the credit of its `next`.
     *
     * @param bool $final Whether the part closes its sequence
     * @param string $bytes Bytes of the part
     * @throws InvalidFormatException Never: the part names an issued sequence and bounded bytes
     */
    private function writePart(bool $final, string $bytes): void
    {
        $part = ++$this->sentPart;
        $frame = RtSyncStreamFrame::part($this->streamId, $this->sendingSequence, $part, $final, $bytes);
        if ($final) {
            $this->awaiting[$this->sendingSequence] = $part;
            $this->awaitingRevisions[$this->sendingSequence] = $this->sendingRevision;
            $this->encoder = null;
            $this->sending = null;
        } else {
            $this->awaitingNext = true;
        }
        $this->emit($frame);
    }

    /**
     * Writes one frame of this channel's own, a part or an answer: never in the turn the frames of
     * another kind keep, even when the host's writer comes back through {@see self::writeInTurn()}.
     *
     * @param RtSyncStreamFrame $frame Frame to write
     */
    private function emit(RtSyncStreamFrame $frame): void
    {
        $this->emitting = true;
        try {
            ($this->write)($frame);
        } finally {
            $this->emitting = false;
        }
    }

    /**
     * Writes the frames of another kind whose turn came: every operation taken before each of them
     * is out to its final part, or dropped. They go in the order the host wrote them.
     */
    private function writeBehind(): void
    {
        $unsent = match (true) {
            $this->encoder !== null => $this->sendingRevision,
            !$this->outbox->isEmpty() => $this->outbox->bottom()->revision,
            default => $this->revision + 1,
        };
        $failure = null;
        while (!$this->behind->isEmpty() && $this->behind->bottom()[0] < $unsent) {
            [, $write] = $this->behind->dequeue();
            try {
                $write();
            } catch (Throwable $raised) {
                $failure ??= $raised;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Ends the waits for answers whose operations are all answered, in the order the host asked for
     * them. Each leaves the queue before it is told, so a host that asks again from inside, or takes
     * or answers through this channel, never meets it a second time. A wait that raises does not
     * keep the ready ones behind it from being told on this same answer: the first failure is
     * raised once none is ready.
     */
    private function endAnswered(): void
    {
        $failure = null;
        while (!$this->answerWaits->isEmpty() && $this->answerWaits->bottom()[0] < $this->unansweredRevision()) {
            [, $then] = $this->answerWaits->dequeue();
            try {
                $then();
            } catch (Throwable $raised) {
                $failure ??= $raised;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * A ready wait may raise after an answer freed credit. Wake the remaining work all the same,
     * then return the first failure, so a full sending window cannot strand its queued tail.
     *
     * @throws LogicException When a segmented lease closed or a work protocol broke
     * @throws InvalidFormatException Never: the parts and answers this end writes are valid
     * @throws InvalidArgumentException Never: a fresh allowance is valid
     */
    private function endAnsweredAndWake(): void
    {
        $failure = null;
        try {
            $this->endAnswered();
        } catch (Throwable $raised) {
            $failure = $raised;
        }
        try {
            $this->wakeUp();
        } catch (Throwable $raised) {
            $failure ??= $raised;
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @return int Revision of the earliest operation taken and not answered, or the next one when none is left */
    private function unansweredRevision(): int
    {
        return match (true) {
            $this->awaitingRevisions !== [] => min($this->awaitingRevisions),
            $this->encoder !== null => $this->sendingRevision,
            !$this->outbox->isEmpty() => $this->outbox->bottom()->revision,
            default => $this->revision + 1,
        };
    }

    /**
     * @param Generator<int, RtWork, int, mixed> $operation Suspended reading
     * @param RtWorkBudget $budget Allowance of the current RT pass
     * @param ?Closure(): bool $pauses Tells, before each request is granted, that the work is to wait for input
     *     instead; null never
     * @return bool Whether the reading completed within the allowance, or paused
     * @throws InvalidFormatException When the bytes or the object do not read
     * @throws LogicException When a page preparation breaks a writer's lifetime or a step is no work request
     * @throws InvalidArgumentException Never: a work request is never negative
     */
    private static function advance(Generator $operation, RtWorkBudget $budget, ?Closure $pauses = null): bool
    {
        while ($operation->valid()) {
            if ($pauses !== null && $pauses()) {
                return true;
            }
            $request = $operation->current();
            if (!$request instanceof RtWork) {
                throw new LogicException('An RT stream reading yields RtWork requests only');
            }
            $granted = $budget->take($request->bytes);
            if ($granted === null) {
                return false;
            }
            $operation->send($granted);
        }

        return true;
    }

    /**
     * Hands a whole operation to the host and answers it once the host settles it. An apply the
     * host hands back as work is kept and advanced before anything else is read. An operation of a
     * held row is refused without reaching the host, and a refusal holds the row it names.
     *
     * @param RtSyncStreamFrame $final Final part of the operation
     * @param RtSyncStreamOperation $operation Operation read from its parts
     * @throws InvalidFormatException Never: the answers this end writes are within their domain
     */
    private function settle(RtSyncStreamFrame $final, RtSyncStreamOperation $operation): void
    {
        $receipt = $this->receipts[$final->sequence] ?? null;
        unset($this->receipts[$final->sequence]);
        if ($operation->revision <= $this->lastRevision) {
            $this->hold($operation);
            $this->refuse($final, RtSyncStreamStatus::Refused, 'its revision does not follow the last one');

            return;
        }
        $this->lastRevision = $operation->revision;
        if (isset($this->heldRows[$operation->collectionKey][$operation->stateId])) {
            $this->refuse($final, RtSyncStreamStatus::Refused, 'an earlier operation of its row was refused and the row waits for recovery');

            return;
        }
        $settled = false;
        $answer = function (bool $applied) use (&$settled, $final, $operation): void {
            if ($settled) {
                return;
            }
            $settled = true;
            if (!$applied) {
                $this->hold($operation);
            }
            $this->emit(RtSyncStreamFrame::ack(
                $final->streamId,
                $final->sequence,
                $final->part,
                $applied ? RtSyncStreamStatus::Applied : RtSyncStreamStatus::Refused,
            ));
        };
        $work = ($this->deliver)($operation, $answer, $receipt);
        if ($work instanceof Generator) {
            $this->delivering = $work;
            $this->deliveringAnswer = $answer;
        }
    }

    /**
     * Reads one part: an ordinary operation closes with its final part; a snapshot's head is read as
     * soon as its rows begin, and what follows its rows with its final part.
     *
     * @param RtSyncStreamFrame $part Part at the head of the incoming queue
     * @return Generator<int, RtWork, int, RtSyncStreamOperation|RtSyncStreamSnapshot|null> The operation the part
     *     closes, the snapshot whose head it read, or nothing yet
     * @throws InvalidFormatException When the bytes or the object do not read
     * @throws LogicException When a page preparation breaks a writer's lifetime
     */
    private function read(RtSyncStreamFrame $part): Generator
    {
        if ($part->part === 0) {
            $this->decoder = new RtSyncStreamDecoder();
        }
        $decoder = $this->decoder;
        yield from $decoder->feed($part->data);
        $snapshot = $this->readsSnapshot() ? $this->receiving : null;
        $head = null;
        if ($snapshot === null && $decoder->head() !== null) {
            $snapshot = $head = yield from RtSyncStreamSnapshot::read($decoder);
        }
        if ($part->final) {
            if ($snapshot === null) {
                return yield from RtSyncStreamOperation::read($decoder->finish());
            }
            yield from $snapshot->finishReading($this->receipts[$part->sequence] ?? null);
            unset($this->receipts[$part->sequence]);
        }

        return $head;
    }

    /**
     * Hands a snapshot to the host at its head, its rows to follow as they are read. The host's answer
     * settles it once its final part is read; a refusal given before gives up the rest of it. One whose
     * revision does not follow the last one is refused without reaching the host, which is not told:
     * no row is held for it, as it names none, so nothing here asks for another - the next event of
     * its collection does (a hold, a new interest).
     *
     * @param RtSyncStreamSnapshot $snapshot Snapshot whose head was read
     * @throws InvalidFormatException Never: the answers this end writes are within their domain
     */
    private function deliverSnapshot(RtSyncStreamSnapshot $snapshot): void
    {
        $this->receiving = $snapshot;
        $this->receivingVerdict = null;
        $this->receivingFinal = null;
        if ($snapshot->revision <= $this->lastRevision) {
            Logger::warning("Refusing an RT stream snapshot whose revision does not follow the last one [revision={$snapshot->revision}]");
            $this->receivingVerdict = false;

            return;
        }
        $this->lastRevision = $snapshot->revision;
        $settled = false;
        $answer = function (bool $applied) use (&$settled, $snapshot): void {
            if ($settled || $this->receiving !== $snapshot || $this->receivingVerdict === false) {
                return;
            }
            $settled = true;
            $this->receivingVerdict = $applied;
            $this->answerSnapshot();
        };
        $work = ($this->deliver)($snapshot, $answer, null);
        if ($work instanceof Generator) {
            $this->delivering = $work;
            $this->deliveringAnswer = $answer;
        } elseif (!$snapshot->isWhole() && $this->receiving === $snapshot && $this->receivingVerdict !== false) {
            Logger::warning('An RT stream snapshot is staged as its rows arrive; a host handing back no work for it refuses it');
            $this->receivingVerdict = false;
        }
    }

    /**
     * Answers a part of the snapshot handed to the host: a non-final one with the credit for the
     * next, the final one with the host's answer once there is one. A part read after the host
     * refused the snapshot carries that refusal, and the rest of the snapshot is given up.
     *
     * @param RtSyncStreamFrame $part Part just read
     * @throws InvalidFormatException Never: the answers this end writes are within their domain
     */
    private function answerSnapshotPart(RtSyncStreamFrame $part): void
    {
        if ($this->receivingVerdict === false) {
            $this->giveUp($part, 'its snapshot was refused before its end');

            return;
        }
        if (!$part->final) {
            $this->emit(RtSyncStreamFrame::ack($part->streamId, $part->sequence, $part->part, RtSyncStreamStatus::Next));

            return;
        }
        $this->decoder = null;
        $this->receivingFinal = $part;
        $this->answerSnapshot();
    }

    /**
     * Writes the host's answer to the snapshot once both the answer and the snapshot's final part are in.
     *
     * @throws InvalidFormatException Never: the answer names a received, valid position
     */
    private function answerSnapshot(): void
    {
        $final = $this->receivingFinal;
        $applied = $this->receivingVerdict;
        if ($final === null || $applied === null) {
            return;
        }
        $this->endSnapshot();
        $this->emit(RtSyncStreamFrame::ack(
            $final->streamId,
            $final->sequence,
            $final->part,
            $applied ? RtSyncStreamStatus::Applied : RtSyncStreamStatus::Refused,
        ));
    }

    /**
     * Refuses a part that does not read, or need not be read, and gives up the rest of its sequence; a
     * snapshot whose parts were still being read is lost with it.
     *
     * @param RtSyncStreamFrame $part Part taken off the incoming queue
     * @param string $reason Why, for the log
     * @throws InvalidFormatException Never: the answer names a received, valid position
     */
    private function giveUp(RtSyncStreamFrame $part, string $reason): void
    {
        $this->step = null;
        $this->decoder = null;
        if (!$part->final && $part->sequence === $this->expectedSequence) {
            ++$this->expectedSequence;
            $this->expectedPart = 0;
        }
        unset($this->receipts[$part->sequence]);
        if ($this->readsSnapshot()) {
            $this->receiving->lose();
            $this->endSnapshot();
        }
        $this->refuse($part, RtSyncStreamStatus::Refused, $reason);
    }

    /** Lets go of the snapshot handed to the host: answered, or given up. */
    private function endSnapshot(): void
    {
        $this->receiving = null;
        $this->receivingVerdict = null;
        $this->receivingFinal = null;
    }

    /** @return bool Whether a snapshot was handed to the host and its later parts are still read */
    private function readsSnapshot(): bool
    {
        return $this->receiving !== null && $this->receivingFinal === null;
    }

    /**
     * @return bool Whether the host's staging of a snapshot waits for its next part: it asked for rows and found
     *     every row read so far taken, and more are to come. It is not advanced meanwhile, so the wait spends
     *     no pass; the part's arrival wakes the pump
     */
    private function waitsForSnapshotPart(): bool
    {
        return $this->readsSnapshot() && $this->receivingVerdict !== false && $this->receiving->awaitsRows();
    }

    /**
     * Holds the row of a refused operation, and marks this end's copy of it frozen since now. A row
     * held already keeps the moment it first froze ({@see RtStaleness::mark()}).
     *
     * @param RtSyncStreamOperation $operation Operation refused
     */
    private function hold(RtSyncStreamOperation $operation): void
    {
        $this->heldRows[$operation->collectionKey][$operation->stateId] = true;
        $this->markHeld($operation->collectionKey, [$operation->stateId], microtime(true));
    }

    /**
     * Marks this end's copy of held rows, through the host when it has a say.
     *
     * @param string $collectionKey Collection of the rows
     * @param list<string> $stateIds Rows the hold froze, or let go of
     * @param ?float $since Microtime the hold began, or null when the rows have a base again
     */
    private function markHeld(string $collectionKey, array $stateIds, ?float $since): void
    {
        if ($this->staleness !== null) {
            ($this->staleness)($collectionKey, $stateIds, $since);
        } elseif ($since === null) {
            RtStaleness::clear($collectionKey, $stateIds);
        } else {
            RtStaleness::mark($collectionKey, $stateIds, $since);
        }
    }

    /**
     * Advances the apply the host handed back. An apply that raises instead of answering is
     * answered refused here, so its window slot comes back and the stream does not stall. A
     * snapshot's staging that waits for the next part is left where it stands until the part is
     * read ({@see self::waitsForSnapshotPart()}).
     *
     * @param RtWorkBudget $budget Allowance of the current RT pass
     * @return bool Whether the apply ended within the allowance, or waits for the next part of its snapshot
     * @throws InvalidFormatException Never: the answers this end writes are within their domain
     */
    private function advanceDelivery(RtWorkBudget $budget): bool
    {
        try {
            if (!self::advance($this->delivering, $budget, $this->waitsForSnapshotPart(...))) {
                return false;
            }
            if ($this->delivering->valid() && $this->waitsForSnapshotPart()) {
                return true;
            }
        } catch (Throwable $raised) {
            Logger::warning('An RT stream operation\'s apply raised instead of answering: ' . $raised->getMessage());
            ($this->deliveringAnswer)(false);
        }
        $this->delivering = null;
        $this->deliveringAnswer = null;

        return true;
    }

    /**
     * @param RtSyncStreamFrame $part Part refused
     * @param RtSyncStreamStatus $status Refused or gap
     * @param string $reason What was wrong with it, for the log
     * @throws InvalidFormatException Never: the answer names a received, valid position
     */
    private function refuse(RtSyncStreamFrame $part, RtSyncStreamStatus $status, string $reason): void
    {
        Logger::warning("Answering an RT stream part {$status->value} [sequence={$part->sequence}] [part={$part->part}]: {$reason}");
        $this->emit(RtSyncStreamFrame::ack($part->streamId, $part->sequence, $part->part, $status));
    }

    /**
     * Runs the pump on the host's pass, or at once until it is out of work; a wake from inside a
     * running pump asks for one more round instead of nesting.
     *
     * @throws LogicException When a segmented lease closed or a work protocol broke
     * @throws InvalidFormatException Never: the answers this end writes are within their domain
     * @throws InvalidArgumentException Never: a fresh allowance is valid
     */
    private function wakeUp(): void
    {
        if ($this->wake !== null) {
            ($this->wake)($this);

            return;
        }
        if ($this->pumping) {
            $this->pumpAgain = true;

            return;
        }
        $this->pumping = true;
        try {
            do {
                $this->pumpAgain = false;
                while ($this->pump(new RtWorkBudget())) {
                }
            } while ($this->pumpAgain);
        } finally {
            $this->pumping = false;
        }
    }
}
