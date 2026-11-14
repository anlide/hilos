<?php

declare(strict_types=1);

namespace Hilos\Runtime\Ordinary;

use Closure;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use WeakReference;

/**
 * @internal One mandatory cleanup a source still owes, named by the row's identity rather than by
 * its key alone: the fact that a row must go, or the terminal values a row must be left with - an
 * ended run, a lifted freeze ({@see RtOrdinaryAdmission::updateAsCleanup()}).
 *
 * A removal holds the collection and the row weakly: it keeps neither alive, and once either is
 * gone - or the key holds another row - there is nothing left to remove. A terminal transition
 * holds the row's own write door, and is owed nothing once the runtime no longer holds that very
 * row under its key.
 */
final readonly class RtOrdinaryCleanup
{
    /** @var ?WeakReference<RtStates> Collection the row stood in when the removal was owed, null for a transition */
    private ?WeakReference $holder;
    /** @var WeakReference<RtState> The very row to remove or to write */
    private WeakReference $row;

    /**
     * @param ?RtStates $states Collection the row stands in, null for a transition
     * @param string $collection Registered collection or item key
     * @param RtState $row Row to remove or to write
     * @param ?Closure(): void $transition The row's own write door making its terminal values, run on
     *        the service reserve; null for a removal
     */
    public function __construct(?RtStates $states, public string $collection, RtState $row, public ?Closure $transition = null)
    {
        $this->holder = $states === null ? null : WeakReference::create($states);
        $this->row = WeakReference::create($row);
    }

    /**
     * @return ?RtStates The collection, while the owed row still stands in it under its key
     */
    public function standingIn(): ?RtStates
    {
        $states = $this->holder?->get();
        $row = $this->row->get();
        if ($states === null || $row === null || $states->get($row->getId()) !== $row) {
            return null;
        }

        return $states;
    }

    /**
     * @return bool Whether the runtime still holds the owed row under its key: in its collection,
     *         or as the item of that key
     */
    public function standsInRuntime(): bool
    {
        $row = $this->row->get();
        $states = Hilos::$rt?->getStateCollection($this->collection);
        $held = $states === null ? Hilos::$rt?->getStateItem($this->collection) : $states->get($row?->getId());

        return $row !== null && $held === $row;
    }

    /** @return ?RtState The owed row, while it lives */
    public function row(): ?RtState
    {
        return $this->row->get();
    }
}
