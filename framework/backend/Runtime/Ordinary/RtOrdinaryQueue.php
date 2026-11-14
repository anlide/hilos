<?php

declare(strict_types=1);

namespace Hilos\Runtime\Ordinary;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Runtime\Exception\RtWriteBackpressureException;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\Snapshot\RtIndexCursor;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use LogicException;
use SplQueue;

/**
 * One process-local source FIFO, shared across all of that source's stores.
 * The source owner outlives queued work; advance() retires fixed cells individually.
 * No wire credit or snapshot generation controls this queue.
 * The framework write doors admit onto it ({@see RtOrdinaryAdmission}).
 * SCAFFOLD: the transport consumer that drains it is deliberately separate.
 */
final class RtOrdinaryQueue
{
    private const int DEFAULT_OPERATIONS = 128;
    private const int DEFAULT_RETAINED_BYTES = 1048576;
    private const int DEFAULT_CLEANUP_OPERATIONS = 8;
    private const int DEFAULT_CLEANUP_BYTES = 65536;

    /** @var SplQueue<RtOrdinaryReservation> Capacity-bounded cells, never native row graphs */
    private SplQueue $pending;
    /** @var SplQueue<RtOrdinaryReservation> Closed holders whose actual refs have not retired */
    private SplQueue $retiring;
    private ?RtOrdinaryReservation $preparing = null;
    private int $operations = 0;
    private int $bytes = 0;
    private int $cleanupOperations = 0;
    private int $cleanupBytes = 0;
    private ?RtOrdinaryReservation $large = null;

    /**
     * @param RtRowArena $arena Existing source storage, shared by its version stores
     * @param int $maxOperations Ordinary pending count, including uncommitted reservations
     * @param int $maxBytes Ordinary retained version allowance
     * @param int $maxCleanupOperations Separate finite mandatory cleanup allowance
     * @param int $maxCleanupBytes Separate finite cleanup version allowance
     * @throws InvalidArgumentException When a quota is negative
     */
    public function __construct(
        public readonly RtRowArena $arena,
        private readonly int $maxOperations = self::DEFAULT_OPERATIONS,
        private readonly int $maxBytes = self::DEFAULT_RETAINED_BYTES,
        private readonly int $maxCleanupOperations = self::DEFAULT_CLEANUP_OPERATIONS,
        private readonly int $maxCleanupBytes = self::DEFAULT_CLEANUP_BYTES,
    ) {
        if (min($maxOperations, $maxBytes, $maxCleanupOperations, $maxCleanupBytes) < 0) {
            throw new InvalidArgumentException('Ordinary Runtime quotas cannot be negative');
        }
        $this->pending = new SplQueue();
        $this->retiring = new SplQueue();
    }

    /**
     * @param int $operations Entire compound size
     * @return RtOrdinaryReservation Admission cell before any live write
     * @throws RtWriteBackpressureException When this source has no preparation or operation room
     */
    public function reserve(int $operations): RtOrdinaryReservation
    {
        return $this->reserveLane($operations, false);
    }

    /** @return bool Whether no cell is reserved, pending or still retiring on this FIFO */
    public function isIdle(): bool
    {
        return $this->preparing === null && $this->pending->isEmpty() && $this->retiring->isEmpty();
    }

    /**
     * @internal Dedicated mandatory-removal capacity; the door still checks rights. A removal it
     * cannot take is owed and retried by {@see RtOrdinaryAdmission::removeAsCleanup()}.
     *
     * @param int $operations Entire cleanup compound size
     * @return RtOrdinaryReservation Finite service admission
     * @throws RtWriteBackpressureException When the service reserve is occupied
     */
    public function reserveCleanup(int $operations): RtOrdinaryReservation
    {
        return $this->reserveLane($operations, true);
    }

    /**
     * @internal All pages and their actual size exist before this final reservation.
     *
     * @param RtOrdinaryReservation $slot Source's active preparation cell
     * @param RtRowValues $prefix Complete immutable ordered operation index
     * @throws RtWriteBackpressureException When held versions exceed the admitted lane
     * @throws LogicException When this cell does not own preparation or its lease closed
     */
    public function seal(RtOrdinaryReservation $slot, RtRowValues $prefix): void
    {
        if ($slot->queue !== $this || $this->preparing !== $slot || $slot->closed) {
            throw new LogicException('The ordinary preparation no longer owns its reservation');
        }
        $bytes = $prefix->rootIn($this->arena)->logicalBytes;
        if ($slot->oversized) {
            // The exclusive slot already holds this compound alone; its versions are counted apart.
        } elseif ($slot->cleanup) {
            if ($bytes <= $this->maxCleanupBytes - $this->cleanupBytes) {
                $this->cleanupBytes += $bytes;
            } elseif ($bytes > $this->maxCleanupBytes && $this->large === null) {
                // A removal larger than the whole service reserve would never fit it; it takes the
                // one exclusive large slot instead, as a large ordinary version does.
                $this->large = $slot;
                $slot->large = true;
            } else {
                throw new RtWriteBackpressureException('The Runtime cleanup version reserve is full');
            }
        } elseif ($bytes <= $this->maxBytes - $this->bytes) {
            $this->bytes += $bytes;
        } elseif ($bytes > $this->maxBytes && $this->large === null) {
            $this->large = $slot;
            $slot->large = true;
        } else {
            throw new RtWriteBackpressureException('The Runtime ordinary version reserve is full');
        }
        $slot->bytes = $bytes;
        $slot->prefix = $prefix->retain(RtRowRole::Ordinary);
        $this->preparing = null;
    }

