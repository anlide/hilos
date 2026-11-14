<?php

declare(strict_types=1);

namespace Hilos\Runtime\Stream;

use Closure;
use Generator;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\Runtime\RtStaleness;
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
 * Receiving: only the expected part of the expected sequence moves the position. An old
 * generation (another stream id), a repeated or earlier part, a part from ahead and an answer
 * nobody waits for leave it where it is; a repeated final is never applied again. A part that
 * does not read is refused and closes its sequence. A final part is answered once the host has
 * applied its operation and queued its further delivery, through the settle callback. A host that
 * applies in portions hands the apply back as work: the channel advances it on the budget of its
 * pumps, the host answers at its end, and the next operation is read only after it.
 *
 * A refused operation holds its row: the row's later operations would land on a base that missed
 * it, so they are refused unread until the row has a base again - a snapshot of its collection
 * published on this end ({@see self::recover()}) or a new channel instance. Operations of other
 * rows go on. Meanwhile this end's copy of the row is frozen, and says so: the hold marks it stale
 * from the moment it began, and the recovery lifts the mark - in this process ({@see RtStaleness}),
 * or through the host where others read this end's copy: a master tells the workers that read it.
 *
 * Framework-first: the positions, credit and queues - the frames of another kind waiting for their
 * turn among them - are process-local state of this channel instance, as a socket's write buffer
 * is - not a Hilos::$rt collection. Nothing but the two ends of this one channel reads them, and
 * they end with it, as the write buffer of a dropped connection does; the stream id is minted per instance, so
 * a replaced channel is a new epoch. The id only has to not collide with the previous epoch of the
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

    /** @var SplQueue<RtSyncStreamOperation> Operations taken and not begun */
    private SplQueue $outbox;
    private int $revision = 0;
    private int $nextSequence = 1;
    private ?RtSyncStreamEncoder $encoder = null;
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
    private bool $reading = false;
    /** @var ?Generator<int, RtWork, int, mixed> A whole operation's apply the host handed back, until it ends */
    private ?Generator $delivering = null;
    /** @var ?Closure(bool): void Answer of the operation being applied, for an apply that raised */
    private ?Closure $deliveringAnswer = null;

    private bool $pumping = false;
    private bool $pumpAgain = false;
    /** Whether the channel is writing a frame of its own */
    private bool $emitting = false;

    /**
     * @param Closure(RtSyncStreamFrame): void $write Writes one frame to the channel
     * @param Closure(RtSyncStreamOperation, Closure(bool): void, ?object): ?Generator $deliver Applies a received
     *        operation and queues its further delivery, then settles it: true once applied, false when refused;
     *        a host applying in portions returns that work, yielding RtWork, and settles at its end. The third
     *        argument is the host's receipt of the operation's final part, null without one
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
     * Writes at once every frame of another kind still waiting for its turn, in the order the host
     * wrote them: the host's link carries no more of this channel's parts - a draining peer link - so
     * nothing they wait for would ever go out ahead of them on it.
     */
    public function releaseWaiting(): void
    {
        while (!$this->behind->isEmpty()) {
            [, $write] = $this->behind->dequeue();
            $write();
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
     * @return bool Whether a received part still waits to be read or its operation to be applied: a
     *     host reading other frames from the same link holds them back meanwhile, so the operation the
     *     part closes is handed on ahead of what the other end sent after it
     */
    public function isReading(): bool
    {
        return !$this->incoming->isEmpty() || $this->delivering !== null;
    }

    /** @return bool Whether nothing is queued, in flight, being applied or awaiting an answer in either direction */
    public function isIdle(): bool
    {
        return $this->outbox->isEmpty() && $this->encoder === null && $this->awaiting === [] && $this->incoming->isEmpty()
            && $this->delivering === null;
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
                Logger::warning("An RT stream operation was answered {$status->value} before its end [sequence={$answer->sequence}]");
            }
            $this->wakeUp();

            return;
        }
        if ($answer->streamId === $this->streamId && ($this->awaiting[$answer->sequence] ?? null) === $answer->part
            && $status !== RtSyncStreamStatus::Next) {
            unset($this->awaiting[$answer->sequence]);
            if ($status !== RtSyncStreamStatus::Applied) {
                Logger::warning("An RT stream operation was not applied: {$status->value} [sequence={$answer->sequence}]");
            }
            $this->wakeUp();

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
            if ($this->delivering !== null) {
                if (!$this->advanceDelivery($budget)) {
                    return true;
                }
                continue;
            }
            $part = $this->incoming->bottom();
            try {
                if ($this->step === null) {
                    if ($part->part === 0) {
                        $this->decoder = new RtSyncStreamDecoder();
                    }
                    $this->step = $this->decoder->feed($part->data);
                    $this->reading = false;
                }
                if (!self::advance($this->step, $budget)) {
                    return true;
                }
                if ($part->final && !$this->reading) {
                    $this->step = RtSyncStreamOperation::read($this->decoder->finish());
                    $this->reading = true;
                    continue;
                }
                $operation = $this->step->getReturn();
            } catch (InvalidFormatException $malformed) {
                $this->incoming->dequeue();
                $this->step = null;
                $this->decoder = null;
                if (!$part->final && $part->sequence === $this->expectedSequence) {
                    ++$this->expectedSequence;
                    $this->expectedPart = 0;
                }
                unset($this->receipts[$part->sequence]);
                $this->refuse($part, RtSyncStreamStatus::Refused, $malformed->getMessage());
                continue;
            }
            $this->incoming->dequeue();
            $this->step = null;
            if (!$part->final) {
                $this->emit(RtSyncStreamFrame::ack($part->streamId, $part->sequence, $part->part, RtSyncStreamStatus::Next));
                continue;
            }
            $this->decoder = null;
            $this->settle($part, $operation);
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
                if ($this->sentPart < 0) {
                    Logger::warning('An RT stream operation cannot be written and is dropped before its first part: '
                        . $unwritable->getMessage());
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
        $this->emit(RtSyncStreamFrame::part($this->streamId, $this->sendingSequence, $part, $final, $bytes));
        if ($final) {
            $this->awaiting[$this->sendingSequence] = $part;
            $this->encoder = null;
        } else {
            $this->awaitingNext = true;
        }
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
        while (!$this->behind->isEmpty() && $this->behind->bottom()[0] < $unsent) {
            [, $write] = $this->behind->dequeue();
            $write();
        }
    }

    /**
     * @param Generator<int, RtWork, int, mixed> $operation Suspended reading
     * @param RtWorkBudget $budget Allowance of the current RT pass
     * @return bool Whether the reading completed within the allowance
     * @throws InvalidFormatException When the bytes or the object do not read
     * @throws LogicException When a page preparation breaks a writer's lifetime or a step is no work request
     * @throws InvalidArgumentException Never: a work request is never negative
     */
    private static function advance(Generator $operation, RtWorkBudget $budget): bool
    {
        while ($operation->valid()) {
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
     * answered refused here, so its window slot comes back and the stream does not stall.
     *
     * @param RtWorkBudget $budget Allowance of the current RT pass
     * @return bool Whether the apply ended within the allowance
     * @throws InvalidFormatException Never: the answers this end writes are within their domain
     */
    private function advanceDelivery(RtWorkBudget $budget): bool
    {
        try {
            if (!self::advance($this->delivering, $budget)) {
                return false;
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
