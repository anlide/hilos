<?php

declare(strict_types=1);

namespace Hilos\Runtime;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Hilos;
use Hilos\Runtime\Snapshot\RtSnapshotExport;
use Hilos\Runtime\Snapshot\RtSnapshotScope;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use LogicException;
use WeakReference;

/** One source's snapshot, cut when the read is made and read out in portions by its hand-over. */
final readonly class RtSnapshotRead
{
    /** Rows of the cut within the scope, in insertion order, without owning their backing source */
    public RtSnapshotExport $export;

    public RtSnapshotScope $scope;

    /** @var ?WeakReference<RtStates|RtState> Original backing source, never returned to a caller */
    private ?WeakReference $source;

    private RtSnapshotBasis $basis;

    private ?int $sourceEpoch;

    /**
     * @param string $collectionKey Mounted source to cut now
     * @param ?RtSnapshotScope $scope Rows the snapshot covers, absent for the whole source
     * @param ?RtSnapshotBasis $basis Original runtime and mount the hand-over started on, absent for immediate capture
     * @throws InvalidFormatException When a standalone row's set field is malformed
     * @throws LogicException When the source's version pages have closed
     */
    public function __construct(public string $collectionKey, ?RtSnapshotScope $scope = null, ?RtSnapshotBasis $basis = null)
    {
        $this->scope = $scope ?? RtSnapshotScope::whole();
        $this->basis = $basis ?? new RtSnapshotBasis();
        $source = Hilos::$rt?->getStateCollection($collectionKey) ?? Hilos::$rt?->getStateItem($collectionKey);
        $this->source = $source === null ? null : WeakReference::create($source);
        $this->sourceEpoch = $source instanceof RtStates ? $source->snapshotEpoch() : null;
        $this->export = RtSnapshot::capture($collectionKey, $this->scope);
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
