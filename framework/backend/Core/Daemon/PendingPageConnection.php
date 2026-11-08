<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

/** One local connection lifetime and indexed latest active attempt across its page cohorts. */
final class PendingPageConnection
{
    private const int HEAP_CHILDREN = 2;

    public bool $active = true;

    public readonly SignalHoldIndex $identities;

    /** @var array<string, PendingPageBucket> Only current active page cohorts */
    public array $pages = [];

    /** @var array<string, int> Current local-agent or remote-node interest generations */
    public array $sources = [];

    /** @var list<string> Max heap of active pages, ordered by their last pending admission */
    private array $heap = [];

    /** @var array<string, int> Exact heap positions, allowing removal without stale heap entries */
    private array $positions = [];

    /** @var array<string, int> Last pending admission of each active page */
    private array $latest = [];

    /** Starts one local connection lifetime without another signal queue. */
    public function __construct()
    {
        $this->identities = new SignalHoldIndex();
    }

    /** @return ?int Last active pending admission, absent when no page still waits */
    public function lastIdentity(): ?int
    {
        return $this->heap === [] ? null : $this->latest[$this->heap[0]];
    }

    /**
     * @param string $page Page whose active pending tail changed
     * @param ?int $identity Last admission, absent when the page no longer waits
     */
    public function setPageTail(string $page, ?int $identity): void
    {
        $position = $this->positions[$page] ?? null;
        if ($identity === null) {
            if ($position === null) {
                return;
            }
            $last = array_pop($this->heap);
            unset($this->positions[$page], $this->latest[$page]);
            if ($position < count($this->heap)) {
                $this->heap[$position] = $last;
                $this->positions[$last] = $position;
                $this->repair($position);
            }
            return;
        }
        if (($this->latest[$page] ?? null) === $identity) {
            return;
        }
        $this->latest[$page] = $identity;
        if ($position === null) {
            $position = count($this->heap);
            $this->heap[] = $page;
            $this->positions[$page] = $position;
        }
        $this->repair($position);
    }

    /** @param int $position Changed or replaced heap entry */
    private function repair(int $position): void
    {
        while ($position > 0) {
            $parent = intdiv($position - 1, self::HEAP_CHILDREN);
            if ($this->latest[$this->heap[$parent]] >= $this->latest[$this->heap[$position]]) {
                break;
            }
            $this->swap($parent, $position);
            $position = $parent;
        }
        while (($left = $position * self::HEAP_CHILDREN + 1) < count($this->heap)) {
            $right = $left + 1;
            $larger = $right < count($this->heap) && $this->latest[$this->heap[$right]] > $this->latest[$this->heap[$left]]
                ? $right : $left;
            if ($this->latest[$this->heap[$position]] >= $this->latest[$this->heap[$larger]]) {
                break;
            }
            $this->swap($position, $larger);
            $position = $larger;
        }
    }

    /**
     * @param int $left First live heap position
     * @param int $right Second live heap position
     */
    private function swap(int $left, int $right): void
    {
        [$this->heap[$left], $this->heap[$right]] = [$this->heap[$right], $this->heap[$left]];
        $this->positions[$this->heap[$left]] = $left;
        $this->positions[$this->heap[$right]] = $right;
    }
}
