<?php

declare(strict_types=1);

namespace Hilos\Log;

/**
 * Immutable difference between two consecutive {@see NodeLogIndex} states (HIL-753).
 *
 * What changed since the previous walk — a key appeared, grew, vanished, a batch arrived or was
 * cleaned away — derived by comparing the two indexes rather than journaled as it happens. No
 * history is kept: the consumer this exists for (HIL-755, deltas to the cluster aggregator) asked
 * for the difference to the last state it was told about, not for a log of changes.
 */
final class NodeLogIndexDelta
{
    /**
     * @param list<string> $appearedKeys Keys present now and absent from the previous index
     * @param list<string> $vanishedKeys Keys present in the previous index and absent now
     * @param array<string, int> $grownKeys Key → bytes added since the previous index (growth only)
     * @param list<int> $appearedBatchTimestamps Rotation batches present now and absent before
     * @param list<int> $vanishedBatchTimestamps Rotation batches present before and absent now
     * @param list<int> $confirmedBatchTimestamps Rotation batches an operator confirmed carrying off since the previous index
     * @param list<int> $withdrawnBatchTimestamps Rotation batches whose confirmation an operator withdrew since the previous index
     * @param list<int> $verdictChangedBatchTimestamps Rotation batches the retention rule started or stopped recommending since the previous index
     * @param bool $availabilityChanged Whether the store crossed between readable and unreadable
     */
    public function __construct(
        public readonly array $appearedKeys,
        public readonly array $vanishedKeys,
        public readonly array $grownKeys,
        public readonly array $appearedBatchTimestamps,
        public readonly array $vanishedBatchTimestamps,
        public readonly array $confirmedBatchTimestamps,
        public readonly array $withdrawnBatchTimestamps,
        public readonly array $verdictChangedBatchTimestamps,
        public readonly bool $availabilityChanged,
    ) {
    }

    /**
     * Whether the walk found nothing to say.
     *
     * The question the sender asks before spending a frame on the aggregator (HIL-755): a store
     * that has not moved since the last walk is worth no report, and on a quiet node most walks
     * find exactly that. Availability counts as a change on its own - a directory that has become
     * unreadable is news even though no key moved.
     *
     * So does a confirmation, and it is the reason this question is asked about it at all: two
     * walks either side of a takeout differ in NOTHING but a marker file the walk does not weigh
     * (HIL-483). Left out, the frame carrying the operator's own click would never be sent, and
     * the screen they clicked on would go on showing the batch as due.
     *
     * And so does its withdrawal, on an axis of its own rather than folded into the one above
     * (HIL-759). The confirmed list answers "which batches gained a marker", a question with one
     * direction; a marker that went away is the opposite news, and counting it in the same list
     * would report a batch as confirmed at the very moment it stopped being.
     *
     * And so does the retention verdict, on an axis of its own again (HIL-871). It is the only
     * change here that no file has to make: a batch crosses the age threshold because the clock
     * moved, and a batch returns under protection because an administrator raised
     * `keep_batches` - in both cases two walks either side of it are identical in every weight,
     * name and marker. Left out, the frame that would have taken the new verdict to the screen
     * is judged empty, and the badge waits for whatever changes next.
     *
     * @return bool True when nothing appeared, grew, vanished, changed its confirmation, changed
     *     its retention verdict or changed side
     */
    public function isEmpty(): bool
    {
        return $this->appearedKeys === []
            && $this->vanishedKeys === []
            && $this->grownKeys === []
            && $this->appearedBatchTimestamps === []
            && $this->vanishedBatchTimestamps === []
            && $this->confirmedBatchTimestamps === []
            && $this->withdrawnBatchTimestamps === []
            && $this->verdictChangedBatchTimestamps === []
            && !$this->availabilityChanged;
    }
}