    /**
     * No capacity check, allocation or user code runs at the commit boundary.
     *
     * @param RtOrdinaryReservation $slot Admitted prefix now made mandatory
     */
    public function commit(RtOrdinaryReservation $slot): void
    {
        $slot->committed = true;
    }

    /**
     * An unfinished transaction at the head holds later commits in FIFO order.
     * The consumer must reserve downstream space before taking responsibility.
     *
     * @return ?RtIndexCursor Independently retained ordinary prefix, or no committed head
     * @throws LogicException When a retained prefix unexpectedly closed
     */
    public function head(): ?RtIndexCursor
    {
        if ($this->pending->isEmpty()) {
            return null;
        }
        $slot = $this->pending->bottom();
        if ($slot->closed || !$slot->committed) {
            return null;
        }

        return new RtIndexCursor($this->arena, $slot->prefix->retain(RtRowRole::Ordinary), $slot->prefix->rootIn($this->arena));
    }

    /**
     * The consumer has accepted the entire head prefix; this is not a transport ACK.
     * Cursor leases may outlive this transfer, independently of the queue's reserve.
     *
     * @throws LogicException When no committed head is available
     */
    public function releaseHead(): void
    {
        if ($this->pending->isEmpty() || !$this->pending->bottom()->committed || $this->pending->bottom()->closed) {
            throw new LogicException('No committed ordinary prefix can be released');
        }
        $this->pending->bottom()->cancel();
    }

    /**
     * Cancellation closes publication immediately; counters follow actual reference release. A
     * synchronous door of the arena ({@see RtRowArena::openDoor()}): the cell's own prefix retires
     * here, so a rolled-back or abandoned reservation is handed back at the next advance.
     *
     * @param RtOrdinaryReservation $slot This source's preparation or pending transaction
     * @throws InvalidArgumentException Never: the door retires on fresh allowances
     */
    public function cancel(RtOrdinaryReservation $slot): void
    {
        $this->arena->openDoor();
        try {
            $slot->cancel();
        } finally {
            $this->arena->closeDoor();
        }
        if ($this->preparing === $slot) {
            $this->preparing = null;
        }
    }

    /**
     * @param RtWorkBudget $budget Ordinary lane's allowance, independent of snapshot work
     * @throws InvalidArgumentException When the supplied work allowance is invalid
     */
    public function advance(RtWorkBudget $budget): void
    {
        while (!$this->pending->isEmpty() && $this->pending->bottom()->closed) {
            if ($budget->take() === null) {
                return;
            }
            $this->retiring->enqueue($this->pending->dequeue());
        }
        $remaining = $this->retiring->count();
        while ($remaining-- > 0) {
            if ($budget->take() === null) {
                return;
            }
            $slot = $this->retiring->dequeue();
            if ($slot->isRetained()) {
                $this->retiring->enqueue($slot);
                continue;
            }
            if ($slot->cleanup) {
                $this->cleanupOperations -= $slot->operations;
            } elseif (!$slot->oversized) {
                $this->operations -= $slot->operations;
            }
            if ($slot->large) {
                if ($this->large === $slot) {
                    $this->large = null;
                }
            } elseif ($slot->cleanup) {
                $this->cleanupBytes -= $slot->bytes;
            } else {
                $this->bytes -= $slot->bytes;
            }
        }
    }

    /** @return int Pending ordinary operations, including uncommitted and retiring refs */
    public function pendingOperations(): int
    {
        return $this->operations;
    }

    /** @return int Retained ordinary bytes, with the exclusive large slot counted separately */
    public function retainedBytes(): int
    {
        return $this->bytes + ($this->large?->bytes ?? 0);
    }

    /**
     * @param int $operations Entire compound count
     * @param bool $cleanup Internal finite service lane
     * @return RtOrdinaryReservation Reserved FIFO cell
     * @throws RtWriteBackpressureException When preparation or pending capacity is unavailable
     */
    private function reserveLane(int $operations, bool $cleanup): RtOrdinaryReservation
    {
        $oversized = $this->admits($operations, $cleanup);
        $slot = new RtOrdinaryReservation($this, $operations, $cleanup);
        $this->pending->enqueue($slot);
        if ($cleanup) {
            $this->cleanupOperations += $operations;
        } elseif ($oversized) {
            $slot->oversized = true;
            $slot->large = true;
            $this->large = $slot;
        } else {
            $this->operations += $operations;
        }
        $this->preparing = $slot;

        return $slot;
    }

    /**
     * An ordinary compound longer than the whole operation quota would never fit even on a free
     * stream; it may take the one exclusive large slot instead, which then holds later ordinary
     * writes back exactly as a large version does.
     *
     * @param int $operations Entire compound count
     * @param bool $cleanup Internal finite service lane
     * @return bool Whether the compound is admitted into the exclusive slot rather than the quota
     * @throws RtWriteBackpressureException When preparation or pending capacity is unavailable
     */
    private function admits(int $operations, bool $cleanup): bool
    {
        $available = $cleanup ? $this->maxCleanupOperations - $this->cleanupOperations : $this->maxOperations - $this->operations;
        $oversized = !$cleanup && $operations > $this->maxOperations;
        if ($operations <= 0 || (!$oversized && $operations > $available) || $this->preparing !== null
            || (!$cleanup && $this->large !== null)) {
            throw new RtWriteBackpressureException('The Runtime source has no ordinary preparation capacity');
        }

        return $oversized;
    }
}
