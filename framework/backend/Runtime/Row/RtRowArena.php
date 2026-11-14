<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Closure;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use SplQueue;

/**
 * Process-local Runtime storage, not replicated application state. Its intrusive
 * directory has constant fan-out; its native queue has one bounded cell per release.
 * The process arena outlives leases, including their pending retirement.
 *
 * Retirement runs in two places. A synchronous write door retires what it released itself, kept
 * apart while it runs ({@see self::openDoor()}); everything else - a carrier dropped after its
 * delivery, a replaced generation - waits in the shared queue for the RT pass of the process
 * loop, which advances it by its retirement share ({@see self::bindPassWake()}) and, round by
 * round, retires a bounded portion of what a burst left beyond a bound ({@see self::retireExcess()}).
 * A process with no such loop - a command, a test - has nobody else to retire that queue and no
 * loop a long step could hold up, so there its outermost door retires the shared queue to its end
 * as well.
 */
final class RtRowArena
{
    /**
     * Releases the shared queue may hold and still be left to the RT pass's retirement share. A
     * burst made outside the pass - agents stopping and starting by the dozen - outruns that share,
     * and the excess goes to the rounds' excess retirement ({@see self::retireExcess()}). Counted,
     * because such a burst is many small values, and what it holds is mostly the releases and pages
     * themselves rather than their payload.
     */
    public const int SHARED_BACKLOG_RELEASES = 4096;

    /**
     * Logical bytes the shared queue may hold awaiting retirement - released roots not begun and the
     * graphs being retired - and still be left to the retirement share. A few large values stay far
     * under the count bound, while the share takes them 8 KiB a round; past this bound they are excess
     * as well. Measured on agents stopping and starting by the dozen, a queue at the count bound holds
     * about 2 MiB by this measure, so small values reach the count bound first.
     */
    public const int SHARED_BACKLOG_BYTES = 4194304;

    /**
     * Structural steps one call of {@see self::retireExcess()} spends at most. The excess grows with
     * the burst, and a round of the master or a worker must not: what one call leaves is retired by
     * the next rounds, which the remaining work keeps cued. A step is one release, edge or page of the queue. Measured
     * on that burst, a call takes up to 6 ms and the master peaks at 47 MB; a quarter of it let the
     * backlog take the master to 59 MB, and no limit at all kept 46 MB with calls of up to 31 ms.
     */
    public const int EXCESS_RETIREMENT_STEPS = 16384;

    /**
     * Logical payload bytes one call of {@see self::retireExcess()} spends at most, for the same
     * reason: a large value is retired over rounds, this much of it a round - the volume bound, so a
     * call that finds the queue beyond it can bring it back.
     */
    public const int EXCESS_RETIREMENT_BYTES = 4194304;

    private static ?self $process = null;

    private ?RtRowPage $head = null;
    private ?RtRowPage $tail = null;
    /** @var SplQueue<RtRowRelease> Individually removable reference records */
    private SplQueue $releases;
    /** @var SplQueue<RtRowCopyFrame|RtRowCompareFrame> Abandoned traversal stacks, detached one frame at a time */
    private SplQueue $copyStacks;
    private ?RtRowRelease $activeRelease = null;
    /**
     * @var list<array{SplQueue<RtRowRelease>, SplQueue<RtRowCopyFrame|RtRowCompareFrame>, ?RtRowRelease}> Queues of
     *      the enclosing doors, the shared queue first, each set aside while a door inside it runs
     */
    private array $doors = [];
    /** @var ?Closure(): void Wakes the RT pass that advances the shared queue, null without one */
    private ?Closure $passWake = null;
    private int $physicalBytes = 0;
    private int $pages = 0;
    private int $retiredBytes = 0;
    /** Logical bytes of the roots released to the queue in use whose retirement has not begun */
    private int $queuedBytes = 0;
    /** @var array<string, int> Bounded map of the seven roles */
    private array $heldBytes = [];
    /** @var array<string, int> Bounded map of the seven roles */
    private array $leases = [];

    /** Creates an arena owned for the entire Runtime work span. */
    public function __construct()
    {
        $this->releases = new SplQueue();
        $this->copyStacks = new SplQueue();
    }

