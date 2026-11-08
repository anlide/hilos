<?php

declare(strict_types=1);

namespace Hilos\Runtime;

use Generator;
use Hilos\Hilos;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use WeakReference;

/** A source's finite row cohort, captured before a retained hand-over starts advancing. */
final readonly class RtSnapshotRead
{
    /** @var Generator<string, ?array<string, mixed>> Original row incarnations, without owning their backing source */
    public Generator $rows;

    /** @var ?WeakReference<RtStates|RtState> Original backing source, never returned to a caller */
    private ?WeakReference $source;

    private RtSnapshotBasis $basis;

    private ?int $sourceEpoch;

    /**
     * @param string $collectionKey Mounted source to capture now
     * @param ?list<string> $scopeKeys Named-row width, absent for other widths
     * @param ?list<string> $scopeSetKeys Set width, absent for other widths
     * @param ?RtSnapshotBasis $basis Original runtime and cross-source row cutoff, absent for immediate capture
     */
    public function __construct(
        public string $collectionKey,
        public ?array $scopeKeys = null,
        public ?array $scopeSetKeys = null,
        ?RtSnapshotBasis $basis = null,
    ) {
        $this->basis = $basis ?? new RtSnapshotBasis();
        $source = Hilos::$rt?->getStateCollection($collectionKey) ?? Hilos::$rt?->getStateItem($collectionKey);
        $this->source = $source === null ? null : WeakReference::create($source);
        $this->sourceEpoch = $source instanceof RtStates ? $source->snapshotEpoch() : null;
        $this->rows = RtSnapshot::rowCandidates($collectionKey, $this->basis->admission);
    }

    /**
     * An unavailable original source is not an empty replacement. Check again before publishing
     * so a remount or whole clear cannot turn an unfinished build into a partial snapshot.
     *
     * @return bool Whether the original runtime, mount and whole-source incarnation still answer
     */
    public function isCurrent(): bool
    {
        if (!$this->basis->isCurrent()) {
            return false;
        }
        $current = Hilos::$rt?->getStateCollection($this->collectionKey) ?? Hilos::$rt?->getStateItem($this->collectionKey);
        return $this->source?->get() === $current
            && (!$current instanceof RtStates || $current->snapshotEpoch() === $this->sourceEpoch);
    }
}