    /**
     * Compatibility writers share one process owner, so their queued leases outlive rows. The RT
     * pass of the master and of a worker advances its shared queue by the retirement share.
     *
     * @return self Process-local storage owner
     */
    public static function process(): self
    {
        return self::$process ??= new self();
    }

    /**
     * @internal Caller reserves one structural step and payload bytes before allocation.
     *
     * @param RtRowKind $kind Shape of this bounded page
     * @param null|bool|int|float|string $scalar Bounded payload
     * @param int $payloadBytes Logical payload bytes already reserved
     * @return RtRowPage Independently retained page
     */
    public function allocate(RtRowKind $kind, null|bool|int|float|string $scalar = null, int $payloadBytes = 0): RtRowPage
    {
        $page = new RtRowPage($kind, $scalar, $payloadBytes);
        $page->older = $this->tail;
        if ($this->tail === null) {
            $this->head = $page;
        } else {
            $this->tail->newer = $page;
        }
        $this->tail = $page;
        ++$this->pages;
        $this->physicalBytes += RtRowPage::OVERHEAD_BYTES + $payloadBytes;

        return $page;
    }

    /**
     * @internal Acquires an external lease without walking the root.
     *
     * @param RtRowPage $page Immutable value root
     * @param RtRowRole $role Holder's role
     */
    public function hold(RtRowPage $page, RtRowRole $role): void
    {
        ++$page->references;
        $this->heldBytes[$role->name] = ($this->heldBytes[$role->name] ?? 0) + $page->logicalBytes;
        $this->leases[$role->name] = ($this->leases[$role->name] ?? 0) + 1;
    }

    /**
     * @internal Changes one existing root holder's role without allocating or walking.
     * Both roles are initialized by preparation before a committed pointer can change.
     *
     * @param RtRowPage $page Independently leased immutable root
     * @param RtRowRole $from Previous holder role
     * @param RtRowRole $to New holder role
     */
    public function reclassify(RtRowPage $page, RtRowRole $from, RtRowRole $to): void
    {
        $this->heldBytes[$from->name] -= $page->logicalBytes;
        --$this->leases[$from->name];
        $this->heldBytes[$to->name] = ($this->heldBytes[$to->name] ?? 0) + $page->logicalBytes;
        $this->leases[$to->name] = ($this->leases[$to->name] ?? 0) + 1;
    }

    /**
     * @internal Updates only the writer's unpublished root, held as Transient.
     *
     * @param RtRowPage $root Unpublished root
     * @param int $bytes Added bounded page/subvalue size
     */
    public function grow(RtRowPage $root, int $bytes): void
    {
        $root->logicalBytes += $bytes;
        $this->heldBytes[RtRowRole::Transient->name] += $bytes;
    }

    /**
     * @internal Enqueues one reference; accounting stays until retire removes it.
     *
     * @param RtRowPage $page Retained root or child
     * @param ?RtRowRole $role External lease's role, or null for an edge
     * @return RtRowRelease Receipt completed after its reference and descendant retirement work
     */
    public function release(RtRowPage $page, ?RtRowRole $role): RtRowRelease
    {
        $release = new RtRowRelease($page, $role);
        $this->wakePass();
        $this->releases->enqueue($release);
        if ($role !== null) {
            $this->queuedBytes += $page->logicalBytes;
        }

        return $release;
    }

    /**
     * @internal Retains all service holders until their individual retirement steps.
     *
     * @param RtRowCopyFrame $head Abandoned stack head
     */
    public function retireCopyStack(RtRowCopyFrame $head): void
    {
        $this->wakePass();
        $this->copyStacks->enqueue($head);
    }

    /**
     * @internal Abandoned equality walks share the existing frame retirement queue.
     *
     * @param RtRowCompareFrame $head Suspended comparison stack
     */
    public function retireComparisonStack(RtRowCompareFrame $head): void
    {
        $this->wakePass();
        $this->copyStacks->enqueue($head);
    }

    /**
     * @internal Opens a synchronous write door's own retirement: until {@see self::closeDoor()},
     * what is released and abandoned is queued apart from the queue the door found, so the door
     * retires what it released and none of the backlog it did not cause. Doors nest; each closes
     * in the reverse order of opening.
     */
    public function openDoor(): void
    {
        $this->doors[] = [$this->releases, $this->copyStacks, $this->activeRelease];
        $this->releases = new SplQueue();
        $this->copyStacks = new SplQueue();
        $this->activeRelease = null;
    }

    /**
     * @internal Retires to its end what the innermost open door released, then gives back the
     * queue that door found. The work is the door's own releases, never the backlog: that stays
     * for the RT pass, or for the door around this one. Only in a process with no RT pass does
     * the outermost door retire the shared queue too, since nothing else ever would.
     *
     * @throws InvalidArgumentException Never: a fresh allowance is valid
     */
    public function closeDoor(): void
    {
        $this->retireCurrentQueue();
        [$this->releases, $this->copyStacks, $this->activeRelease] = array_pop($this->doors);
        if ($this->doors === [] && $this->passWake === null) {
            $this->retireCurrentQueue();
        }
    }

    /**
     * Hands the shared queue to the RT pass of the process loop: a release queued while nothing
     * waited, outside any door, wakes that pass, which then advances it by its retirement share.
     *
     * @param Closure(): void $wake Schedules the process's RT pass; called outside any door, never inside one
     */
    public function bindPassWake(Closure $wake): void
    {
        $this->passWake = $wake;
    }

    /**
     * Forgets the RT pass wake, when it is still the one given.
     *
     * @param Closure(): void $wake Wake an RT pass bound with {@see self::bindPassWake()}
     */
    public function unbindPassWake(Closure $wake): void
    {
        if ($this->passWake === $wake) {
            $this->passWake = null;
        }
    }

    /**
     * Retires the shared queue, a portion at a time, while it holds more than
     * {@see self::SHARED_BACKLOG_RELEASES} releases or more than {@see self::SHARED_BACKLOG_BYTES}
     * awaiting retirement, and for no more than {@see self::EXCESS_RETIREMENT_STEPS} steps and
     * {@see self::EXCESS_RETIREMENT_BYTES} bytes a call. What lies within the bounds is left to the
     * retirement share; excess the call leaves is the next round's, and keeps the queue pending so
     * the next round is cued. Called by the RT pass outside any door; inside one it does nothing,
     * because the queue in use then is the door's own.
     *
     * @throws InvalidArgumentException Never: a portion is never negative
     */
    public function retireExcess(): void
    {
        if ($this->doors !== []) {
            return;
        }
        $steps = self::EXCESS_RETIREMENT_STEPS;
        $bytes = self::EXCESS_RETIREMENT_BYTES;
        while ($steps > 0 && $this->exceedsSharedBacklog()) {
            $portion = new RtWorkBudget(min($steps, RtWorkBudget::DEFAULT_STEPS), min($bytes, RtWorkBudget::DEFAULT_BYTES));
            $this->advanceRetirement($portion);
            if ($portion->usedSteps() === 0) {
                // The head waits for payload bytes this call has spent.
                return;
            }
            $steps -= $portion->usedSteps();
            $bytes -= $portion->usedBytes();
        }
    }

    /** @return bool Whether the shared queue still holds retirement work for the RT pass */
    public function hasPendingRetirement(): bool
    {
        [$releases, $copyStacks, $activeRelease] = $this->doors[0] ?? [$this->releases, $this->copyStacks, $this->activeRelease];

        return $activeRelease !== null || !$releases->isEmpty() || !$copyStacks->isEmpty();
    }

    /**
     * Each edge and each part of a byte leaf is charged before release. No native
     * graph is dropped: the directory retains children while parent edges are severed.
     *
     * @param RtWorkBudget $budget Shared retirement allowance for this RT pass
     * @throws InvalidArgumentException When an invalid byte allowance is requested
     */
    public function advanceRetirement(RtWorkBudget $budget): void
    {
        while ($this->activeRelease !== null || !$this->releases->isEmpty() || !$this->copyStacks->isEmpty()) {
            if (!$this->copyStacks->isEmpty()) {
                if ($budget->take() === null) {
                    return;
                }
                $frame = $this->copyStacks->dequeue();
                if ($frame->previous !== null) {
                    $this->copyStacks->enqueue($frame->previous);
                    $frame->previous = null;
                }
                unset($frame);
            }
            if ($this->activeRelease === null) {
                if ($this->releases->isEmpty()) {
                    continue;
                }
                if ($budget->take() === null) {
                    return;
                }
                $this->activeRelease = $this->releases->dequeue();
            }
            $release = $this->activeRelease;
            $page = $release->page;
            if (!$release->started) {
                if ($budget->take() === null) {
                    return;
                }
                $release->started = true;
                if ($release->role !== null) {
                    $this->heldBytes[$release->role->name] -= $page->logicalBytes;
                    --$this->leases[$release->role->name];
                    $this->queuedBytes -= $page->logicalBytes;
                }
                if (--$page->references > 0) {
                    if ($release->role === null) {
                        // This edge belongs to a retiring root. Another holder keeps the subtree.
                        $this->retiredBytes -= $page->logicalBytes;
                    }
                    $this->completeRelease();
                    continue;
                }
                if ($release->role !== null) {
                    // Keep the whole pending graph charged while its child edges await their turn.
                    $this->retiredBytes += $page->logicalBytes;
                }
            }
            if ($release->releasedBytes < $page->payloadBytes) {
                $bytes = $budget->take($page->payloadBytes - $release->releasedBytes);
                if ($bytes === null) {
                    return;
                }
                $release->releasedBytes += $bytes;
                continue;
            }
            foreach (['first', 'second', 'next'] as $edge) {
                if ($page->$edge !== null) {
                    if ($budget->take() === null) {
                        return;
                    }
                    $group = $release->group ?? $release;
                    ++$group->pendingReferences;
                    $this->releases->enqueue(new RtRowRelease($page->$edge, null, $group));
                    $page->$edge = null;
                }
            }
            if ($budget->take() === null) {
                return;
            }
            if ($page->older === null) {
                $this->head = $page->newer;
            } else {
                $page->older->newer = $page->newer;
            }
            if ($page->newer === null) {
                $this->tail = $page->older;
            } else {
                $page->newer->older = $page->older;
            }
            $page->older = null;
            $page->newer = null;
            $page->scalar = null;
            --$this->pages;
            $this->physicalBytes -= RtRowPage::OVERHEAD_BYTES + $page->payloadBytes;
            $this->retiredBytes -= RtRowPage::OVERHEAD_BYTES + $page->payloadBytes;
            $this->completeRelease();
        }
    }

    /** @return RtRowRetention Actual pages and outstanding role leases */
    public function retention(): RtRowRetention
    {
        return new RtRowRetention(
            $this->physicalBytes,
            $this->pages,
            $this->retiredBytes,
            $this->queuedBytes,
            $this->releases->count() + $this->copyStacks->count() + ($this->activeRelease === null ? 0 : 1),
            $this->heldBytes,
            $this->leases,
        );
    }

    /**
     * Retires the queue in use now to its end, one fresh allowance at a time.
     *
     * @throws InvalidArgumentException Never: a fresh allowance is valid
     */
    private function retireCurrentQueue(): void
    {
        while ($this->activeRelease !== null || !$this->releases->isEmpty() || !$this->copyStacks->isEmpty()) {
            $this->advanceRetirement(new RtWorkBudget());
        }
    }

    /** @return bool Whether the shared queue holds more than is left to the retirement share, by count or by volume */
    private function exceedsSharedBacklog(): bool
    {
        return $this->releases->count() > self::SHARED_BACKLOG_RELEASES
            || $this->queuedBytes + $this->retiredBytes > self::SHARED_BACKLOG_BYTES;
    }

    /** Wakes the RT pass when the shared queue, outside any door, is about to stop being empty. */
    private function wakePass(): void
    {
        if ($this->passWake !== null && $this->doors === []
            && $this->activeRelease === null && $this->releases->isEmpty() && $this->copyStacks->isEmpty()) {
            ($this->passWake)();
        }
    }

    /** Completes one fixed reference; a shared subtree remains charged to its other actual owners. */
    private function completeRelease(): void
    {
        $group = $this->activeRelease->group ?? $this->activeRelease;
        --$group->pendingReferences;
        $this->activeRelease = null;
    }
}
